<?php
/**
 * Readiness report — a list of pass/warn/fail checks that the admin "Readiness"
 * panel renders. Each check is cheap and side-effect free.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Readiness {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run the checks.
	 *
	 * @return array<int,array<string,string>> List of { id, label, status, detail }.
	 */
	public function report() {
		$checks = array(
			$this->check_site_public(),
			$this->check_permalinks(),
			$this->check_llms_txt(),
			$this->check_llms_full(),
			$this->check_llms_full_size(),
			$this->check_post_types(),
			$this->check_about(),
			$this->check_expertise(),
			$this->check_same_as(),
			$this->check_security_txt(),
			$this->check_schema_conflict(),
			$this->check_static_robots(),
			$this->check_sitemap(),
			$this->check_robots_sitemap(),
		);

		/**
		 * Filter the readiness checks (a Pro add-on can append its own).
		 *
		 * @param array    $checks   Check rows.
		 * @param Settings $settings Settings store.
		 */
		return apply_filters( 'agentimus_readiness_checks', $checks, $this->settings );
	}

	/**
	 * Build one check row.
	 *
	 * @param string     $id     Identifier.
	 * @param string     $label  Human label.
	 * @param string     $status pass|warn|fail.
	 * @param string     $detail What the check found (the current state).
	 * @param string     $fix    What to do about it — shown for warn/fail. Empty for a clean pass.
	 * @param array|null $action Optional call-to-action: an in-app jump
	 *                           { label, tab, anchor } or an external { label, href }.
	 * @return array
	 */
	private function row( $id, $label, $status, $detail, $fix = '', $action = null ) {
		return compact( 'id', 'label', 'status', 'detail', 'fix', 'action' );
	}

	/**
	 * An in-app call-to-action that jumps to a section of the Settings tab.
	 *
	 * @param string $label  Button label.
	 * @param string $anchor DOM id of the target section.
	 * @return array
	 */
	private function nav( $label, $anchor ) {
		return array(
			'label'  => $label,
			'tab'    => 'settings',
			'anchor' => $anchor,
		);
	}

	/**
	 * An external call-to-action (e.g. a core WordPress admin screen).
	 *
	 * @param string $label Link label.
	 * @param string $href  Destination URL.
	 * @return array
	 */
	private function link( $label, $href ) {
		return array(
			'label' => $label,
			'href'  => $href,
		);
	}

	private function check_site_public() {
		$public = (bool) get_option( 'blog_public', 1 );
		return $public
			? $this->row( 'public', __( 'Search engine visibility', 'agentimus' ), 'pass', __( 'The site is public, so agents and crawlers can read it.', 'agentimus' ) )
			: $this->row(
				'public',
				__( 'Search engine visibility', 'agentimus' ),
				'fail',
				__( 'Settings → Reading is set to discourage search engines. Agents will be blocked.', 'agentimus' ),
				__( 'Open Settings → Reading and uncheck “Discourage search engines from indexing this site.” Nothing else here matters until this is on.', 'agentimus' ),
				$this->link( __( 'Open Reading settings', 'agentimus' ), admin_url( 'options-reading.php' ) )
			);
	}

	private function check_permalinks() {
		$pretty = (bool) get_option( 'permalink_structure' );
		return $pretty
			? $this->row( 'permalinks', __( 'Pretty permalinks', 'agentimus' ), 'pass', __( 'Markdown URLs like /post-slug.md resolve cleanly.', 'agentimus' ) )
			: $this->row(
				'permalinks',
				__( 'Pretty permalinks', 'agentimus' ),
				'warn',
				__( 'Plain permalinks are active. Switch to a pretty structure for tidy .md URLs.', 'agentimus' ),
				__( 'In Settings → Permalinks choose “Post name” (or any non-plain structure). With plain links, the /slug.md markdown URLs agents prefer can’t resolve.', 'agentimus' ),
				$this->link( __( 'Open Permalinks', 'agentimus' ), admin_url( 'options-permalink.php' ) )
			);
	}

	private function check_llms_txt() {
		return $this->settings->enabled( 'enable_llms_txt' )
			? $this->row( 'llms', __( '/llms.txt index', 'agentimus' ), 'pass', sprintf( /* translators: %s: URL. */ __( 'Served at %s.', 'agentimus' ), home_url( '/llms.txt' ) ) )
			: $this->row(
				'llms',
				__( '/llms.txt index', 'agentimus' ),
				'warn',
				__( 'Disabled. Enable it so agents can discover your content map.', 'agentimus' ),
				__( 'Turn on “/llms.txt index” under Settings → Features. It publishes a single map of your site that crawlers and agents check first.', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-sec-features' )
			);
	}

	private function check_llms_full() {
		return $this->settings->enabled( 'enable_llms_full' )
			? $this->row( 'llms_full', __( '/llms-full.txt full text', 'agentimus' ), 'pass', sprintf( /* translators: %s: URL. */ __( 'Served at %s.', 'agentimus' ), home_url( '/llms-full.txt' ) ) )
			: $this->row(
				'llms_full',
				__( '/llms-full.txt full text', 'agentimus' ),
				'warn',
				__( 'Disabled. The full-text edition lets agents ingest everything in one request.', 'agentimus' ),
				__( 'Enable “/llms-full.txt full text” under Settings → Features so an agent can pull your whole corpus in one fetch instead of crawling page by page.', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-sec-features' )
			);
	}

	private function check_llms_full_size() {
		// Only meaningful when the full-text edition is on; when off, check_llms_full()
		// already covers it — don't double-warn.
		if ( ! $this->settings->enabled( 'enable_llms_full' ) ) {
			return $this->row( 'llms_full_size', __( 'Full-text file size', 'agentimus' ), 'pass', __( 'The full-text edition is off, so there is nothing to size.', 'agentimus' ) );
		}

		$est       = Content::estimate_full_size( $this->settings );
		$stat      = Cache::get( Cache::LLMS_FULL_STAT );
		$truncated = is_array( $stat ) && ! empty( $stat['truncated'] );

		if ( $est['will_truncate'] || $truncated ) {
			$detail = ( $truncated && is_array( $stat ) )
				? sprintf(
					/* translators: 1: served size e.g. "1 MB"; 2: budget size. */
					__( 'The full-text file was last served at %1$s and truncated at the %2$s limit, so some content is left out.', 'agentimus' ),
					size_format( (int) $stat['bytes'] ),
					size_format( $est['budget_bytes'] )
				)
				: sprintf(
					/* translators: 1: item count; 2: estimated size; 3: budget size. */
					__( 'About %1$s items (~%2$s) would exceed the %3$s size limit, so the file will be truncated.', 'agentimus' ),
					number_format_i18n( $est['items'] ),
					size_format( $est['est_bytes'] ),
					size_format( $est['budget_bytes'] )
				);

			return $this->row(
				'llms_full_size',
				__( 'Full-text file size', 'agentimus' ),
				'warn',
				$detail,
				__( 'Lower “Posts in /llms-full.txt” under Settings → Features so the file fits, or rely on the /llms.txt index — agents can still fetch any page by appending .md to its URL.', 'agentimus' ),
				$this->nav( __( 'Adjust in Features', 'agentimus' ), 'ar-sec-features' )
			);
		}

		return $this->row(
			'llms_full_size',
			__( 'Full-text file size', 'agentimus' ),
			'pass',
			sprintf(
				/* translators: 1: item count; 2: estimated size; 3: budget size. */
				__( 'About %1$s items (~%2$s), within the %3$s limit.', 'agentimus' ),
				number_format_i18n( $est['items'] ),
				size_format( $est['est_bytes'] ),
				size_format( $est['budget_bytes'] )
			)
		);
	}

	private function check_post_types() {
		$types  = Content::post_types();
		$labels = array_map( array( Content::class, 'label' ), $types );
		return $this->row(
			'post_types',
			__( 'Content coverage', 'agentimus' ),
			'pass',
			sprintf(
				/* translators: 1: number of content types, 2: comma-separated list. */
				__( 'Indexing %1$d content type(s): %2$s.', 'agentimus' ),
				count( $types ),
				implode( ', ', $labels )
			)
		);
	}

	private function check_about() {
		return '' !== trim( (string) $this->settings->identity( 'about' ) )
			? $this->row( 'about', __( 'Author / entity profile', 'agentimus' ), 'pass', __( 'A profile sentence is set — the highest-signal line for retrieval.', 'agentimus' ) )
			: $this->row(
				'about',
				__( 'Author / entity profile', 'agentimus' ),
				'fail',
				__( 'No profile set. Add one under Identity so agents know who owns this site.', 'agentimus' ),
				__( 'Write one plain sentence under Settings → Identity: who you are, your role, and what this site is about. It’s the single line agents quote most when they cite you.', 'agentimus' ),
				$this->nav( __( 'Edit Identity', 'agentimus' ), 'ar-sec-identity' )
			);
	}

	private function check_expertise() {
		return ! empty( array_filter( (array) $this->settings->identity( 'expertise', array() ) ) )
			? $this->row( 'expertise', __( 'Expertise topics', 'agentimus' ), 'pass', __( 'Expertise is declared (feeds llms.txt and knowsAbout).', 'agentimus' ) )
			: $this->row(
				'expertise',
				__( 'Expertise topics', 'agentimus' ),
				'warn',
				__( 'No expertise topics set. They establish topical authority for agents.', 'agentimus' ),
				__( 'Add three to five topics under Settings → Identity (e.g. “WordPress development”, “API design”). They flow into llms.txt and the schema knowsAbout list so agents know what you’re an authority on.', 'agentimus' ),
				$this->nav( __( 'Add topics', 'agentimus' ), 'ar-sec-identity' )
			);
	}

	private function check_same_as() {
		return ! empty( array_filter( (array) $this->settings->identity( 'same_as', array() ) ) )
			? $this->row( 'same_as', __( 'sameAs profiles', 'agentimus' ), 'pass', __( 'Linked profiles help agents resolve your entity confidently.', 'agentimus' ) )
			: $this->row(
				'same_as',
				__( 'sameAs profiles', 'agentimus' ),
				'warn',
				__( 'No sameAs links (GitHub, LinkedIn, X…). Add them to strengthen entity matching.', 'agentimus' ),
				__( 'Add your GitHub, LinkedIn, or X profile URLs under Settings → Identity. These “sameAs” links let an agent tie this site to a known entity instead of guessing.', 'agentimus' ),
				$this->nav( __( 'Add profiles', 'agentimus' ), 'ar-sec-identity' )
			);
	}

	private function check_security_txt() {
		$url = home_url( '/.well-known/security.txt' );

		// A real on-disk file always wins (the generator stands down) — and it's
		// still the goal: a published security contact. Report it as a pass.
		if ( file_exists( \Agentimus\Paths::site_root() . '.well-known/security.txt' ) ) {
			return $this->row(
				'security_txt',
				__( 'security.txt contact', 'agentimus' ),
				'pass',
				/* translators: %s: URL. */
				sprintf( __( 'A security.txt file is published at %s; Agentimus links it and stands aside.', 'agentimus' ), $url )
			);
		}

		// Mirror the generator's real decision so the check never drifts from it.
		$sec = new Discovery\SecurityTxt( $this->settings );

		// Off (and no file): a disclosure contact is a recommended trust signal.
		if ( ! $sec->should_serve() ) {
			return $this->row(
				'security_txt',
				__( 'security.txt contact', 'agentimus' ),
				'warn',
				__( 'No security.txt. Researchers and agents have no machine-readable way to report a vulnerability.', 'agentimus' ),
				__( 'Turn on “Generate security.txt” under Settings → Security.txt and add a contact (your Identity email is reused automatically). It publishes an RFC 9116 disclosure contact at /.well-known/security.txt.', 'agentimus' ),
				$this->nav( __( 'Enable security.txt', 'agentimus' ), 'ar-sec-security' )
			);
		}

		// On, but no contact ⇒ RFC 9116 makes the document invalid ⇒ nothing served.
		$contacts = $sec->contacts();
		if ( empty( $contacts ) ) {
			return $this->row(
				'security_txt',
				__( 'security.txt contact', 'agentimus' ),
				'warn',
				__( 'security.txt is enabled but has no contact, so RFC 9116 makes it invalid and nothing is served.', 'agentimus' ),
				__( 'Add at least one Security contact (or a public contact email under Identity) in Settings → Security.txt. Without a Contact line the document can’t be published.', 'agentimus' ),
				$this->nav( __( 'Add a contact', 'agentimus' ), 'ar-sec-security' )
			);
		}

		// On, with a contact, and no file shadowing it → live.
		return $this->row(
			'security_txt',
			__( 'security.txt contact', 'agentimus' ),
			'pass',
			sprintf(
				/* translators: 1: number of contacts, 2: URL. */
				_n( 'Serving %1$d security contact at %2$s.', 'Serving %1$d security contacts at %2$s.', count( $contacts ), 'agentimus' ),
				count( $contacts ),
				$url
			)
		);
	}

	private function check_schema_conflict() {
		$schema = new Schema( $this->settings );
		if ( ! $this->settings->enabled( 'enable_schema' ) ) {
			return $this->row(
				'schema',
				__( 'JSON-LD structured data', 'agentimus' ),
				'warn',
				__( 'Schema output is disabled in settings.', 'agentimus' ),
				__( 'Enable “JSON-LD structured data” under Settings → Features — unless an SEO plugin already emits schema, in which case leaving it off avoids duplicate markup.', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-sec-features' )
			);
		}
		return $schema->seo_plugin_active()
			? $this->row( 'schema', __( 'JSON-LD structured data', 'agentimus' ), 'pass', __( 'An SEO plugin owns schema; Agentimus is standing down to avoid duplicates.', 'agentimus' ) )
			: $this->row( 'schema', __( 'JSON-LD structured data', 'agentimus' ), 'pass', __( 'Agentimus is emitting WebSite + entity + article schema.', 'agentimus' ) );
	}

	private function check_static_robots() {
		$file = \Agentimus\Paths::site_root() . 'robots.txt';
		return file_exists( $file )
			? $this->row(
				'robots',
				__( 'robots.txt control', 'agentimus' ),
				'warn',
				__( 'A static robots.txt exists at the web root and overrides this plugin. Remove it to let Agentimus manage robots rules, or edit it by hand.', 'agentimus' ),
				__( 'Delete robots.txt from your site root to let Agentimus serve a managed virtual one — or, if you maintain it by hand, add your crawler and Sitemap directives there yourself.', 'agentimus' )
			)
			: $this->row( 'robots', __( 'robots.txt control', 'agentimus' ), 'pass', __( 'WordPress serves a virtual robots.txt that this plugin manages.', 'agentimus' ) );
	}

	private function check_sitemap() {
		$sitemap = Sitemap::detect();

		// Nothing serves a sitemap — core is off, no SEO plugin, generator opted out.
		if ( '' === $sitemap['url'] ) {
			return $this->row(
				'sitemap',
				__( 'XML sitemap', 'agentimus' ),
				'warn',
				__( 'No sitemap detected. Crawlers and agents have no single index of your URLs to start from.', 'agentimus' ),
				__( 'Turn on “XML sitemap” under Settings → Features and Agentimus will generate one for you — no SEO plugin required. (Re-enabling WordPress core sitemaps, or any major SEO plugin, also satisfies this; Agentimus auto-detects and links whichever exists.)', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-sec-features' )
			);
		}

		// WordPress core serves it.
		if ( 'core' === $sitemap['source'] ) {
			return $this->row(
				'sitemap',
				__( 'XML sitemap', 'agentimus' ),
				'pass',
				__( 'WordPress core sitemap is live and advertised in robots.txt and llms.txt.', 'agentimus' )
			);
		}

		// Agentimus's own fallback generator is filling the gap.
		if ( 'agentimus' === $sitemap['source'] ) {
			return $this->row(
				'sitemap',
				__( 'XML sitemap', 'agentimus' ),
				'pass',
				sprintf(
					/* translators: %s: sitemap URL. */
					__( 'Agentimus is generating your sitemap at %s and advertising it in robots.txt and llms.txt.', 'agentimus' ),
					home_url( Sitemap::PATH )
				)
			);
		}

		// A known SEO plugin owns it — detected and linked, never duplicated.
		return $this->row(
			'sitemap',
			__( 'XML sitemap', 'agentimus' ),
			'pass',
			sprintf(
				/* translators: %s: SEO plugin name, e.g. “Yoast SEO”. */
				__( 'Provided by %s and advertised in robots.txt and llms.txt — Agentimus links it rather than emitting a duplicate.', 'agentimus' ),
				$sitemap['label']
			)
		);
	}

	private function check_robots_sitemap() {
		$robots  = $this->robots_txt_contents();
		$has     = (bool) preg_match( '/^\s*sitemap:\s*https?:\/\//mi', $robots['contents'] );
		$sitemap = Sitemap::detect();

		// Advertised — crawlers autodiscover the sitemap. This is the goal.
		if ( $has ) {
			return $this->row(
				'robots_sitemap',
				__( 'Sitemap in robots.txt', 'agentimus' ),
				'pass',
				__( 'robots.txt declares a Sitemap: line, so crawlers discover your sitemap automatically.', 'agentimus' )
			);
		}

		// A non-public site emits "Disallow: /" with no Sitemap line by design —
		// the "Search engine visibility" check above is the real fix, not robots.
		if ( ! (bool) get_option( 'blog_public', 1 ) && ! $robots['static'] ) {
			return $this->row(
				'robots_sitemap',
				__( 'Sitemap in robots.txt', 'agentimus' ),
				'warn',
				__( 'robots.txt currently blocks all crawlers, so it advertises no sitemap.', 'agentimus' ),
				__( 'This follows from “Search engine visibility” being off — fix that check first and the Sitemap: line is emitted automatically.', 'agentimus' )
			);
		}

		// Not advertised, and there's nothing to advertise yet.
		if ( '' === $sitemap['url'] ) {
			return $this->row(
				'robots_sitemap',
				__( 'Sitemap in robots.txt', 'agentimus' ),
				'warn',
				__( 'robots.txt has no Sitemap: line — there is no sitemap to point to yet.', 'agentimus' ),
				__( 'Enable “XML sitemap” under Settings → Features: Agentimus then generates a sitemap and adds the Sitemap: line to robots.txt in one step. (Core or an SEO-plugin sitemap would be linked automatically too.)', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-sec-features' )
			);
		}

		// A sitemap exists but a hand-maintained static robots.txt doesn't link it.
		if ( $robots['static'] ) {
			return $this->row(
				'robots_sitemap',
				__( 'Sitemap in robots.txt', 'agentimus' ),
				'warn',
				/* translators: %s: sitemap URL. */
				sprintf( __( 'A sitemap exists (%s) but your static robots.txt doesn’t link it, so crawlers may miss it.', 'agentimus' ), $sitemap['url'] ),
				/* translators: %s: sitemap URL. */
				sprintf( __( 'Add this line to the robots.txt file at your site root: “Sitemap: %s”. A static file overrides the one Agentimus manages, so the link has to be added by hand.', 'agentimus' ), $sitemap['url'] )
			);
		}

		// A sitemap exists, robots is virtual, but the Sitemap: line is missing —
		// almost always because the robots.txt feature is switched off.
		return $this->row(
			'robots_sitemap',
			__( 'Sitemap in robots.txt', 'agentimus' ),
			'warn',
			/* translators: %s: sitemap URL. */
			sprintf( __( 'A sitemap exists (%s) but isn’t advertised in robots.txt, so crawlers may not find it.', 'agentimus' ), $sitemap['url'] ),
			__( 'Turn on “robots.txt rules” under Settings → Features so Agentimus advertises your sitemap to crawlers.', 'agentimus' ),
			$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-sec-features' )
		);
	}

	/**
	 * The robots.txt a crawler would see: the static file if one exists at the
	 * web root, otherwise the virtual output assembled exactly as WordPress's
	 * do_robots() does (so every robots_txt filter — core, SEO plugins, Agentimus
	 * — is reflected).
	 *
	 * @return array{contents:string,static:bool}
	 */
	private function robots_txt_contents() {
		$file = \Agentimus\Paths::site_root() . 'robots.txt';
		if ( file_exists( $file ) ) {
			return array(
				'contents' => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read-only diagnostic.
				'static'   => true,
			);
		}

		$public   = get_option( 'blog_public' );
		$contents = "User-agent: *\n";
		$contents .= ( '0' === (string) $public )
			? "Disallow: /\n"
			: "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

		return array(
			'contents' => (string) apply_filters( 'robots_txt', $contents, $public ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own robots_txt filter to reconstruct the served robots.txt (mirrors do_robots()), not declaring a new hook.
			'static'   => false,
		);
	}
}
