<?php
/**
 * Integrazione Sentry per il tracciamento anonimo degli errori PHP.
 *
 * Si attiva SOLO se l'amministratore ha abilitato esplicitamente l'opt-in
 * nelle impostazioni del plugin. Di default è sempre disattivata.
 * Nessun dato personale o IP viene mai trasmesso.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestisce l'inizializzazione di Sentry e la privacy policy WP.
 */
class Cetus_MO_Telemetry {

	/**
	 * DSN del progetto Sentry.
	 *
	 * Sostituire con il proprio DSN prima del deploy.
	 * NON committare mai il DSN reale in un repository pubblico.
	 * In alternativa, definire la costante CETUS_MO_SENTRY_DSN in wp-config.php.
	 */
	private const DSN = '%%SENTRY_DSN%%';

	/**
	 * Inizializza Sentry solo se l'utente ha fornito il consenso esplicito
	 * e l'SDK è disponibile tramite Composer.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! self::is_opted_in() ) {
			return;
		}

		$dsn = defined( 'CETUS_MO_SENTRY_DSN' ) ? CETUS_MO_SENTRY_DSN : self::DSN;

		if ( '' === $dsn || 'YOUR_SENTRY_DSN_HERE' === $dsn || str_contains( $dsn, '%%' ) ) {
			return;
		}

		if ( ! class_exists( '\Sentry\SentrySdk' ) ) {
			return;
		}

		\Sentry\init(
			[
				'dsn'                  => $dsn,
				'send_default_pii'     => false,
				'environment'          => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'development' : 'production',
				'release'              => 'cetus-media-optimizer@' . CETUS_MO_VERSION,
				'traces_sample_rate'   => 0.0,
				'profiles_sample_rate' => 0.0,
				'max_breadcrumbs'      => 10,
				'attach_stacktrace'    => true,
				'before_send'          => [ self::class, 'sanitize_event' ],
			]
		);

		\Sentry\configureScope(
			function ( \Sentry\State\Scope $scope ): void {
				$scope->setTag( 'wp_version', get_bloginfo( 'version' ) );
				$scope->setTag( 'php_version', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
				$scope->setTag( 'plugin_version', CETUS_MO_VERSION );
				$scope->setUser( [] );
			}
		);
	}

	/**
	 * Callback before_send: rimuove qualsiasi dato personale o sensibile
	 * prima che l'evento venga trasmesso a Sentry.
	 *
	 * @param \Sentry\Event     $event Evento originale.
	 * @param \Sentry\EventHint $hint  Hint aggiuntivo (eccezione originale, ecc.).
	 * @return \Sentry\Event|null
	 */
	public static function sanitize_event( \Sentry\Event $event, \Sentry\EventHint $hint ): ?\Sentry\Event {
		// Scarta eventi che non originano dai file del nostro plugin.
		$plugin_dir = defined( 'CETUS_MO_DIR' ) ? CETUS_MO_DIR : '';
		if ( $plugin_dir ) {
			$from_plugin = false;
			foreach ( $event->getStacktrace()?->getFrames() ?? [] as $frame ) {
				if ( str_starts_with( (string) $frame->getAbsoluteFilename(), $plugin_dir ) ) {
					$from_plugin = true;
					break;
				}
			}
			// Controlla anche l'eccezione originale nello hint.
			if ( ! $from_plugin && isset( $hint->exception ) ) {
				foreach ( $hint->exception->getTrace() as $trace_frame ) {
					if ( isset( $trace_frame['file'] ) && str_starts_with( $trace_frame['file'], $plugin_dir ) ) {
						$from_plugin = true;
						break;
					}
				}
			}
			if ( ! $from_plugin ) {
				return null;
			}
		}

		$event->setUser( null );

		$request = $event->getRequest();
		if ( ! empty( $request ) ) {
			$sensitive_headers = [
				'Cookie',
				'Authorization',
				'X-Forwarded-For',
				'X-Real-IP',
				'HTTP_CLIENT_IP',
			];
			$headers           = $request['headers'] ?? [];
			foreach ( $sensitive_headers as $header ) {
				unset( $headers[ $header ], $headers[ strtolower( $header ) ] );
			}
			unset( $request['cookies'], $request['env'], $request['url'] );
			$request['headers'] = $headers;
			$event->setRequest( $request );
		}

		$extra = $event->getExtra();
		if ( ! empty( $extra ) ) {
			unset( $extra['server'] );
			$event->setExtra( $extra );
		}

		return $event;
	}

	/**
	 * Controlla se l'amministratore ha abilitato il consenso.
	 *
	 * @return bool
	 */
	public static function is_opted_in(): bool {
		return '1' === get_option( 'cetus_media_telemetry_opt_in', '0' );
	}

	/**
	 * Registra il testo della privacy policy del plugin nella pagina
	 * Privacy di WordPress (Strumenti → Privacy).
	 *
	 * @return void
	 */
	public static function register_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$allowed_tags = [
			'strong' => [],
			'a'      => [
				'href'   => [],
				'rel'    => [],
				'target' => [],
			],
			'p'      => [],
		];

		$content = '<h2>' . esc_html__( 'Cetus Image Converter & AI Alt Text — Error Telemetry', 'cetus-media-optimizer' ) . '</h2>';

		$content .= '<p>' . wp_kses(
			__(
				'If the site administrator has enabled telemetry in the settings of <strong>Cetus Image Converter & AI Alt Text</strong>, the plugin sends anonymous PHP error reports to the <a href="https://sentry.io" rel="noopener noreferrer" target="_blank">Sentry</a> service (sentry.io).',
				'cetus-media-optimizer'
			),
			$allowed_tags
		) . '</p>';

		$content .= '<p>' . esc_html__(
			'The transmitted data includes exclusively: PHP error type, stack trace limited to plugin files, WordPress version, PHP version and plugin version. No IP address, username, site content or personal data is ever transmitted.',
			'cetus-media-optimizer'
		) . '</p>';

		$content .= '<p>' . esc_html__(
			'This feature is disabled by default and requires explicit administrator consent. It can be disabled at any time from Cetus Media → Preferences → Telemetry.',
			'cetus-media-optimizer'
		) . '</p>';

		$content .= '<p>' . wp_kses(
			sprintf(
				/* translators: %s: URL of Sentry privacy policy */
				__( 'For more information see the <a href="%s" rel="noopener noreferrer" target="_blank">Sentry Privacy Policy</a>.', 'cetus-media-optimizer' ),
				'https://sentry.io/privacy/'
			),
			$allowed_tags
		) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Cetus Image Converter & AI Alt Text', 'cetus-media-optimizer' ),
			$content
		);
	}
}
