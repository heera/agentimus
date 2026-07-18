<?php
/**
 * Assistant — the in-admin writing assistant behind the nav-bar quill: "write me a
 * post about X" without an external MCP client. The second door to operating the
 * site with AI; the first (MCP) serves external tools, this one serves the owner
 * standing in wp-admin.
 *
 * Deliberately thin, because the hard parts already exist and are REUSED, not
 * duplicated:
 *  - the BRAIN is {@see Assist::generate()} — the one choke point every Agentimus
 *    generation goes through, so the per-user rate limit and the site's Content
 *    Guidelines ride along for free;
 *  - the HANDS are {@see Abilities\ContentWriter::create()} — the same governed
 *    write path MCP agents use: capability-checked terms, meta, statuses.
 *
 * v1 safety shape (the confirm button IS the safety flow):
 *  - compose NEVER writes: it returns a structured draft for the owner to read;
 *  - create NEVER publishes: status is clamped to draft/pending regardless of the
 *    agent_writes_publish rung — going live stays a human editor action in v1;
 *  - no model-side tool calling: one prompt in, one JSON document out.
 *
 * Gating mirrors the launcher: both routes require the `enable_agent_writes`
 * switch (the owner's "AI may write here" consent) and real user capabilities —
 * compose needs edit_posts, create needs the post type's create_posts, exactly
 * as wp-admin's "Add New" would.
 *
 * @package Agentimus
 */

namespace Agentimus;

use Agentimus\Abilities\ContentWriter;

defined( 'ABSPATH' ) || exit;

final class Assistant {

	const NS = 'agentimus/v1';

	/** Prompt bounds: enough for a real brief, small enough to stay a brief. */
	const PROMPT_MIN = 8;
	const PROMPT_MAX = 2000;

	/** Output budget for a full compose/revision. The whole-document contract means
	 *  a REVISION re-emits the entire article, so this bounds the largest revisable
	 *  post: ~8k tokens ≈ 4–5k English words (less in denser scripts — Bengali runs
	 *  2–3× heavier). Kept generous also because reasoning models spend "thinking"
	 *  tokens from the same budget before emitting a character of answer. 8192 is
	 *  within every current provider's output ceiling. Filterable for sites on a
	 *  model that allows more. */
	const COMPOSE_TOKENS = 8192;

	/** Bounds on the dressing lists the model may propose. */
	const MAX_TOPICS     = 8;
	const MAX_TAGS       = 6;
	const MAX_CATEGORIES = 3;

	/** Image SLOTS per article: the text model proposes where a picture helps and
	 *  what it should show (free); each actual image is an explicit, per-slot act. */
	const MAX_IMAGE_SLOTS = 4;

	/** Output budget for the scene-describer (one vivid paragraph). */
	const SCENE_TOKENS = 400;

	/** Output budget for the outline skeleton — small on purpose (retrying an
	 *  outline should cost pennies next to a full draft), but with headroom for
	 *  reasoning models that spend thinking tokens from the same budget. */
	const OUTLINE_TOKENS = 2048;

	/** Ceiling on an outline's section list. */
	const MAX_OUTLINE_SECTIONS = 10;

	/** Blocks (bare names, core/ implied) the edit-existing flow can round-trip
	 *  through the model without loss — exactly the vocabulary the assistant
	 *  itself writes. Anything else refuses the post LOUDLY instead of quietly
	 *  destroying a table or an embed. */
	const EDIT_SAFE_BLOCKS = array( 'paragraph', 'heading', 'list', 'list-item', 'quote', 'image', 'html' );

	/** Word ceiling for a whole-document rewrite — the same COMPOSE_TOKENS
	 *  budget bounds the revised article on the way back. */
	const MAX_EDIT_WORDS = 4000;

	/** One image figure, as both the editor and figure_html() write it. */
	const FIGURE_RX = '#<figure class="wp-block-image[^"]*"[^>]*>.*?</figure>#is';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the REST routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Launcher state for the admin bootstrap: what the quill needs to know to be
	 * live, dimmed-with-guidance, and honest about which prerequisite is missing.
	 *
	 * @return array{writesOn:bool,providerReady:bool}
	 */
	public function state() {
		return array(
			'writesOn'      => (bool) $this->settings->enabled( 'enable_agent_writes' ),
			'providerReady' => Assist::ai_available(),
			'imageReady'    => self::image_ready(),
			'canUpload'     => current_user_can( 'upload_files' ),
		);
	}

