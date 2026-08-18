<?php
/**
 * The two doors out of the plugin: get help, or report an issue.
 *
 * ⭐⭐ NEITHER OF THEM SUBMITS ANYTHING. This class composes a URL and the browser
 * opens it; the person posts under their own account, on the platform's own form.
 * That is a deliberate refusal, not a shortcut:
 *
 *  - A token shipped in the plugin would sit in every install, readable from any
 *    site's database, and let anyone post as the maintainer against his rate
 *    limit. It is the one vector this feature could create and does not.
 *  - A relay the maintainer runs would work, but the issue then arrives from a
 *    bot rather than the reporter — so nobody can be asked the one follow-up
 *    question that makes most reports usable. A bug report you cannot reply to
 *    is usually worthless.
 *
 * ⚠️ Labels come from the issue FORMS in `.github/ISSUE_TEMPLATE/`, never from a
 * `labels=` query parameter: GitHub 404s that parameter for anyone without triage
 * permission on the repository, which is every person who would ever use this.
 * The template's own `labels:` apply on creation whoever files it.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Support {

	/** The public repository. */
	const REPO = 'https://github.com/heera/agentimus';

	/** The WordPress.org support forum for this plugin. */
	const FORUM = 'https://wordpress.org/support/plugin/agentimus/';

	/**
	 * What a reporter can be filing, and which issue form answers it.
	 *
	 * ⭐ The kind exists for ONE reason: it chooses the template, and the template
	 * carries the label. It is not a survey.
	 *
	 * ⛔ "Something else" is `needs-triage`, never unlabelled — an issue with no
	 * label falls through every filter the maintainer has, which is a worse
	 * outcome than not offering the option at all.
	 *
	 * @return array<int,array{id:string,label:string,template:string}>
	 */
	public static function kinds() {
		return array(
			array(
				'id'       => 'bug',
				'label'    => __( 'Something is broken', 'agentimus' ),
				'template' => 'bug.yml',
			),
			array(
				'id'       => 'integration',
				'label'    => __( 'An integration won’t connect', 'agentimus' ),
				'template' => 'integration.yml',
			),
			array(
				'id'       => 'idea',
				'label'    => __( 'An idea', 'agentimus' ),
				'template' => 'idea.yml',
			),
			array(
				'id'       => 'docs',
				'label'    => __( 'The documentation is wrong or missing', 'agentimus' ),
				'template' => 'docs.yml',
			),
			array(
				'id'       => 'other',
				'label'    => __( 'Something else', 'agentimus' ),
				'template' => 'other.yml',
			),
		);
	}

	/**
	 * What this install is, as label => value pairs.
	 *
	 * ⭐ READ, never typed. The panel that shows this is a statement the plugin
	 * makes about the site, not a field the reporter fills in — a version number
	 * somebody edited by hand is worse than no version number, because it is
	 * believed.
	 *
	 * ⚠️ It stops being true the moment it leaves this screen: every field on a
	 * prefilled GitHub form is editable by whoever submits it. This is a courtesy
	 * to the maintainer, not evidence.
	 *
	 * @param bool $with_site Include the site address.
	 * @return array<string,string>
	 */
	public static function facts( $with_site = true ) {
		$theme = wp_get_theme();
		$seo   = SeoContext::resolve();

		$out = array(
			'Agentimus' => AGENTIMUS_VERSION,
			'WordPress' => get_bloginfo( 'version' ),
			'PHP'       => PHP_VERSION,
			'Theme'     => trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ),
		);

		if ( $with_site ) {
			$out['Site'] = home_url( '/' );
		}

		// Named rather than guessed at: "none" is a real answer and a common cause
		// of the slowness people report, so an empty line here would hide the
		// finding rather than state it.
		$out['Cache'] = wp_using_ext_object_cache()
			? __( 'persistent object cache', 'agentimus' )
			: __( 'none', 'agentimus' );

		$out['SEO'] = ( ! empty( $seo['plugin']['label'] ) )
			? (string) $seo['plugin']['label']
			: __( 'none detected', 'agentimus' );

		$connected = self::connected();
		$out['Connected'] = $connected ? implode( ', ', $connected ) : __( 'nothing', 'agentimus' );

		/**
		 * The facts attached to a support report.
		 *
		 * @param array<string,string> $out       Label => value.
		 * @param bool                 $with_site Whether the site address is included.
		 */
		return (array) apply_filters( 'agentimus_support_facts', $out, $with_site );
	}

	/**
	 * Which integrations and data sources are switched on.
	 *
	 * ⛔ Names only — never a URL, a token or a channel id. Everything here ends up
	 * in a public issue, and "Slack" is the whole of what a maintainer needs to
	 * know; a webhook URL in a public thread is a working way into somebody's
	 * workspace.
	 *
	 * @return array<int,string>
	 */
	private static function connected() {
		$s   = new Settings();
		$out = array();

		foreach ( array(
			'slack_enabled'          => 'Slack',
			'discord_enabled'        => 'Discord',
			'telegram_enabled'       => 'Telegram',
			'x_share_enabled'        => 'X',
			'linkedin_share_enabled' => 'LinkedIn',
			'sheets_enabled'         => 'Google Sheets',
			'webhook_enabled'        => 'Webhook',
		) as $key => $name ) {
			if ( $s->enabled( $key ) ) {
				$out[] = $name;
			}
		}

		try {
			if ( ( new Bing\Settings() )->connected() ) {
				$out[] = 'Bing';
			}
			if ( ( new Google\Settings() )->connected() ) {
				$out[] = 'Search Console';
			}
		} catch ( \Throwable $e ) {
			// A data source that cannot answer is not worth failing a bug report over.
			$out[] = '(a data source could not be read)';
		}

		return $out;
	}

	/**
	 * The facts as the block that travels — aligned, so it reads as a record.
	 *
	 * @param bool $with_site Include the site address.
	 * @return string
	 */
	public static function facts_text( $with_site = true ) {
		$facts = self::facts( $with_site );
		$width = 0;
		foreach ( array_keys( $facts ) as $label ) {
			$width = max( $width, strlen( $label ) );
		}

		$lines = array();
		foreach ( $facts as $label => $value ) {
			$lines[] = str_pad( $label, $width + 2 ) . $value;
		}

		// ⚠️ TRIAGE, NOT PROOF — and the wording has to stay honest about that.
		// Anybody can type this line; it exists so a report from a real install
		// reads differently at a glance from a drive-by, and for nothing else.
		$lines[] = str_pad( 'Reported', $width + 2 ) . __( 'from inside the plugin', 'agentimus' );

		return implode( "\n", $lines );
	}

	/**
	 * The GitHub issue URL for one kind of report.
	 *
	 * @param string $kind      A {@see kinds()} id.
	 * @param bool   $with_site Include the site address in the attached facts.
	 * @return string
	 */
	public static function issue_url( $kind, $with_site = true ) {
		$template = 'other.yml';
		foreach ( self::kinds() as $k ) {
			if ( $k['id'] === (string) $kind ) {
				$template = $k['template'];
				break;
			}
		}

		$facts = self::facts_text( $with_site );

		// `env` is the template field id the facts land in. ⛔ No `labels=` — see
		// the class note: GitHub 404s it for anyone without triage rights.
		//
		// ⭐ `body` is the SAME facts again, and it is not redundant: it is what
		// survives when the template does not. If the form file is missing — not
		// yet pushed, renamed, deleted — GitHub silently ignores `template` AND
		// `env`, and drops the reporter on a blank issue with an empty box. The
		// setup block simply evaporates, and neither of them ever learns it did.
		// `body` fills the description on that blank form, and is ignored
		// whenever the template is present (a form has no field with that id),
		// so the good path is untouched and the broken path still carries the
		// versions. Verified live 2026-08-18: the fallback is real, not
		// theoretical — the templates were unpushed and the block vanished.
		return add_query_arg(
			array(
				'template' => rawurlencode( $template ),
				'env'      => rawurlencode( $facts ),
				'body'     => rawurlencode( "```\n" . $facts . "\n```\n" ),
			),
			self::REPO . '/issues/new'
		);
	}

	/**
	 * Every URL the dialog can need, composed HERE.
	 *
	 * ⚠️⚠️ THE DIALOG MUST NOT BUILD THESE ITSELF, and it did until 2026-08-18.
	 * The URL format lived twice — once in PHP, once in the Vue computed — and
	 * the two drifted the moment one was fixed: the `body` fallback was added to
	 * this class, the tests passed against this class, and the button on screen
	 * went on emitting the old two-parameter URL. A test that green-lights an
	 * unused code path is worse than no test, because it is believed.
	 *
	 * One format, one place, and the screen picks a finished string.
	 *
	 * @return array<string,array{site:string,lean:string}> Keyed by kind id.
	 */
	public static function issue_urls() {
		$out = array();
		foreach ( self::kinds() as $kind ) {
			$out[ $kind['id'] ] = array(
				'site' => self::issue_url( $kind['id'], true ),
				'lean' => self::issue_url( $kind['id'], false ),
			);
		}
		return $out;
	}

	/**
	 * Everything the two dialogs need, for the boot payload.
	 *
	 * ⚠️ Both the with-site and without-site blocks are sent, so the tick is
	 * instant and needs no round trip — and so the screen can never show one
	 * thing while the URL carries another.
	 *
	 * @return array
	 */
	public static function payload() {
		return array(
			'repo'      => self::REPO,
			'forum'     => self::FORUM,
			'docs'      => 'https://heera.github.io/agentimus/',
			'kinds'     => self::kinds(),
			'facts'     => self::facts_text( true ),
			'factsLean' => self::facts_text( false ),
			// Finished URLs, one per kind × the site-address tick. Sending ten
			// strings costs a few KB and removes the one thing that had already
			// gone wrong: two places knowing how to build the same link.
			'urls'      => self::issue_urls(),
			// The forum cannot be pre-filled by anyone, so its block is carried
			// for the copy button instead {@see the Get Help dialog}.
			'version'   => AGENTIMUS_VERSION,
		);
	}
}