	/**
	 * Whether the connected AI provider can generate images — drives the per-slot
	 * "Generate" buttons (feature-detected, never a dead-end button; the library
	 * picker works regardless).
	 *
	 * @return bool
	 */
	public static function image_ready() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		try {
			return (bool) wp_ai_client_prompt( 'capability probe' )->is_supported_for_image_generation();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Define the routes.
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/assistant/compose',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'compose' ),
				'permission_callback' => array( $this, 'can_compose' ),
			)
		);
		register_rest_route(
			self::NS,
			'/assistant/outline',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'outline' ),
				'permission_callback' => array( $this, 'can_compose' ),
			)
		);
		register_rest_route(
			self::NS,
			'/assistant/refine',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'refine' ),
				'permission_callback' => array( $this, 'can_compose' ),
			)
		);
		register_rest_route(
			self::NS,
			'/assistant/generate-image',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'generate_image' ),
				'permission_callback' => array( $this, 'can_generate_image' ),
			)
		);
		register_rest_route(
			self::NS,
			'/assistant/create',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'can_create' ),
			)
		);
		register_rest_route(
			self::NS,
			'/assistant/posts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'posts' ),
				'permission_callback' => array( $this, 'can_compose' ),
			)
		);
		register_rest_route(
			self::NS,
			'/assistant/post/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'fetch_post' ),
				'permission_callback' => array( $this, 'can_edit_target' ),
			)
		);
		register_rest_route(
			self::NS,
			'/assistant/update',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_edit_target' ),
			)
		);
	}

	/**
	 * Compose may run for anyone who could edit posts — the same bar as the editor's
	 * Draft-with-AI buttons — but only while the owner's write switch is on.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_compose() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return $this->writes_enabled();
	}

	/**
	 * Create additionally needs the REQUESTED type's create capability — checked here
	 * against the raw param so a refusal is a clean 403 before any work, and checked
	 * with the same cap wp-admin's "Add New" uses.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_create( \WP_REST_Request $request ) {
		$enabled = $this->writes_enabled();
		if ( true !== $enabled ) {
			return $enabled;
		}
		$type = sanitize_key( (string) ( $request->get_param( 'type' ) ? $request->get_param( 'type' ) : 'post' ) );
		$pto  = get_post_type_object( $type );
		if ( ! $pto ) {
			return false;
		}
		$cap = ! empty( $pto->cap->create_posts ) ? $pto->cap->create_posts : ( ! empty( $pto->cap->edit_posts ) ? $pto->cap->edit_posts : '' );
		return '' !== $cap && current_user_can( $cap );
	}

	/**
	 * Generating an image also imports it into the media library, so it needs the
	 * upload capability on top of the compose bar — the same rule wp-admin applies
	 * to the media uploader itself.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_generate_image() {
		$can = $this->can_compose();
		if ( true !== $can ) {
			return $can;
		}
		return current_user_can( 'upload_files' );
	}

	/**
	 * Editing an EXISTING post needs edit_post on that specific post — the same
	 * bar the editor itself applies — on top of the owner's write switch. The
	 * target must also be an agent-visible type.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_target( \WP_REST_Request $request ) {
		$enabled = $this->writes_enabled();
		if ( true !== $enabled ) {
			return $enabled;
		}
		$post = get_post( absint( $request->get_param( 'id' ) ) );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * The owner's write-consent gate, shared by both routes. A WP_Error (not false)
	 * when the switch is off, so the UI can say WHICH prerequisite is missing
	 * instead of a bare 403.
	 *
	 * @return true|\WP_Error
	 */
	private function writes_enabled() {
		if ( $this->settings->enabled( 'enable_agent_writes' ) ) {
			return true;
		}
		return new \WP_Error(
			'agentimus_writes_off',
			__( 'AI writing is switched off on this site. Turn on “Let connected agents write” in Settings → Discovery → MCP server.', 'agentimus' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * POST /assistant/compose — one structured generation from the owner's brief.
	 * Returns a draft DOCUMENT (title, body, excerpt, description, topics, tags,
	 * categories) for the preview card. Writes nothing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function compose( \WP_REST_Request $request ) {
		$prompt = self::read_brief( $request );
		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		// An approved outline (the outline-first gate) rides along as a contract.
		// It came from our own /outline response, but it's re-held to the schema
		// anyway — the same both-directions rule the draft round-trip follows.
		$outline = $request->get_param( 'outline' );
		if ( ! empty( $outline ) ) {
			$outline = self::sanitize_outline( $outline );
			if ( is_wp_error( $outline ) ) {
				return new \WP_Error(
					'agentimus_outline_invalid',
					__( 'The outline looks empty — give it a title and at least one section, or draft without it.', 'agentimus' ),
					array( 'status' => 400 )
				);
			}
			$prompt = self::outline_prompt( $prompt, $outline );
		}

		if ( ! Assist::ai_available() ) {
			return new \WP_Error(
				'agentimus_ai_unavailable',
				__( 'No AI text model is configured. Add or enable one under Settings → AI, then try again.', 'agentimus' ),
				array( 'status' => 503 )
			);
		}

		$text = ( new Assist( $this->settings ) )->generate( self::system_prompt(), $prompt, self::COMPOSE_TOKENS );
		if ( is_wp_error( $text ) ) {
			return self::friendly_ai_error( $text );
		}

		$draft = self::parse_draft( $text );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		return rest_ensure_response( array( 'draft' => $draft ) );
	}

	/**
	 * POST /assistant/outline — the cheap step before the expensive one: a
	 * skeleton (working title + sections) from the same brief, for the owner to
	 * shape BEFORE the full generation runs. Rerolling a skeleton costs pennies;
	 * rerolling a whole article doesn't — that's the entire point of the gate.
	 * Writes nothing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function outline( \WP_REST_Request $request ) {
		$prompt = self::read_brief( $request );
		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		if ( ! Assist::ai_available() ) {
			return new \WP_Error(
				'agentimus_ai_unavailable',
				__( 'No AI text model is configured. Add or enable one under Settings → AI, then try again.', 'agentimus' ),
				array( 'status' => 503 )
			);
		}

		$text = ( new Assist( $this->settings ) )->generate( self::outline_system_prompt(), $prompt, self::OUTLINE_TOKENS );
		if ( is_wp_error( $text ) ) {
			return self::friendly_ai_error( $text );
		}

		$outline = self::parse_outline( $text );
		if ( is_wp_error( $outline ) ) {
			return $outline;
		}
		return rest_ensure_response( array( 'outline' => $outline ) );
	}

	/**
	 * PURE: providers answer failures with walls of API jargon — status lines,
	 * metric names, retry timers, documentation URLs. Our own errors
	 * (agentimus_* codes) are already written for humans and pass through
	 * untouched; a recognisable quota/rate wall becomes ONE actionable
	 * sentence; anything else is clipped to a single clean line. The drawer
	 * shows errors inside its pinned foot — it gets a sentence, never a
	 * paragraph of provider spew.
	 *
	 * @param \WP_Error $error The failed generation's error.
	 * @return \WP_Error
	 */
	public static function friendly_ai_error( \WP_Error $error ) {
		$code = (string) $error->get_error_code();
		if ( 0 === strpos( $code, 'agentimus_' ) ) {
			return $error;
		}

		$raw = (string) $error->get_error_message();
		$lc  = strtolower( $raw );
		foreach ( array( 'quota', 'too many requests', 'rate limit', 'resource_exhausted', '429' ) as $needle ) {
			if ( false !== strpos( $lc, $needle ) ) {
				return new \WP_Error(
					'agentimus_ai_provider_limited',
					__( 'Your AI provider is out of requests for now — free tiers allow only a handful per day. Wait a little and try again, or check the provider’s plan.', 'agentimus' ),
					array( 'status' => 429 )
				);
			}
		}

		$msg = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
		if ( mb_strlen( $msg ) > 160 ) {
			$msg = mb_substr( $msg, 0, 159 ) . '…';
		}
		if ( '' === $msg ) {
			$msg = __( 'The AI call failed — please try again.', 'agentimus' );
		}
		return new \WP_Error( 'agentimus_ai_failed', $msg, array( 'status' => 502 ) );
	}

	/**
	 * The brief, validated the same way for outline and compose: bounded,
	 * sanitised, or a clear 400 saying what's missing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	private static function read_brief( \WP_REST_Request $request ) {
		$prompt = trim( sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ) );
		if ( strlen( $prompt ) < self::PROMPT_MIN ) {
			return new \WP_Error(
				'agentimus_prompt_short',
				__( 'Describe the post in a sentence or two first.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		if ( strlen( $prompt ) > self::PROMPT_MAX ) {
			$prompt = substr( $prompt, 0, self::PROMPT_MAX );
		}
		return $prompt;
	}

	/**
	 * The outline instruction: a skeleton only — title plus h2 sections with a
	 * one-line note each. Intro and conclusion are deliberately excluded here;
	 * {@see outline_prompt()} welcomes them back AROUND the sections at write
	 * time, so the two prompts describe the same article shape.
	 *
	 * @return string
	 */
	public static function outline_system_prompt() {
		return 'You are the writing assistant inside a WordPress site\'s admin, planning a post from the owner\'s brief. '
			. 'Respond with ONLY a single JSON object — no markdown fences, no commentary before or after — with exactly these keys: '
			. '"title" (string: a working title for the post), '
			. '"sections" (array of 3–8 objects, each {"heading": the exact h2 text the section will use, '
			. '"note": one plain sentence on what the section covers}). '
			. 'Plan concretely in the brief\'s language; order the sections so the post reads naturally. '
			. 'Do not include the introduction or a conclusion as sections — those are written around the outline later.';
	}

	/**
	 * PURE: the compose user-prompt when an approved outline gates the generation
	 * — the brief plus the outline as a CONTRACT: these sections, this order,
	 * these headings, verbatim. A kept contract has a free bonus: image-slot
	 * anchors match h2 text, so every proposed slot stays anchorable.
	 *
	 * @param string $brief   The owner's brief.
	 * @param array  $outline The sanitised outline.
	 * @return string
	 */
	public static function outline_prompt( $brief, array $outline ) {
		return $brief . "\n\nThe owner approved this outline, as JSON:\n"
			. wp_json_encode( $outline, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. "\n\nFollow it exactly: use the outline's title unless the brief itself demands another, and write one <h2> section per outline entry, in the given order, with the given heading text verbatim. "
			. 'Cover what each section\'s note describes. Do not add, remove, merge or reorder sections. '
			. 'An introduction before the first section and a short closing after the last are welcome, without headings of their own.';
	}

	/**
	 * PURE: turn the model's text into a sanitised outline, or a clear error —
	 * the same fence/prose-tolerant cut {@see parse_draft()} uses.
	 *
	 * @param string $text Raw model output.
	 * @return array|\WP_Error
	 */
	public static function parse_outline( $text ) {
		$text  = trim( (string) $text );
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		$data  = ( false !== $start && false !== $end && $end > $start )
			? json_decode( substr( $text, $start, $end - $start + 1 ), true )
			: null;

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'agentimus_ai_bad_output',
				__( 'The AI didn’t return a usable outline — please try again.', 'agentimus' ),
				array( 'status' => 502 )
			);
		}
		return self::sanitize_outline( $data );
	}

	/**
	 * PURE: hold an outline-shaped array to the schema — the shared gate for both
	 * directions, exactly like {@see sanitize_draft()}: model output on its way
	 * to the editor, and an owner-edited outline on its way back into the compose
	 * contract. A section needs a real heading; a heading-less row is dropped,
	 * and an outline needs a title and at least one surviving section to count.
	 *
	 * @param mixed $data Outline-shaped input.
	 * @return array|\WP_Error
	 */
	public static function sanitize_outline( $data ) {
		$bad = new \WP_Error(
			'agentimus_ai_bad_output',
			__( 'The AI didn’t return a usable outline — please try again.', 'agentimus' ),
			array( 'status' => 502 )
		);
		if ( ! is_array( $data ) ) {
			return $bad;
		}

		$title    = mb_substr( trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) ), 0, 160 );
		$sections = array();
		foreach ( array_slice( (array) ( $data['sections'] ?? array() ), 0, self::MAX_OUTLINE_SECTIONS ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$heading = trim( sanitize_text_field( (string) ( $section['heading'] ?? '' ) ) );
			if ( '' === $heading ) {
				continue;
			}
			$sections[] = array(
				'heading' => mb_substr( $heading, 0, 120 ),
				'note'    => mb_substr( trim( sanitize_text_field( (string) ( $section['note'] ?? '' ) ) ), 0, 240 ),
			);
		}

		if ( '' === $title || ! $sections ) {
			return $bad;
		}
		return array(
			'title'    => $title,
			'sections' => $sections,
		);
	}

	/**
	 * POST /assistant/refine — revise the HELD draft with a targeted instruction
	 * ("add a section on caching", "drop the checklist", "shorten the intro"):
	 * one AI call, the complete revised document back, still writing nothing.
	 * The current draft is re-sanitised BEFORE it rides in the prompt, so the
	 * model only ever sees what the preview showed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refine( \WP_REST_Request $request ) {
		$instruction = trim( sanitize_textarea_field( (string) $request->get_param( 'instruction' ) ) );
		if ( strlen( $instruction ) < 4 ) {
			return new \WP_Error(
				'agentimus_instruction_short',
				__( 'Say what to change first — e.g. “add a short section on caching”.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		if ( strlen( $instruction ) > 500 ) {
			$instruction = substr( $instruction, 0, 500 );
		}

		$draft = self::sanitize_draft( $request->get_param( 'draft' ) );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		if ( ! Assist::ai_available() ) {
			return new \WP_Error(
				'agentimus_ai_unavailable',
				__( 'No AI text model is configured. Add or enable one under Settings → AI, then try again.', 'agentimus' ),
				array( 'status' => 503 )
			);
		}

		$text = ( new Assist( $this->settings ) )->generate(
			self::system_prompt(),
			self::revision_prompt( $draft, $instruction ),
			self::COMPOSE_TOKENS
		);
		if ( is_wp_error( $text ) ) {
			return self::friendly_ai_error( $text );
		}

		$revised = self::parse_draft( $text );
		if ( is_wp_error( $revised ) ) {
			return $revised;
		}
		return rest_ensure_response( array( 'draft' => $revised ) );
	}

	/**
	 * PURE: the revision user-prompt — the current draft as JSON plus the owner's
	 * instruction, with preserve-everything-else stated explicitly so a one-line
	 * request can't silently rewrite the whole post.
	 *
	 * @param array  $draft       The sanitised current draft.
	 * @param string $instruction The owner's change request.
	 * @return string
	 */
	public static function revision_prompt( array $draft, $instruction ) {
		return "Here is the current draft, as JSON:\n"
			. wp_json_encode( $draft, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. "\n\nRevision request: " . $instruction
			. "\n\nReturn the COMPLETE revised draft as the same single JSON object with all the same keys. "
			. 'Apply only the requested change; preserve everything else — the title, structure, wording, '
			. 'tags, topics and categories — unless the request itself asks for them to change.';
	}

	/**
	 * POST /assistant/generate-image — one image for one slot, on one explicit
	 * click. Two-step prompt chain (the good idea from the owner's earlier
	 * aiwriter plugin): the slot's alt text is a SEED, first rewritten by the text
	 * model into a vivid scene prompt, then rendered by the image model. The
	 * result lands in the media library with the ORIGINAL alt (that's the honest
	 * accessibility text — the scene prompt is generator jargon), EXIF-free by
	 * WordPress's own import pipeline. Never called in bulk: one slot, one click,
	 * one image — image generation is priced differently from text and the click
	 * is the consent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_image( \WP_REST_Request $request ) {
		$alt = trim( sanitize_text_field( (string) $request->get_param( 'alt' ) ) );
		if ( mb_strlen( $alt ) < 5 ) {
			return new \WP_Error(
				'agentimus_alt_short',
				__( 'Describe the image in a sentence first.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		$title = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );

		if ( ! self::image_ready() ) {
			return new \WP_Error(
				'agentimus_image_unavailable',
				__( 'Your connected AI provider can’t generate images. Pick one from the media library instead, or connect an image-capable provider under Settings → AI.', 'agentimus' ),
				array( 'status' => 503 )
			);
		}

		// Step 1 — scene description (a TEXT call: rides the same rate limit as
		// every other generation, which also bounds the paired image call below).
		$scene = ( new Assist( $this->settings ) )->generate(
			self::scene_system_prompt(),
			( '' !== $title ? 'Article: ' . $title . "\n" : '' ) . 'Image description: ' . $alt,
			self::SCENE_TOKENS
		);
		if ( is_wp_error( $scene ) ) {
			return self::friendly_ai_error( $scene );
		}

		// Step 2 — the one image call.
		try {
			$file = wp_ai_client_prompt( $scene )->generate_image();
		} catch ( \Throwable $e ) {
			$file = new \WP_Error( 'agentimus_image_failed', $e->getMessage(), array( 'status' => 502 ) );
		}
		if ( is_wp_error( $file ) ) {
			// Providers answer quota refusals with pages of API jargon; say the one
			// thing the owner can act on. (Capability detection can't see QUOTA — a
			// provider can "support" image generation on a plan that includes none.)
			$raw = (string) $file->get_error_message();
			if ( false !== stripos( $raw, 'quota' ) || false !== stripos( $raw, 'exceeded' ) ) {
				return new \WP_Error(
					'agentimus_image_quota',
					__( 'Your AI provider declined the image: the current plan’s image quota is used up (or the plan doesn’t include image generation). Pick an image from the library instead, or check the provider’s plan.', 'agentimus' ),
					array( 'status' => 502 )
				);
			}
			return new \WP_Error(
				'agentimus_image_failed',
				mb_substr( preg_replace( '/\s+/', ' ', $raw ), 0, 240 ),
				array( 'status' => 502 )
			);
		}

		$attachment_id = self::import_image_file( $file, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return rest_ensure_response(
			array(
				'id'      => $attachment_id,
				'url'     => (string) ( wp_get_attachment_image_url( $attachment_id, 'medium' ) ?: wp_get_attachment_url( $attachment_id ) ),
				'fullUrl' => (string) wp_get_attachment_url( $attachment_id ),
				'alt'     => $alt,
			)
		);
	}

	/**
	 * The scene-describer instruction: turn a slot's alt seed into one concrete
	 * image prompt. Blog-appropriate styles only; text-in-image banned (models
	 * mangle it, and the article already carries the words).
	 *
	 * @return string
	 */
	public static function scene_system_prompt() {
		return 'You turn a short description of a blog-article image into ONE vivid, concrete image-generation prompt. '
			. 'Describe subject, setting, composition, lighting and style in a single plain-text paragraph. '
			. 'Prefer clean editorial illustration or natural photography suited to a professional blog. '
			. 'No text, lettering, watermarks, logos or UI screenshots in the image. Return ONLY the prompt paragraph.';
	}

	/**
	 * Import a generated image (inline base64 or remote URL) through WordPress's
	 * own sideload pipeline — validation, size variants, clean metadata — and
	 * stamp the slot's alt on it. Temp files are cleaned on every path.
	 *
	 * @param object $file The AI client's File DTO.
	 * @param string $alt  The slot's alt text.
	 * @return int|\WP_Error Attachment ID.
	 */
	private static function import_image_file( $file, $alt ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$mime = method_exists( $file, 'getMimeType' ) ? (string) $file->getMimeType() : 'image/png';
		$exts = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		$ext  = isset( $exts[ $mime ] ) ? $exts[ $mime ] : 'png';

		if ( method_exists( $file, 'isInline' ) && $file->isInline() ) {
			$data = base64_decode( (string) $file->getBase64Data(), true );
			if ( false === $data || '' === $data ) {
				return new \WP_Error( 'agentimus_image_failed', __( 'The generated image came back unreadable — please try again.', 'agentimus' ), array( 'status' => 502 ) );
			}
			$tmp = wp_tempnam( 'agentimus-image.' . $ext );
			if ( ! $tmp || false === file_put_contents( $tmp, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- controlled temp file.
				return new \WP_Error( 'agentimus_image_failed', __( 'Couldn’t stage the image for import.', 'agentimus' ), array( 'status' => 500 ) );
			}
		} else {
			$tmp = download_url( (string) $file->getUrl() );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
		}

		$name       = sanitize_file_name( 'assistant-' . sanitize_title( mb_substr( $alt, 0, 48 ) ) . '.' . $ext );
		$attachment = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			mb_substr( $alt, 0, 120 ) // Attachment title = the human description.
		);
		if ( is_wp_error( $attachment ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- best-effort temp cleanup.
			return $attachment;
		}
		update_post_meta( (int) $attachment, '_wp_attachment_image_alt', $alt );
		return (int) $attachment;
	}

	/**
	 * POST /assistant/create — materialise a previewed draft through the governed
	 * write path. The owner clicked the button; this is the ONLY place the
	 * assistant writes, and it can only ever produce a draft or a pending post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		// v1 never publishes — clamp BEFORE ContentWriter even sees the status, so
		// the assistant can't go live even on a site whose publish rung is on.
		$status = 'pending' === (string) $request->get_param( 'status' ) ? 'pending' : 'draft';

		$input = array(
			'type'    => sanitize_key( (string) ( $request->get_param( 'type' ) ? $request->get_param( 'type' ) : 'post' ) ),
			'title'   => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'content' => wp_kses_post( (string) $request->get_param( 'content' ) ),
			'status'  => $status,
		);
		$excerpt = sanitize_text_field( (string) $request->get_param( 'excerpt' ) );
		if ( '' !== $excerpt ) {
			$input['excerpt'] = $excerpt;
		}
		$description = sanitize_text_field( (string) $request->get_param( 'description' ) );
		if ( '' !== $description ) {
			$input['description'] = $description;
		}
		foreach ( array( 'topics', 'tags', 'categories' ) as $list ) {
			$values = self::clean_list( $request->get_param( $list ), 'topics' === $list ? self::MAX_TOPICS : ( 'tags' === $list ? self::MAX_TAGS : self::MAX_CATEGORIES ) );
			if ( $values ) {
				$input[ $list ] = $values;
			}
		}

		// Featured image: an attachment the owner chose or generated in the preview.
		// ContentWriter re-validates it (is it an image, does the type support one).
		$featured = absint( $request->get_param( 'featured_image' ) );
		if ( $featured > 0 ) {
			$input['featured_image'] = (string) $featured;
		}

		// Filled image slots become <figure>s injected after their anchor headings —
		// only slots with a REAL image attachment; empty slots simply don't exist in
		// the published content (a skipped suggestion leaves no placeholder behind).
		$figures = array();
		foreach ( array_slice( (array) $request->get_param( 'images' ), 0, self::MAX_IMAGE_SLOTS ) as $img ) {
			if ( ! is_array( $img ) ) {
				continue;
			}
			$aid = absint( $img['attachment_id'] ?? 0 );
			if ( $aid <= 0 || ! wp_attachment_is_image( $aid ) ) {
				continue;
			}
			$url = wp_get_attachment_image_url( $aid, 'large' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $aid );
			}
			if ( ! $url ) {
				continue;
			}
			$figures[] = array(
				'html'          => self::figure_html( (string) $url, sanitize_text_field( (string) ( $img['alt'] ?? '' ) ), $aid ),
				'after_heading' => sanitize_text_field( (string) ( $img['after_heading'] ?? '' ) ),
			);
		}
		if ( $figures ) {
			$input['content'] = self::inject_images( $input['content'], $figures );
		}

		// The editor should open REAL blocks, not a Classic block with a
		// "Convert to blocks" chore — figures included, so this runs last.
		$input['content'] = self::blockify( $input['content'] );

		$result = ( new ContentWriter( $this->settings ) )->create( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'post' => $result ) );
	}

	/**
	 * GET /assistant/posts — the edit-existing picker's search: the owner's own
	 * editable posts, each carrying an honest verdict on whether the assistant
	 * can rewrite it safely (and why not, when it can't).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function posts( \WP_REST_Request $request ) {
		$q     = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$query = new \WP_Query(
			array(
				'post_type'      => Content::post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				's'              => $q,
				'posts_per_page' => 10,
				'orderby'        => '' === $q ? 'modified' : 'relevance',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$reason = self::edit_gate_reason( $post->post_content );
			$rows[] = array(
				'id'          => $post->ID,
				'title'       => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'agentimus' ),
				'status'      => $post->post_status,
				'statusLabel' => self::status_label( $post->post_status ),
				'date'        => get_the_modified_date( '', $post ),
				'compatible'  => '' === $reason,
				'reason'      => $reason,
			);
		}
		return rest_ensure_response( array( 'posts' => $rows ) );
	}

	/**
	 * GET /assistant/post/{id} — one post as an editable DOCUMENT: the same
	 * draft shape compose produces, so the whole preview/refine/undo machinery
	 * works on it unchanged. Content comes back as clean image-free HTML; the
	 * post's figures become slots (attachment kept, heading anchor recovered).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function fetch_post( \WP_REST_Request $request ) {
		$post   = get_post( absint( $request->get_param( 'id' ) ) );
		$reason = self::edit_gate_reason( $post->post_content );
		if ( '' !== $reason ) {
			return new \WP_Error(
				'agentimus_post_uneditable',
				sprintf(
					/* translators: %s: the reason this post can't be rewritten. */
					__( 'This post %s — pick another, or edit it in the editor.', 'agentimus' ),
					$reason
				),
				array( 'status' => 409 )
			);
		}
		return rest_ensure_response( array( 'post' => self::post_to_doc( $post ) ) );
	}

	/**
	 * POST /assistant/update — materialise a revised document back onto its
	 * post through the governed write path. The ONE rule that has no exceptions:
	 * no status ever rides this call — the assistant edits content, never
	 * visibility. A draft stays a draft, a published post stays published, and
	 * WordPress keeps the previous version in Revisions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$input = array(
			'post_id' => (string) absint( $request->get_param( 'id' ) ),
			'title'   => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'content' => wp_kses_post( (string) $request->get_param( 'content' ) ),
			'excerpt' => sanitize_text_field( (string) $request->get_param( 'excerpt' ) ),
		);
		$description = sanitize_text_field( (string) $request->get_param( 'description' ) );
		if ( '' !== $description ) {
			$input['description'] = $description;
		}
		foreach ( array( 'topics', 'tags', 'categories' ) as $list ) {
			$values = self::clean_list( $request->get_param( $list ), 'topics' === $list ? self::MAX_TOPICS : ( 'tags' === $list ? self::MAX_TAGS : self::MAX_CATEGORIES ) );
			if ( $values ) {
				$input[ $list ] = $values;
			}
		}
		$featured = absint( $request->get_param( 'featured_image' ) );
		if ( $featured > 0 ) {
			$input['featured_image'] = (string) $featured;
		}

		$figures = array();
		foreach ( array_slice( (array) $request->get_param( 'images' ), 0, self::MAX_IMAGE_SLOTS ) as $img ) {
			if ( ! is_array( $img ) ) {
				continue;
			}
			$aid = absint( $img['attachment_id'] ?? 0 );
			if ( $aid <= 0 || ! wp_attachment_is_image( $aid ) ) {
				continue;
			}
			$url = wp_get_attachment_image_url( $aid, 'large' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $aid );
			}
			if ( ! $url ) {
				continue;
			}
			$figures[] = array(
				'html'          => self::figure_html( (string) $url, sanitize_text_field( (string) ( $img['alt'] ?? '' ) ), $aid ),
				'after_heading' => sanitize_text_field( (string) ( $img['after_heading'] ?? '' ) ),
			);
		}
		if ( $figures ) {
			$input['content'] = self::inject_images( $input['content'], $figures );
		}
		$input['content'] = self::blockify( $input['content'] );

		$result = ( new ContentWriter( $this->settings ) )->update( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'post' => $result ) );
	}

	/**
	 * PURE: why a post can't be rewritten by the assistant, or '' when it can.
	 * Three honest gates: block vocabulary (anything outside what the assistant
	 * itself writes would be destroyed by the round-trip), image count (more
	 * figures than slots exist to carry them), and length (a whole-document
	 * rewrite must fit the output budget). Phrases complete the sentence
	 * "This post …".
	 *
	 * @param string $content Raw post_content (block markup or classic HTML).
	 * @return string Empty when editable; otherwise the reason.
	 */
	public static function edit_gate_reason( $content ) {
		$content = (string) $content;

		if ( preg_match_all( '/<!--\s*wp:([a-z][\w\/-]*)/i', $content, $m ) ) {
			$offenders = array();
			foreach ( array_unique( $m[1] ) as $name ) {
				$bare = strtolower( 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name );
				if ( ! in_array( $bare, self::EDIT_SAFE_BLOCKS, true ) ) {
					$offenders[] = $bare;
				}
			}
			if ( $offenders ) {
				return sprintf(
					/* translators: %s: comma-separated block names. */
					__( 'uses blocks the assistant can’t rewrite safely yet (%s)', 'agentimus' ),
					implode( ', ', array_slice( $offenders, 0, 4 ) )
				);
			}
		}

		if ( preg_match_all( self::FIGURE_RX, $content, $unused ) > self::MAX_IMAGE_SLOTS ) {
			return sprintf(
				/* translators: %d: the image-slot ceiling. */
				__( 'has more images than the assistant can carry (%d)', 'agentimus' ),
				self::MAX_IMAGE_SLOTS
			);
		}

		$words = str_word_count( wp_strip_all_tags( $content ) );
		if ( $words > self::MAX_EDIT_WORDS ) {
			return sprintf(
				/* translators: %d: the post's word count. */
				__( 'is longer than a one-pass rewrite can hold (~%d words)', 'agentimus' ),
				$words
			);
		}
		return '';
	}

	/**
	 * PURE: post_content (block markup or classic HTML) → the document parts the
	 * drawer works on — the exact MIRROR of create()'s inject_images+blockify:
	 * block comments stripped, figures lifted OUT into slots (attachment kept,
	 * nearest preceding h2/h3 recovered as the anchor), so images never ride
	 * through the model. A figure with unusable alt gets an honest placeholder
	 * instead of being dropped — an existing image must never be lost.
	 *
	 * @param string $content Raw post_content.
	 * @return array{content:string,images:array}
	 */
	public static function content_to_doc( $content ) {
		$content = (string) $content;
		$content = (string) preg_replace( '/<!--\s*\/?wp:[^>]*-->\s*/', '', $content );

		$slots = array();
		if ( preg_match_all( self::FIGURE_RX, $content, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				list( $figure, $offset ) = $hit;
				$slot = array(
					'alt'           => '',
					'after_heading' => '',
				);
				if ( preg_match( '/\balt="([^"]*)"/i', $figure, $a ) ) {
					$slot['alt'] = html_entity_decode( $a[1], ENT_QUOTES );
				}
				if ( mb_strlen( trim( $slot['alt'] ) ) < 5 ) {
					$slot['alt'] = __( 'Image kept from the original post', 'agentimus' );
				}
				if ( preg_match( '/wp-image-(\d+)/', $figure, $i ) ) {
					$slot['attachment_id'] = (int) $i[1];
				}
				if ( preg_match_all( '#<h([23])\b[^>]*>(.*?)</h\1>#is', substr( $content, 0, $offset ), $h ) ) {
					$slot['after_heading'] = trim( wp_strip_all_tags( end( $h[2] ) ) );
				}
				$slots[] = $slot;
			}
			$content = (string) preg_replace( self::FIGURE_RX, '', $content );
		}

		return array(
			'content' => trim( $content ),
			'images'  => self::clean_image_slots( $slots ),
		);
	}

	/**
	 * One post as the drawer's editable document. Slots gain display URLs for
	 * the preview thumbnails; an attachment that no longer resolves to a real
	 * image is dropped from its slot (the suggestion survives, the dead id
	 * doesn't).
	 *
	 * @param \WP_Post $post The post.
	 * @return array
	 */
	private static function post_to_doc( \WP_Post $post ) {
		$parts = self::content_to_doc( $post->post_content );
		foreach ( $parts['images'] as &$slot ) {
			if ( empty( $slot['attachment_id'] ) ) {
				continue;
			}
			if ( ! wp_attachment_is_image( $slot['attachment_id'] ) ) {
				unset( $slot['attachment_id'] );
				continue;
			}
			$url = wp_get_attachment_image_url( $slot['attachment_id'], 'medium' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $slot['attachment_id'] );
			}
			if ( $url ) {
				$slot['url'] = (string) $url;
			}
		}
		unset( $slot );

		$featured = null;
		$thumb    = (int) get_post_thumbnail_id( $post );
		if ( $thumb > 0 ) {
			$furl     = wp_get_attachment_image_url( $thumb, 'medium' );
			$featured = array(
				'id'  => $thumb,
				'url' => (string) ( $furl ? $furl : wp_get_attachment_url( $thumb ) ),
			);
		}

		return array(
			'id'            => $post->ID,
			'status'        => $post->post_status,
			'statusLabel'   => self::status_label( $post->post_status ),
			'title'         => (string) $post->post_title,
			'excerpt'       => (string) $post->post_excerpt,
			'content'       => wp_kses_post( $parts['content'] ),
			'description'   => (string) get_post_meta( $post->ID, Description::META, true ),
			'topics'        => array_values( array_filter( (array) get_post_meta( $post->ID, Topics::META_TOPICS, true ), 'is_string' ) ),
			'tags'          => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
			'categories'    => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'images'        => $parts['images'],
			'featuredImage' => $featured,
		);
	}

	/**
	 * A post status as the short human label the drawer shows.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'publish' => __( 'Published', 'agentimus' ),
			'draft'   => __( 'Draft', 'agentimus' ),
			'pending' => __( 'Pending review', 'agentimus' ),
			'private' => __( 'Private', 'agentimus' ),
			'future'  => __( 'Scheduled', 'agentimus' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * The compose system instruction: one JSON document, keys fixed, body as clean
	 * post HTML. Kept strict so parsing is boring; the site's Content Guidelines
	 * are layered on top by {@see Assist::generate()}, not here.
	 *
	 * @return string
	 */
	public static function system_prompt() {
		return 'You are the writing assistant inside a WordPress site\'s admin, drafting a post from the owner\'s brief. '
			. 'Respond with ONLY a single JSON object — no markdown fences, no commentary before or after — with exactly these keys: '
			. '"title" (string), '
			. '"excerpt" (string, 1–2 plain sentences), '
			. '"content" (string: the full post body as clean HTML using only <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <a>, <blockquote> — no <h1>, no images, no scripts or styles), '
			. '"description" (string: one sentence under 160 characters saying what the page is about, for AI assistants), '
			. '"topics" (array of 3–6 short topic phrases), '
			. '"tags" (array of 2–5 tag names), '
			. '"categories" (array of 0–2 category names, only if clearly implied by the brief), '
			. '"images" (array of 0–4 image SUGGESTIONS, only where a picture would genuinely help the reader: '
			. 'each {"alt": a rich, self-contained visual description an image generator could paint from — subject, setting, mood, '
			. '"after_heading": the exact text of the h2/h3 the image should follow, or "" for right after the introduction}; '
			. 'never put <img> tags in "content"). '
			. 'Write concretely in the brief\'s language; no filler, no invented facts or statistics. '
			. 'Voice: follow the site content guidelines above when they declare one (their author, '
			. 'their person, their tone are real — use them); never INVENT credentials, employers or '
			. 'anecdotes the guidelines don\'t provide; and when the brief itself specifies a voice, '
			. 'the brief wins. With no guidance from either, write in a neutral site voice. '
			. 'If the brief asks for length, honour it; otherwise write a complete, useful post of natural length.';
	}

	/**
	 * PURE: turn the model's text into a sanitised draft document, or a clear error.
	 * Tolerates the two classic wrappers (code fences, prose around the object) by
	 * cutting from the first "{" to the last "}"; everything inside is then held to
	 * the schema and WordPress-sanitised — the preview renders what create() would
	 * save, never raw model output.
	 *
	 * @param string $text Raw model output.
	 * @return array|\WP_Error
	 */
	public static function parse_draft( $text ) {
		$text  = trim( (string) $text );
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		$data  = ( false !== $start && false !== $end && $end > $start )
			? json_decode( substr( $text, $start, $end - $start + 1 ), true )
			: null;

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'agentimus_ai_bad_output',
				__( 'The AI didn’t return a usable draft — please try again.', 'agentimus' ),
				array( 'status' => 502 )
			);
		}
		return self::sanitize_draft( $data );
	}

	/**
	 * PURE: hold a draft-shaped array to the schema and WordPress-sanitise every
	 * field — the shared gate for BOTH directions: model output on its way to the
	 * preview (parse_draft) and a held draft on its way back into a revision
	 * prompt (refine). Same rules either way, so nothing un-sanitised ever rides
	 * in a prompt or a preview.
	 *
	 * @param mixed $data Draft-shaped input.
	 * @return array|\WP_Error
	 */
	public static function sanitize_draft( $data ) {
		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'agentimus_ai_bad_output',
				__( 'The AI didn’t return a usable draft — please try again.', 'agentimus' ),
				array( 'status' => 502 )
			);
		}

		$title   = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		$content = wp_kses_post( (string) ( $data['content'] ?? '' ) );
		if ( '' === $title || '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new \WP_Error(
				'agentimus_ai_bad_output',
				__( 'The AI’s draft was missing a title or body — please try again.', 'agentimus' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'title'       => $title,
			'excerpt'     => sanitize_text_field( (string) ( $data['excerpt'] ?? '' ) ),
			'content'     => $content,
			'description' => mb_substr( sanitize_text_field( (string) ( $data['description'] ?? '' ) ), 0, 200 ),
			'topics'      => self::clean_list( $data['topics'] ?? array(), self::MAX_TOPICS ),
			'tags'        => self::clean_list( $data['tags'] ?? array(), self::MAX_TAGS ),
			'categories'  => self::clean_list( $data['categories'] ?? array(), self::MAX_CATEGORIES ),
			'images'      => self::clean_image_slots( $data['images'] ?? array() ),
		);
	}

	/**
	 * PURE: sanitise the image SLOTS — placement suggestions, not images. A slot
	 * needs a real description (its alt is both the accessibility text and the
	 * generation seed); an `attachment_id` a slot may carry (a chosen/generated
	 * image) survives the round-trip, so a revision never orphans a paid image.
	 *
	 * @param mixed $value Raw slots.
	 * @return array
	 */
	public static function clean_image_slots( $value ) {
		$out = array();
		foreach ( array_slice( (array) $value, 0, self::MAX_IMAGE_SLOTS ) as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$alt = trim( sanitize_text_field( (string) ( $slot['alt'] ?? '' ) ) );
			if ( mb_strlen( $alt ) < 5 ) {
				continue;
			}
			$clean = array(
				'alt'           => mb_substr( $alt, 0, 300 ),
				'after_heading' => mb_substr( trim( sanitize_text_field( (string) ( $slot['after_heading'] ?? '' ) ) ), 0, 200 ),
			);
			$aid   = absint( $slot['attachment_id'] ?? 0 );
			if ( $aid > 0 ) {
				$clean['attachment_id'] = $aid;
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * PURE: one image as article HTML — the same figure markup the block editor
	 * produces for an image, so the editor adopts it cleanly.
	 *
	 * @param string $url Image URL.
	 * @param string $alt Alt text.
	 * @param int    $id  Attachment ID.
	 * @return string
	 */
	public static function figure_html( $url, $alt, $id ) {
		return '<figure class="wp-block-image size-large">'
			. '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $id . '"/>'
			. '</figure>';
	}

	/**
	 * PURE: place each figure after its anchor heading. Anchoring is by the
	 * heading's TEXT (normalised, case-insensitive) because both sides of the
	 * match come from the same generation — and every miss degrades gracefully
	 * instead of guessing: "" anchors after the first paragraph (the intro), a
	 * heading that no longer exists (e.g. renamed by a revision) falls back to
	 * the intro position, and a content with no paragraphs at all appends. An
	 * image never vanishes because its anchor moved — the aiwriter lesson about
	 * fragile ordinal anchors, answered with fallbacks instead of faith.
	 *
	 * @param string $content Article HTML.
	 * @param array  $figures [{html, after_heading}].
	 * @return string
	 */
	public static function inject_images( $content, array $figures ) {
		$content = (string) $content;

		foreach ( $figures as $figure ) {
			$html   = (string) $figure['html'];
			$anchor = strtolower( trim( (string) $figure['after_heading'] ) );
			$done   = false;

			if ( '' !== $anchor && preg_match_all( '#<h([23])\b[^>]*>(.*?)</h\1>#is', $content, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $i => $match ) {
					$text = strtolower( trim( wp_strip_all_tags( $m[2][ $i ][0] ) ) );
					if ( $text === $anchor ) {
						$pos     = self::skip_past_figures( $content, $match[1] + strlen( $match[0] ) );
						$content = substr( $content, 0, $pos ) . $html . substr( $content, $pos );
						$done    = true;
						break;
					}
				}
			}
			if ( ! $done ) {
				// Intro position: after the first closing paragraph; append when the
				// content has none.
				$pos = stripos( $content, '</p>' );
				if ( false !== $pos ) {
					$pos     = self::skip_past_figures( $content, $pos + 4 );
					$content = substr( $content, 0, $pos ) . $html . substr( $content, $pos );
				} else {
					$content .= $html;
				}
			}
		}
		return $content;
	}

	/**
	 * PURE: wrap the assistant's bounded HTML in NATIVE block markup, so the
	 * editor opens real Heading/Paragraph/List/Quote/Image blocks instead of
	 * one Classic block with a "Convert to blocks" chore. Possible only
	 * because the compose contract keeps the vocabulary tiny — each element
	 * maps to exactly one core block, serialised the way the editor itself
	 * serialises it. Anything outside the vocabulary rides in a custom-HTML
	 * block (renders identically); on any parse trouble the original HTML is
	 * returned unchanged and the Classic block remains the safety net.
	 *
	 * @param string $content Clean post HTML (kses'd, figures already injected).
	 * @return string Block markup, or the original content when conversion can't run.
	 */
	public static function blockify( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content || false !== strpos( $content, '<!-- wp:' ) || ! class_exists( \DOMDocument::class ) ) {
			return $content; // Empty, already blocks, or no DOM extension.
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$ok = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><div>' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		if ( ! $ok || ! $dom->documentElement ) {
			return $content;
		}

		$blocks = array();
		foreach ( $dom->documentElement->childNodes as $node ) {
			$block = self::node_to_block( $node );
			if ( '' !== $block ) {
				$blocks[] = $block;
			}
		}
		return $blocks ? implode( "\n\n", $blocks ) : $content;
	}

	/**
	 * One top-level DOM node → one serialised core block.
	 *
	 * @param \DOMNode $node A child of the parsed content root.
	 * @return string Block markup, or '' for ignorable nodes.
	 */
	private static function node_to_block( \DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $node->textContent );
			return '' === $text ? '' : "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );
		switch ( $tag ) {
			case 'p':
				return "<!-- wp:paragraph -->\n<p>" . self::dom_inner_html( $node ) . "</p>\n<!-- /wp:paragraph -->";

			case 'h2':
				return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . self::dom_inner_html( $node ) . "</h2>\n<!-- /wp:heading -->";

			case 'h3':
				return "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . self::dom_inner_html( $node ) . "</h3>\n<!-- /wp:heading -->";

			case 'ul':
			case 'ol':
				$items = '';
				foreach ( $node->childNodes as $child ) {
					if ( XML_ELEMENT_NODE === $child->nodeType && 'li' === strtolower( $child->nodeName ) ) {
						$items .= "<!-- wp:list-item -->\n<li>" . self::dom_inner_html( $child ) . "</li>\n<!-- /wp:list-item -->\n";
					}
				}
				$attrs = 'ol' === $tag ? ' {"ordered":true}' : '';
				return '<!-- wp:list' . $attrs . " -->\n<" . $tag . " class=\"wp-block-list\">\n" . $items . '</' . $tag . ">\n<!-- /wp:list -->";

			case 'blockquote':
				// The quote block nests paragraph blocks; loose inline content
				// (a bare-text quote) collects into one.
				$inner = '';
				$loose = '';
				foreach ( $node->childNodes as $child ) {
					if ( XML_ELEMENT_NODE === $child->nodeType && 'p' === strtolower( $child->nodeName ) ) {
						if ( '' !== trim( $loose ) ) {
							$inner .= "<!-- wp:paragraph -->\n<p>" . trim( $loose ) . "</p>\n<!-- /wp:paragraph -->";
							$loose  = '';
						}
						$inner .= "<!-- wp:paragraph -->\n<p>" . self::dom_inner_html( $child ) . "</p>\n<!-- /wp:paragraph -->";
					} else {
						$loose .= $node->ownerDocument->saveHTML( $child );
					}
				}
				if ( '' !== trim( $loose ) ) {
					$inner .= "<!-- wp:paragraph -->\n<p>" . trim( $loose ) . "</p>\n<!-- /wp:paragraph -->";
				}
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $inner . "</blockquote>\n<!-- /wp:quote -->";

			case 'figure':
				// Our injected image figure — recover the attachment id from the
				// wp-image-N class the editor itself uses.
				$img = $node->getElementsByTagName( 'img' )->item( 0 );
				$id  = 0;
				if ( $img && preg_match( '/wp-image-(\d+)/', (string) $img->getAttribute( 'class' ), $m ) ) {
					$id = (int) $m[1];
				}
				$attrs = $id > 0 ? sprintf( ' {"id":%d,"sizeSlug":"large","linkDestination":"none"}', $id ) : '';
				return '<!-- wp:image' . $attrs . " -->\n" . $node->ownerDocument->saveHTML( $node ) . "\n<!-- /wp:image -->";

			default:
				// Outside the contract's vocabulary: render verbatim through the
				// custom-HTML block — displays identically, still valid blocks.
				return "<!-- wp:html -->\n" . $node->ownerDocument->saveHTML( $node ) . "\n<!-- /wp:html -->";
		}
	}

	/**
	 * The inner HTML of a DOM node, serialised by its own document.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private static function dom_inner_html( \DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * PURE: advance an insertion point past any figures already sitting there,
	 * so figures injected one by one under the same anchor keep their order
	 * instead of leap-frogging each other.
	 *
	 * @param string $content The content being injected into.
	 * @param int    $pos     Candidate insertion offset.
	 * @return int The adjusted offset.
	 */
	private static function skip_past_figures( $content, $pos ) {
		while ( preg_match( '#\A\s*<figure class="wp-block-image[^"]*"[^>]*>.*?</figure>#is', substr( $content, $pos ), $m ) ) {
			$pos += strlen( $m[0] );
		}
		return $pos;
	}

	/**
	 * PURE: a bounded, deduped list of short plain strings from arbitrary input.
	 *
	 * @param mixed $value Raw list.
	 * @param int   $max   Ceiling.
	 * @return string[]
	 */
	public static function clean_list( $value, $max ) {
		$out = array();
		foreach ( (array) $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' === $item || mb_strlen( $item ) > 60 || in_array( $item, $out, true ) ) {
				continue;
			}
			$out[] = $item;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}
}
