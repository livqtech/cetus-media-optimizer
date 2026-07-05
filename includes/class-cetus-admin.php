<?php
/**
 * Pannello di controllo amministrativo per Cetus Image Converter & AI Alt Text.
 *
 * Utilizza tab nativi WordPress (nav-tab-wrapper) con URL separati per ciascuna sezione:
 *   ?tab=diagnosi   — Diagnosi Server
 *   ?tab=preferenze — Preferenze
 *   ?tab=libreria   — Gestione Libreria
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_Admin' ) ) {
	return;
}

/**
 * Class Cetus_MO_Admin
 */
class Cetus_MO_Admin {

	/**
	 * Slug della pagina di impostazioni.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'cetus-media-optimizer';

	/**
	 * Nome del gruppo di opzioni per register_setting().
	 *
	 * @var string
	 */
	private const OPTIONS_GROUP = 'cetus_media_options';

	// -------------------------------------------------------------------------
	// Inizializzazione
	// -------------------------------------------------------------------------

	/**
	 * Registra tutti gli hook necessari per il pannello admin.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_attachment_meta_box' ] );
		add_action( 'edit_attachment', [ $this, 'save_attachment_exclusion_meta' ] );
		add_action( 'wp_ajax_cetus_mo_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_cetus_mo_validate_key', [ $this, 'ajax_validate_key' ] );
		add_action( 'wp_ajax_cetus_mo_clear_log', [ $this, 'ajax_clear_log' ] );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	/**
	 * Aggiunge la voce di menu nel pannello di amministrazione.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Cetus Image Converter & AI Alt Text', 'cetus-media-optimizer' ),
			__( 'Cetus Media', 'cetus-media-optimizer' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-images-alt2',
			58
		);
	}

	// -------------------------------------------------------------------------
	// Impostazioni
	// -------------------------------------------------------------------------

	/**
	 * Registra le opzioni tramite l'API Settings di WordPress.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$options = [
			'cetus_media_format'              => [ $this, 'sanitize_format' ],
			'cetus_media_auto_convert'        => [ $this, 'sanitize_checkbox' ],
			'cetus_media_webp_quality'        => [ $this, 'sanitize_quality' ],
			'cetus_media_avif_quality'        => [ $this, 'sanitize_quality' ],
			'cetus_media_ai_provider'         => [ $this, 'sanitize_ai_provider' ],
			'cetus_media_ai_fallback'         => [ $this, 'sanitize_checkbox' ],
			'cetus_media_gemini_key'          => [ $this, 'sanitize_api_key' ],
			'cetus_media_openai_key'          => [ $this, 'sanitize_api_key' ],
			'cetus_media_alt_text_language'   => [ $this, 'sanitize_language' ],
			'cetus_media_alt_text_prompt'     => 'sanitize_textarea_field',
			'cetus_media_cron_enabled'        => [ $this, 'sanitize_checkbox' ],
			'cetus_media_telemetry_opt_in'    => [ $this, 'sanitize_checkbox' ],
			'cetus_media_delete_on_uninstall' => [ $this, 'sanitize_checkbox' ],
		];

		foreach ( $options as $option => $callback ) {
			register_setting( self::OPTIONS_GROUP, $option, [ 'sanitize_callback' => $callback ] );
		}
	}

	/**
	 * Sanitizza un valore checkbox: accetta solo '0' o '1'.
	 *
	 * @param mixed $value Valore grezzo.
	 * @return string '1' | '0'
	 */
	public function sanitize_checkbox( mixed $value ): string {
		return ( '1' === (string) $value || 'on' === (string) $value ) ? '1' : '0';
	}

	/**
	 * Sanitizza una chiave API rimuovendo spazi e caratteri non ASCII.
	 *
	 * @param mixed $value Valore grezzo.
	 * @return string
	 */
	public function sanitize_api_key( mixed $value ): string {
		return sanitize_text_field( trim( (string) $value ) );
	}

	/**
	 * Sanitizza il valore di qualità immagine: intero tra 1 e 100.
	 *
	 * @param mixed $value Valore grezzo.
	 * @return int
	 */
	public function sanitize_quality( mixed $value ): int {
		return min( 100, max( 1, (int) $value ) );
	}

	/**
	 * Sanitizza il formato di output: accetta solo i valori consentiti.
	 *
	 * @param mixed $value Valore grezzo.
	 * @return string
	 */
	public function sanitize_format( mixed $value ): string {
		$allowed = [ 'auto', 'avif', 'webp', 'none' ];
		$value   = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'auto';
	}

	/**
	 * Sanitizza il provider AI: accetta solo i valori consentiti.
	 *
	 * @param mixed $value Valore grezzo.
	 * @return string
	 */
	public function sanitize_ai_provider( mixed $value ): string {
		$allowed = [ 'gemini', 'openai' ];
		$value   = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'gemini';
	}

	/**
	 * Sanitizza il codice lingua per l'alt text: stringa vuota o tag BCP-47 valido.
	 *
	 * @param mixed $value Valore grezzo.
	 * @return string
	 */
	public function sanitize_language( mixed $value ): string {
		$value = sanitize_text_field( trim( (string) $value ) );
		// Accetta stringa vuota (auto-detect) o tag lingua BCP-47 (es. "it", "en-US").
		if ( '' === $value || preg_match( '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $value ) ) {
			return $value;
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Asset
	// -------------------------------------------------------------------------

	/**
	 * Carica CSS e JavaScript inline solo nella pagina del plugin.
	 *
	 * @param string $hook Slug della pagina admin corrente.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue lo script jQuery (già incluso in WordPress).
		wp_enqueue_script( 'jquery' );

		// Localizza le variabili JS necessarie.
		wp_add_inline_script(
			'jquery',
			'window.CetusMO = ' . wp_json_encode(
				[
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'cetus_mo_bulk_nonce' ),
					'save_nonce' => wp_create_nonce( 'cetus_mo_save_nonce' ),
					'i18n'       => [
						'start'              => __( 'Start Optimization', 'cetus-media-optimizer' ),
						'pause'              => __( 'Pause', 'cetus-media-optimizer' ),
						'resume'             => __( 'Resume', 'cetus-media-optimizer' ),
						'stop'               => __( 'Stop', 'cetus-media-optimizer' ),
						'scanning'           => __( 'Scanning…', 'cetus-media-optimizer' ),
						'processing'         => __( 'Processing…', 'cetus-media-optimizer' ),
						'done'               => __( 'Done!', 'cetus-media-optimizer' ),
						'error'              => __( 'Server communication error.', 'cetus-media-optimizer' ),
						'confirm_orphan'     => __( 'Are you sure you want to optimize the orphan files found?', 'cetus-media-optimizer' ),
						'saved'              => __( 'Settings saved.', 'cetus-media-optimizer' ),
						'key_valid'          => __( 'Valid key format.', 'cetus-media-optimizer' ),
						'key_invalid'        => __( 'Invalid key format.', 'cetus-media-optimizer' ),
						'converted_label'    => __( 'converted', 'cetus-media-optimizer' ),
						'errors_label'       => __( 'errors', 'cetus-media-optimizer' ),
						'orphans_done'       => __( 'orphan files optimized', 'cetus-media-optimizer' ),
						'confirm_reset'      => __( 'Are you sure? All WebP/AVIF files generated by the plugin will be deleted. Original files remain intact.', 'cetus-media-optimizer' ),
						'confirm_clear_log'  => __( 'Clear the conversion log?', 'cetus-media-optimizer' ),
						'verify'             => __( 'Verify', 'cetus-media-optimizer' ),
						'process_stopped'    => __( 'Process stopped.', 'cetus-media-optimizer' ),
						'estimated_time'     => __( 'Estimated time:', 'cetus-media-optimizer' ),
						'converted_stat'     => __( 'Converted:', 'cetus-media-optimizer' ),
						'skipped_stat'       => __( 'Skipped:', 'cetus-media-optimizer' ),
						'errors_stat'        => __( 'Errors:', 'cetus-media-optimizer' ),
						'generated_stat'     => __( 'Generated:', 'cetus-media-optimizer' ),
						'already_present'    => __( 'Already present (skipped):', 'cetus-media-optimizer' ),
						'no_conversions'     => __( 'No conversions recorded yet.', 'cetus-media-optimizer' ),
						'estimating'         => __( 'Estimating time…', 'cetus-media-optimizer' ),
						'no_orphans'         => __( 'No orphan files found.', 'cetus-media-optimizer' ),
						'orphan_already_opt' => __( 'All orphan files are already in the target format — nothing to convert.', 'cetus-media-optimizer' ),
						'orphan_stopped'     => __( 'Orphan optimization stopped.', 'cetus-media-optimizer' ),
						'scan_orphans_btn'   => __( 'Scan Orphan Files', 'cetus-media-optimizer' ),
					],
				]
			) . ';'
		);

		// CSS inline per il pannello.
		wp_register_style( 'cetus-mo-admin', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'cetus-mo-admin' );
		wp_add_inline_style( 'cetus-mo-admin', $this->get_inline_css() );

		// JS inline per la logica del pannello.
		wp_register_script( 'cetus-mo-admin', false, [ 'jquery' ], CETUS_MO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
		wp_enqueue_script( 'cetus-mo-admin' );
		wp_add_inline_script( 'cetus-mo-admin', $this->get_inline_js() );
	}

	// -------------------------------------------------------------------------
	// Render principale
	// -------------------------------------------------------------------------

	/**
	 * Esegue il rendering dell'intera pagina di impostazioni.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'cetus-media-optimizer' ) );
		}
		$optimizer = new Cetus_MO_Optimizer();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading tab for navigation only, no data is processed
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'diagnosi';
		$page_url   = admin_url( 'admin.php?page=cetus-media-optimizer' );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<?php esc_html_e( 'Cetus Image Converter & AI Alt Text', 'cetus-media-optimizer' ); ?>
				<span style="font-size:13px;font-weight:400;color:#646970;vertical-align:middle;">
					v<?php echo esc_html( CETUS_MO_VERSION ); ?>
				</span>
				<span style="font-size:13px;font-weight:400;margin-left:4px;">
					<a href="https://wordpress.org/plugins/cetus-media-optimizer/" target="_blank" rel="noopener" style="text-decoration:none;color:#646970;" title="<?php esc_attr_e( 'Documentation', 'cetus-media-optimizer' ); ?>">
						<?php esc_html_e( 'Docs', 'cetus-media-optimizer' ); ?>
					</a>
					<span style="color:#c3c4c7;margin:0 6px;">·</span>
					<a href="https://wordpress.org/support/plugin/cetus-media-optimizer/" target="_blank" rel="noopener" style="text-decoration:none;color:#646970;" title="<?php esc_attr_e( 'WordPress.org support forum', 'cetus-media-optimizer' ); ?>">
						<?php esc_html_e( 'Support', 'cetus-media-optimizer' ); ?>
					</a>
					<span style="color:#c3c4c7;margin:0 6px;">·</span>
					<a href="https://github.com/livqtech/cetus-media-optimizer/issues" target="_blank" rel="noopener" style="text-decoration:none;color:#646970;" title="<?php esc_attr_e( 'GitHub Issues', 'cetus-media-optimizer' ); ?>">
						<?php esc_html_e( 'GitHub', 'cetus-media-optimizer' ); ?>
					</a>
					<span style="color:#c3c4c7;margin:0 6px;">·</span>
					<a href="<?php echo esc_url( 'mailto:support@livq.it?subject=' . rawurlencode( 'Cetus Image Converter & AI Alt Text v' . CETUS_MO_VERSION . ' – Support – ' . ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' ) ) ); ?>" style="text-decoration:none;color:#646970;" title="<?php esc_attr_e( 'Email support', 'cetus-media-optimizer' ); ?>">
						<?php esc_html_e( 'Email', 'cetus-media-optimizer' ); ?>
					</a>
				</span>
			</h1>

			<div id="cmo-notice-wrap"></div>

			<nav class="nav-tab-wrapper wp-clearfix" id="cmo-tab-nav">
				<a href="<?php echo esc_url( $page_url . '&tab=diagnosi' ); ?>"
					class="nav-tab <?php echo 'diagnosi' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-tools" style="vertical-align:middle;margin-right:4px;"></span>
					<?php esc_html_e( 'Server Diagnosis', 'cetus-media-optimizer' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=preferenze' ); ?>"
					class="nav-tab <?php echo 'preferenze' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-settings" style="vertical-align:middle;margin-right:4px;"></span>
					<?php esc_html_e( 'Preferences', 'cetus-media-optimizer' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=libreria' ); ?>"
					class="nav-tab <?php echo 'libreria' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-images-alt2" style="vertical-align:middle;margin-right:4px;"></span>
					<?php esc_html_e( 'Library Management', 'cetus-media-optimizer' ); ?>
				</a>
			</nav>

			<div class="wrap" style="margin-top:0;">
				<?php if ( 'diagnosi' === $active_tab ) : ?>
					<?php $this->render_step1( $optimizer ); ?>
				<?php elseif ( 'preferenze' === $active_tab ) : ?>
					<form id="cmo-settings-form" method="post" action="options.php">
						<?php settings_fields( self::OPTIONS_GROUP ); ?>
						<?php $this->render_step2(); ?>
						<?php $this->render_telemetry(); ?>
						<?php $this->render_uninstall_section(); ?>
					</form>
				<?php elseif ( 'libreria' === $active_tab ) : ?>
					<?php $this->render_step3( $optimizer ); ?>
				<?php endif; ?>
			</div>

			<?php $this->render_review_box(); ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Step 1: Diagnosi Server
	// -------------------------------------------------------------------------

	/**
	 * Render del pannello di diagnosi server.
	 *
	 * @param Cetus_MO_Optimizer $optimizer Istanza ottimizzatore per i check.
	 * @return void
	 */
	private function render_step1( Cetus_MO_Optimizer $optimizer ): void {
		$checks   = $this->run_server_diagnostics( $optimizer );
		$all_ok   = ! in_array( false, array_column( $checks, 'ok' ), true );
		$page_url = admin_url( 'admin.php?page=cetus-media-optimizer' );
		?>
		<div style="margin-top:20px;">
			<?php if ( $all_ok ) : ?>
			<div class="notice notice-success inline">
				<p><span class="dashicons dashicons-yes-alt" style="vertical-align:middle;margin-right:6px;"></span>
				<strong><?php esc_html_e( 'Server correctly configured', 'cetus-media-optimizer' ); ?></strong> — <?php esc_html_e( 'all required libraries are available.', 'cetus-media-optimizer' ); ?></p>
			</div>
			<?php else : ?>
			<div class="notice notice-warning inline">
				<p><span class="dashicons dashicons-warning" style="vertical-align:middle;margin-right:6px;"></span>
				<strong><?php esc_html_e( 'Partial configuration', 'cetus-media-optimizer' ); ?></strong> — <?php esc_html_e( 'some features may not be available.', 'cetus-media-optimizer' ); ?></p>
			</div>
			<?php endif; ?>

			<table class="widefat striped" style="margin-top:16px;">
				<thead>
					<tr>
						<th style="width:32px;"></th>
						<th><?php esc_html_e( 'Component', 'cetus-media-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Status', 'cetus-media-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'cetus-media-optimizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td style="text-align:center;">
							<?php if ( $check['ok'] ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
							<?php else : ?>
								<span class="dashicons dashicons-warning" style="color:#d63638;"></span>
							<?php endif; ?>
						</td>
						<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
						<td><?php echo esc_html( $check['status'] ); ?></td>
						<td><em><?php echo esc_html( $check['note'] ); ?></em></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:16px;">
				<a href="<?php echo esc_url( $page_url . '&tab=preferenze' ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Preferences →', 'cetus-media-optimizer' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Esegue i check diagnostici del server e restituisce i risultati.
	 *
	 * @param Cetus_MO_Optimizer $optimizer Istanza da usare per i check.
	 * @return array<array{label: string, ok: bool, status: string, note: string}>
	 */
	private function run_server_diagnostics( Cetus_MO_Optimizer $optimizer ): array {
		$php_version = PHP_VERSION;
		$php_ok      = version_compare( $php_version, '8.0.0', '>=' );

		$imagick_loaded        = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$gd_loaded             = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$imagick_webp          = $imagick_loaded && $optimizer->imagick_available( 'webp' );
		$imagick_avif_declared = $imagick_loaded && in_array( 'AVIF', Imagick::queryFormats(), true );
		$imagick_avif          = $imagick_loaded && $optimizer->imagick_available( 'avif' ); // usa il probe reale.
		$gd_webp               = $gd_loaded && $optimizer->gd_webp_available();
		$gd_avif               = $gd_loaded && $optimizer->gd_avif_available();

		return [
			[
				'label'  => __( 'PHP Version', 'cetus-media-optimizer' ),
				'ok'     => $php_ok,
				'status' => $php_version,
				'note'   => $php_ok ? '' : __( 'Requires PHP 8.0 or higher.', 'cetus-media-optimizer' ),
			],
			[
				'label'  => __( 'Imagick Extension', 'cetus-media-optimizer' ),
				'ok'     => $imagick_loaded,
				'status' => $imagick_loaded
					/* translators: %s: Imagick extension version number, e.g. "3.7.0" */
					? sprintf( __( 'Available (v%s)', 'cetus-media-optimizer' ), phpversion( 'imagick' ) ?: 'n/a' )
					: __( 'Not available', 'cetus-media-optimizer' ),
				'note'   => $imagick_loaded ? '' : __( 'Contact your hosting provider to enable Imagick.', 'cetus-media-optimizer' ),
			],
			[
				'label'  => __( 'GD Extension', 'cetus-media-optimizer' ),
				'ok'     => $gd_loaded,
				'status' => $gd_loaded ? __( 'Available', 'cetus-media-optimizer' ) : __( 'Not available', 'cetus-media-optimizer' ),
				'note'   => '',
			],
			[
				'label'  => __( 'WebP Support (Imagick)', 'cetus-media-optimizer' ),
				'ok'     => $imagick_webp,
				'status' => $imagick_webp ? __( 'Supported', 'cetus-media-optimizer' ) : __( 'Not supported', 'cetus-media-optimizer' ),
				'note'   => ! $imagick_webp && $gd_webp ? __( 'Available via GD as fallback.', 'cetus-media-optimizer' ) : '',
			],
			[
				'label'  => __( 'WebP Support (GD)', 'cetus-media-optimizer' ),
				'ok'     => $gd_webp,
				'status' => $gd_webp ? __( 'Supported', 'cetus-media-optimizer' ) : __( 'Not supported', 'cetus-media-optimizer' ),
				'note'   => '',
			],
			[
				'label'  => __( 'AVIF Support (Imagick)', 'cetus-media-optimizer' ),
				'ok'     => $imagick_avif,
				'status' => $imagick_avif ? __( 'Supported', 'cetus-media-optimizer' ) : __( 'Not supported', 'cetus-media-optimizer' ),
				'note'   => $imagick_avif
					? ''
					: ( $imagick_avif_declared
						? __( 'Imagick declares AVIF support but real encoding produces empty files (libheif not working). The plugin will fall back to WebP.', 'cetus-media-optimizer' )
						: __( 'Requires libheif/libavif compiled with Imagick.', 'cetus-media-optimizer' )
					),
			],
			[
				'label'  => __( 'AVIF Support (GD)', 'cetus-media-optimizer' ),
				'ok'     => $gd_avif,
				'status' => $gd_avif ? __( 'Supported', 'cetus-media-optimizer' ) : __( 'Not supported', 'cetus-media-optimizer' ),
				'note'   => $gd_avif ? '' : __( 'Requires PHP ≥ 8.1 with libavif.', 'cetus-media-optimizer' ),
			],
		];
	}

	// -------------------------------------------------------------------------
	// Step 2: Preferenze
	// -------------------------------------------------------------------------

	/**
	 * Render del pannello delle preferenze.
	 *
	 * @return void
	 */
	private function render_step2(): void {
		$format       = get_option( 'cetus_media_format', 'auto' );
		$auto_convert = get_option( 'cetus_media_auto_convert', '0' );
		$ai_provider  = get_option( 'cetus_media_ai_provider', 'gemini' );
		$ai_fallback  = get_option( 'cetus_media_ai_fallback', '1' );
		$gemini_key   = get_option( 'cetus_media_gemini_key', '' );
		$openai_key   = get_option( 'cetus_media_openai_key', '' );
		$gemini_set   = '' !== $gemini_key;
		$openai_set   = '' !== $openai_key;
		$ai_active    = $gemini_set || $openai_set;
		$page_url     = admin_url( 'admin.php?page=cetus-media-optimizer' );

		$format_options = [
			'auto' => __( 'Automatic (AVIF if available, otherwise WebP)', 'cetus-media-optimizer' ),
			'avif' => __( 'AVIF only', 'cetus-media-optimizer' ),
			'webp' => __( 'WebP only', 'cetus-media-optimizer' ),
			'none' => __( 'No conversion (Alt Text AI only)', 'cetus-media-optimizer' ),
		];

		$lang_options = [
			''   => __( 'Automatic (from WordPress locale)', 'cetus-media-optimizer' ),
			'it' => 'Italiano',
			'en' => 'English',
			'es' => 'Español',
			'fr' => 'Français',
			'de' => 'Deutsch',
			'pt' => 'Português',
			'nl' => 'Nederlands',
			'ru' => 'Русский',
			'pl' => 'Polski',
			'sv' => 'Svenska',
			'da' => 'Dansk',
			'fi' => 'Suomi',
			'nb' => 'Norsk',
			'tr' => 'Türkçe',
			'cs' => 'Čeština',
			'ro' => 'Română',
			'hu' => 'Magyar',
			'el' => 'Ελληνικά',
			'ar' => 'العربية',
			'ko' => '한국어',
			'ja' => '日本語',
			'zh' => '中文',
		];
		$current_lang = get_option( 'cetus_media_alt_text_language', '' );
		?>

		<?php if ( $ai_active ) : ?>
		<div class="notice notice-success inline" style="margin-top:20px;">
			<p><span class="dashicons dashicons-yes-alt" style="vertical-align:middle;margin-right:6px;"></span>
			<?php esc_html_e( 'API key active: Alt Text will be generated automatically on every new upload.', 'cetus-media-optimizer' ); ?></p>
		</div>
		<?php else : ?>
		<div class="notice notice-info inline" style="margin-top:20px;">
			<p><span class="dashicons dashicons-info-outline" style="vertical-align:middle;margin-right:6px;"></span>
			<?php esc_html_e( 'Enter at least one API key to enable automatic Alt Text generation.', 'cetus-media-optimizer' ); ?></p>
		</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Output Format', 'cetus-media-optimizer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Conversion format', 'cetus-media-optimizer' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Conversion format', 'cetus-media-optimizer' ); ?></legend>
						<?php foreach ( $format_options as $value => $label ) : ?>
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="cetus_media_format" value="<?php echo esc_attr( $value ); ?>"
								<?php checked( $format, $value ); ?> id="cmo_format_<?php echo esc_attr( $value ); ?>">
							<?php echo esc_html( $label ); ?>
						</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( '"Automatic" selects AVIF if the server supports it, WebP as fallback.', 'cetus-media-optimizer' ); ?></p>
					</fieldset>
				</td>
			</tr>
			<tr id="cmo-row-auto-convert">
				<th scope="row"><?php esc_html_e( 'New Uploads', 'cetus-media-optimizer' ); ?></th>
				<td>
					<label id="cmo-label-auto-convert"<?php echo ( 'none' === $format ? ' style="opacity:.5;"' : '' ); ?>>
						<input type="checkbox" id="cmo_auto_convert" name="cetus_media_auto_convert" value="1"
							<?php checked( $auto_convert, '1' ); ?>
							<?php echo ( 'none' === $format ? ' disabled' : '' ); ?>>
						<?php esc_html_e( 'Automatically convert new images on upload', 'cetus-media-optimizer' ); ?>
					</label>
					<p class="description" id="cmo-auto-convert-note"<?php echo ( 'none' !== $format ? ' style="display:none;"' : '' ); ?>>
						<?php esc_html_e( 'Not available when format is set to "No conversion".', 'cetus-media-optimizer' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Conversion Quality', 'cetus-media-optimizer' ); ?></h2>
		<table class="form-table" role="presentation" id="cmo-quality-group" <?php echo ( 'none' === $format ? 'style="display:none;"' : '' ); ?>>
			<tr>
				<th scope="row"><label for="cmo_webp_quality">WebP</label></th>
				<td>
					<input type="range" id="cmo_webp_quality" name="cetus_media_webp_quality"
						min="1" max="100" class="cmo-quality-slider"
						value="<?php echo esc_attr( get_option( 'cetus_media_webp_quality', '82' ) ); ?>">
					<span class="cmo-quality-value" id="cmo_webp_quality_val">
						<?php echo esc_html( get_option( 'cetus_media_webp_quality', '82' ) ); ?>
					</span>
					<p class="description"><?php esc_html_e( 'Higher values = better quality but larger files (default: 82).', 'cetus-media-optimizer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cmo_avif_quality">AVIF</label></th>
				<td>
					<input type="range" id="cmo_avif_quality" name="cetus_media_avif_quality"
						min="1" max="100" class="cmo-quality-slider"
						value="<?php echo esc_attr( get_option( 'cetus_media_avif_quality', '75' ) ); ?>">
					<span class="cmo-quality-value" id="cmo_avif_quality_val">
						<?php echo esc_html( get_option( 'cetus_media_avif_quality', '75' ) ); ?>
					</span>
					<p class="description"><?php esc_html_e( 'Higher values = better quality but larger files (default: 75).', 'cetus-media-optimizer' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'AI Assistant – Alt Text Generation', 'cetus-media-optimizer' ); ?></h2>
		<div class="notice notice-warning inline">
			<p><span class="dashicons dashicons-warning" style="vertical-align:middle;margin-right:6px;"></span>
			<?php esc_html_e( 'Monitor your API key quota limits. High usage can generate "Quota Exceeded" errors (429).', 'cetus-media-optimizer' ); ?></p>
		</div>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Preferred AI provider', 'cetus-media-optimizer' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'AI provider', 'cetus-media-optimizer' ); ?></legend>
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="cetus_media_ai_provider" value="gemini" <?php checked( $ai_provider, 'gemini' ); ?>>
							<?php esc_html_e( 'Google Gemini (gemini-1.5-flash)', 'cetus-media-optimizer' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="cetus_media_ai_provider" value="openai" <?php checked( $ai_provider, 'openai' ); ?>>
							<?php esc_html_e( 'OpenAI (gpt-4o-mini)', 'cetus-media-optimizer' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic fallback', 'cetus-media-optimizer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="cetus_media_ai_fallback" value="1" <?php checked( $ai_fallback, '1' ); ?>>
						<?php esc_html_e( 'Enable automatic fallback to the other provider on 429 errors', 'cetus-media-optimizer' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cmo_gemini_key"><?php esc_html_e( 'Google Gemini API Key', 'cetus-media-optimizer' ); ?></label></th>
				<td>
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<input type="password" id="cmo_gemini_key" name="cetus_media_gemini_key"
							value="<?php echo $gemini_set ? esc_attr( str_repeat( '•', 16 ) ) : ''; ?>"
							placeholder="<?php echo $gemini_set ? '' : esc_attr( 'AIzaSy…' ); ?>" class="regular-text cmo-key-input" autocomplete="new-password">
						<button type="button" class="button cmo-validate-key" data-provider="gemini" data-input="cmo_gemini_key">
							<?php esc_html_e( 'Verify', 'cetus-media-optimizer' ); ?>
						</button>
						<span class="cmo-key-status" id="cmo_gemini_key_status"></span>
					</div>
					<p class="description"><?php esc_html_e( 'Expected format: AIzaSy… (39 characters). Get a key from Google AI Studio.', 'cetus-media-optimizer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cmo_openai_key"><?php esc_html_e( 'OpenAI API Key', 'cetus-media-optimizer' ); ?></label></th>
				<td>
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<input type="password" id="cmo_openai_key" name="cetus_media_openai_key"
							value="<?php echo $openai_set ? esc_attr( str_repeat( '•', 16 ) ) : ''; ?>"
							placeholder="<?php echo $openai_set ? '' : esc_attr( 'sk-…' ); ?>" class="regular-text cmo-key-input" autocomplete="new-password">
						<button type="button" class="button cmo-validate-key" data-provider="openai" data-input="cmo_openai_key">
							<?php esc_html_e( 'Verify', 'cetus-media-optimizer' ); ?>
						</button>
						<span class="cmo-key-status" id="cmo_openai_key_status"></span>
					</div>
					<p class="description"><?php esc_html_e( 'Expected format: sk-… Get a key from platform.openai.com.', 'cetus-media-optimizer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cmo_alt_text_language"><?php esc_html_e( 'Alt Text AI Language', 'cetus-media-optimizer' ); ?></label></th>
				<td>
					<select id="cmo_alt_text_language" name="cetus_media_alt_text_language">
						<?php foreach ( $lang_options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_lang, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: current WordPress locale code, e.g. it_IT */
								__( 'With "Automatic" the current WordPress locale is used (%s).', 'cetus-media-optimizer' ),
								'<code>' . esc_html( get_locale() ) . '</code>'
							),
							[ 'code' => [] ]
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cmo_alt_text_prompt"><?php esc_html_e( 'Custom prompt', 'cetus-media-optimizer' ); ?></label></th>
				<td>
					<textarea id="cmo_alt_text_prompt" name="cetus_media_alt_text_prompt"
						rows="3" class="large-text"
						placeholder="<?php esc_attr_e( 'Leave empty to use the default prompt.', 'cetus-media-optimizer' ); ?>"
					><?php echo esc_textarea( get_option( 'cetus_media_alt_text_prompt', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional — overrides the language and default prompt. Example: "Describe this image in English in one sentence."', 'cetus-media-optimizer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Background (WP-Cron)', 'cetus-media-optimizer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="cetus_media_cron_enabled" value="1"
							<?php checked( get_option( 'cetus_media_cron_enabled', '0' ), '1' ); ?>>
						<?php esc_html_e( 'Process the library in the background via WP-Cron (every 5 min) without keeping the browser open', 'cetus-media-optimizer' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Ideal for very large libraries or hosting with strict timeouts.', 'cetus-media-optimizer' ); ?></p>
				</td>
			</tr>
		</table>

		<?php
		submit_button( __( 'Save Preferences', 'cetus-media-optimizer' ), 'primary', 'submit', true, [ 'id' => 'cmo-save-btn' ] );
		?>

		<p>
			<a href="<?php echo esc_url( $page_url . '&tab=libreria' ); ?>" class="button">
				<?php esc_html_e( 'Go to Library Management →', 'cetus-media-optimizer' ); ?>
			</a>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Step 3: Gestione Libreria
	// -------------------------------------------------------------------------

	/**
	 * Render del pannello di gestione della libreria.
	 *
	 * @param Cetus_MO_Optimizer $optimizer Istanza ottimizzatore per le statistiche.
	 * @return void
	 */
	private function render_step3( Cetus_MO_Optimizer $optimizer ): void {
		$stats = $optimizer->get_library_stats();

		// Totale immagini nella libreria media.
		$total_images_query = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		$total_images       = count( $total_images_query->posts );

		// Immagini non ancora convertite nel formato di output corrente.
		$format        = get_option( 'cetus_media_format', 'auto' );
		$not_converted = 0;
		if ( 'none' !== $format ) {
			$not_converted = $optimizer->count_unconverted_images();
		}
		?>
		<div style="margin-top:20px;">
			<h2><?php esc_html_e( 'Library Statistics', 'cetus-media-optimizer' ); ?></h2>

			<table class="widefat fixed striped" style="margin-bottom:24px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Images in library', 'cetus-media-optimizer' ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $total_images ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'To convert', 'cetus-media-optimizer' ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $not_converted ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Total space', 'cetus-media-optimizer' ); ?></strong></td>
						<td><?php echo esc_html( $this->format_bytes( $stats['total_bytes'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Original JPG/PNG/GIF', 'cetus-media-optimizer' ); ?></strong></td>
						<td><?php echo esc_html( $this->format_bytes( $stats['original_bytes'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WebP files', 'cetus-media-optimizer' ); ?></strong></td>
						<td><?php echo esc_html( $this->format_bytes( $stats['webp_bytes'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'AVIF files', 'cetus-media-optimizer' ); ?></strong></td>
						<td><?php echo esc_html( $this->format_bytes( $stats['avif_bytes'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Current session savings', 'cetus-media-optimizer' ); ?></strong></td>
						<td id="cmo-bytes-saved">–</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Cumulative total savings', 'cetus-media-optimizer' ); ?></strong></td>
						<td><?php echo esc_html( $this->format_bytes( (int) get_option( 'cetus_mo_total_bytes_saved', 0 ) ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<!-- Barra progressione -->
			<div class="cmo-progress-wrap" id="cmo-progress-wrap" style="display:none;">
				<div class="cmo-progress-bar-track">
					<div class="cmo-progress-bar-fill" id="cmo-progress-bar" style="width:0%"></div>
				</div>
				<div class="cmo-progress-info">
					<span id="cmo-progress-text">0 / 0</span>
					<span id="cmo-progress-percent">0%</span>
				</div>
				<div class="cmo-progress-meta">
					<span id="cmo-progress-speed"></span>
					<span id="cmo-progress-eta"></span>
				</div>
				<div class="cmo-progress-detail" id="cmo-progress-detail"></div>
			</div>

			<!-- Pulsanti controllo batch -->
			<p>
				<button type="button" id="cmo-btn-start" class="button button-primary button-hero">
					<span class="dashicons dashicons-controls-play" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Start Library Optimization', 'cetus-media-optimizer' ); ?>
				</button>
				<button type="button" id="cmo-btn-pause" class="button button-large" style="display:none;">
					<span class="dashicons dashicons-controls-pause" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Pause', 'cetus-media-optimizer' ); ?>
				</button>
				<button type="button" id="cmo-btn-resume" class="button button-large" style="display:none;">
					<span class="dashicons dashicons-controls-play" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Resume', 'cetus-media-optimizer' ); ?>
				</button>
				<button type="button" id="cmo-btn-stop" class="button button-large" style="display:none;">
					<span class="dashicons dashicons-controls-stop" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Stop', 'cetus-media-optimizer' ); ?>
				</button>
			</p>

			<!-- Log errori ottimizzazione libreria -->
			<div id="cmo-bulk-errors-wrap" style="display:none; margin-top:12px;">
				<h4 style="margin:0 0 6px;"><?php esc_html_e( 'Conversion errors', 'cetus-media-optimizer' ); ?></h4>
				<div id="cmo-bulk-errors-log" class="cmo-error-log"></div>
			</div>

			<hr>

			<!-- File Orfani -->
			<h2><?php esc_html_e( 'Orphan File Analysis', 'cetus-media-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'Search for images in the uploads folder not registered in the WordPress database.', 'cetus-media-optimizer' ); ?></p>

			<p>
				<button type="button" id="cmo-btn-scan-orphans" class="button button-secondary">
					<span class="dashicons dashicons-search" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Scan Orphan Files', 'cetus-media-optimizer' ); ?>
				</button>
			</p>

			<div id="cmo-orphan-result" style="display:none; margin-top:16px;">
				<div id="cmo-orphan-info"></div>
				<p>
					<button type="button" id="cmo-btn-convert-orphans" class="button button-primary" style="display:none;">
						<span class="dashicons dashicons-controls-play" style="vertical-align:middle;"></span>
						<?php esc_html_e( 'Optimize orphan files found', 'cetus-media-optimizer' ); ?>
					</button>
					<button type="button" id="cmo-btn-orphan-pause" class="button button-large" style="display:none;">
						<span class="dashicons dashicons-controls-pause" style="vertical-align:middle;"></span>
						<?php esc_html_e( 'Pause', 'cetus-media-optimizer' ); ?>
					</button>
					<button type="button" id="cmo-btn-orphan-resume" class="button button-large" style="display:none;">
						<span class="dashicons dashicons-controls-play" style="vertical-align:middle;"></span>
						<?php esc_html_e( 'Resume', 'cetus-media-optimizer' ); ?>
					</button>
					<button type="button" id="cmo-btn-orphan-stop" class="button button-large" style="display:none;">
						<span class="dashicons dashicons-controls-stop" style="vertical-align:middle;"></span>
						<?php esc_html_e( 'Stop', 'cetus-media-optimizer' ); ?>
					</button>
				</p>
				<div id="cmo-orphan-progress-wrap" style="display:none; margin-top:12px;">
					<div class="cmo-progress-bar-outer" style="background:#e0e0e0;border-radius:4px;height:18px;overflow:hidden;">
						<div id="cmo-orphan-bar" style="height:100%;width:0%;background:#2271b1;transition:width .3s;"></div>
					</div>
					<p id="cmo-orphan-progress-text" style="margin:6px 0 0;font-size:13px;color:#666;">0%</p>
				</div>
				<!-- Log errori orfani -->
				<div id="cmo-orphan-errors-wrap" style="display:none; margin-top:12px;">
					<h4 style="margin:0 0 6px;"><?php esc_html_e( 'Conversion errors', 'cetus-media-optimizer' ); ?></h4>
					<div id="cmo-orphan-errors-log" class="cmo-error-log"></div>
				</div>
			</div>

			<hr>

			<!-- Generazione Alt Text in massa -->
			<h2><?php esc_html_e( 'Alt Text Generation', 'cetus-media-optimizer' ); ?></h2>
			<?php
			$has_ai_key = '' !== get_option( 'cetus_media_gemini_key', '' )
				|| '' !== get_option( 'cetus_media_openai_key', '' );
			if ( $has_ai_key ) :

				// Conta immagini senza alt text.
				$missing_alt_query = new WP_Query(
					[
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ],
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						[
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
						],
						[
						'key'     => '_wp_attachment_image_alt',
						'value'   => '',
						'compare' => '=',
						],
						],
					]
				);
				$missing_alt_count = count( $missing_alt_query->posts );

				$provider = get_option( 'cetus_media_ai_provider', 'gemini' );
				?>

				<?php if ( $missing_alt_count > 0 ) : ?>
			<div class="cmo-alttext-preview">
				<div class="cmo-alttext-preview__count">
					<span class="cmo-alttext-preview__num"><?php echo esc_html( number_format_i18n( $missing_alt_count ) ); ?></span>
					<span class="cmo-alttext-preview__label"><?php esc_html_e( 'images without Alt Text', 'cetus-media-optimizer' ); ?></span>
				</div>
				<p class="cmo-alttext-preview__note">
					<?php
					if ( 'openai' === $provider ) {
						/* translators: %s: URL of the OpenAI pricing page */
						$tpl = __( 'This operation will make API calls to OpenAI for each image. Check the <a href="%s" target="_blank" rel="noopener noreferrer">OpenAI pricing page</a> before proceeding.', 'cetus-media-optimizer' );
						printf(
							wp_kses(
								$tpl,
								[
									'a' => [
										'href'   => [],
										'target' => [],
										'rel'    => [],
									],
								]
							),
							'https://openai.com/api/pricing/'
						);
					} else {
						/* translators: %s: URL of the Google AI pricing page */
						$tpl = __( 'This operation will make API calls to Google Gemini for each image. Check the <a href="%s" target="_blank" rel="noopener noreferrer">Google AI pricing page</a> before proceeding.', 'cetus-media-optimizer' );
						printf(
							wp_kses(
								$tpl,
								[
									'a' => [
										'href'   => [],
										'target' => [],
										'rel'    => [],
									],
								]
							),
							'https://ai.google.dev/pricing'
						);
					}
					?>
				</p>
			</div>
			<?php else : ?>
			<div class="notice notice-success inline" style="margin-bottom:16px;">
				<p><span class="dashicons dashicons-yes-alt" style="vertical-align:middle;margin-right:6px;"></span>
				<?php esc_html_e( 'All images in the library already have an Alt Text.', 'cetus-media-optimizer' ); ?></p>
			</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Generate Alt Text for library images that do not have one yet. The process uses the AI provider configured in Preferences.', 'cetus-media-optimizer' ); ?></p>

			<p>
				<button type="button" id="cmo-btn-alttext" class="button button-secondary"<?php echo 0 === $missing_alt_count ? ' disabled' : ''; ?>>
					<span class="dashicons dashicons-edit-large" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Generate Alt Text for images without a description', 'cetus-media-optimizer' ); ?>
				</button>
			</p>

			<div id="cmo-alttext-progress" style="display:none; margin-top:16px;">
				<div class="cmo-progress-bar-track" style="margin-bottom:8px;">
					<div class="cmo-progress-bar-fill" id="cmo-alttext-bar" style="width:0%;background:linear-gradient(90deg,#6f42c1,#4a2c7a);"></div>
				</div>
				<div class="cmo-progress-info">
					<span id="cmo-alttext-text">0 / 0</span>
					<span id="cmo-alttext-percent">0%</span>
				</div>
				<div class="cmo-progress-detail" id="cmo-alttext-detail"></div>
			</div>
			<?php else : ?>
			<div class="notice notice-info inline">
				<p><span class="dashicons dashicons-info-outline" style="vertical-align:middle;margin-right:6px;"></span>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: link to Preferences tab */
						__( 'Configure at least one API key in %s to enable Alt Text generation.', 'cetus-media-optimizer' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=cetus-media-optimizer&tab=preferenze' ) ) . '">' . esc_html__( 'Preferences', 'cetus-media-optimizer' ) . '</a>'
					),
					[ 'a' => [ 'href' => [] ] ]
				);
				?>
				</p>
			</div>
			<?php endif; ?>

			<hr>

			<!-- Ripristino file originali -->
			<h2><?php esc_html_e( 'Restore Originals', 'cetus-media-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'Delete all WebP and AVIF files generated by the plugin. Original JPG/PNG/GIF files are never touched. Also resets the log and savings counter.', 'cetus-media-optimizer' ); ?></p>
			<p>
				<button type="button" id="cmo-btn-reset" class="button button-secondary cmo-btn-danger">
					<span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Delete all converted files', 'cetus-media-optimizer' ); ?>
				</button>
			</p>
			<div id="cmo-reset-result" style="display:none; margin-top:10px;"></div>

			<hr>

			<!-- Log conversioni -->
			<div id="cmo-log-section">
				<h2>
					<?php esc_html_e( 'Conversion Log', 'cetus-media-optimizer' ); ?>
					<button type="button" id="cmo-btn-clear-log" class="button button-small" style="margin-left:12px; font-size:11px;">
						<?php esc_html_e( 'Clear log', 'cetus-media-optimizer' ); ?>
					</button>
				</h2>
				<?php $this->render_log_table(); ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Footer: Telemetria
	// -------------------------------------------------------------------------

	/**
	 * Render della sezione telemetria (Opt-in, disabilitata di default).
	 *
	 * @return void
	 */
	private function render_telemetry(): void {
		$opt_in = get_option( 'cetus_media_telemetry_opt_in', '0' );
		?>
		<hr>
		<h3><?php esc_html_e( 'Telemetry', 'cetus-media-optimizer' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Anonymous crash reports', 'cetus-media-optimizer' ); ?></th>
				<td>
					<label for="cetus_media_telemetry_opt_in">
						<input type="checkbox" id="cetus_media_telemetry_opt_in" name="cetus_media_telemetry_opt_in" value="1" <?php checked( '1', get_option( 'cetus_media_telemetry_opt_in', '0' ) ); ?> />
						<?php esc_html_e( 'Allow Cetus to send anonymous crash reports to help us improve the plugin.', 'cetus-media-optimizer' ); ?>
					</label>
					<p class="description" style="margin-top: 10px;">
						<?php
						echo wp_kses(
							__( 'Error logs are fully anonymized and securely processed via Sentry. No IP address or personal data is ever collected. Read the <a href="https://sentry.io/privacy/" target="_blank" rel="noopener noreferrer">Sentry Privacy Policy</a>.', 'cetus-media-optimizer' ),
							[
								'a' => [
									'href'   => [],
									'target' => [],
									'rel'    => [],
								],
							]
						);
						?>
					</p>
					<p class="description" style="margin-top:6px;">
						<?php if ( '1' === $opt_in ) : ?>
							<span style="color:#46b450;">&#9679;</span>
							<?php esc_html_e( 'Active — reports sent to Sentry.', 'cetus-media-optimizer' ); ?>
						<?php else : ?>
							<span style="color:#dc3232;">&#9679;</span>
							<?php esc_html_e( 'Disabled.', 'cetus-media-optimizer' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
		submit_button( __( 'Save Preferences', 'cetus-media-optimizer' ), 'primary', 'submit', true, [ 'id' => 'cmo-save-btn-bottom' ] );
	}

	/**
	 * Render della sezione uninstall cleanup (Opt-in, disabilitata di default).
	 *
	 * @return void
	 */
	private function render_uninstall_section(): void {
		?>
		<hr>
		<h3><?php esc_html_e( 'Data & Privacy', 'cetus-media-optimizer' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Remove data on uninstall', 'cetus-media-optimizer' ); ?></th>
				<td>
					<label for="cetus_media_delete_on_uninstall">
						<input type="checkbox" id="cetus_media_delete_on_uninstall" name="cetus_media_delete_on_uninstall" value="1" <?php checked( '1', get_option( 'cetus_media_delete_on_uninstall', '0' ) ); ?> />
						<?php esc_html_e( 'Delete all plugin settings and data when the plugin is uninstalled.', 'cetus-media-optimizer' ); ?>
					</label>
					<p class="description" style="margin-top:8px;">
						<?php esc_html_e( 'When enabled, all plugin options and attachment metadata stored by Cetus will be permanently removed from the database upon uninstallation. Your original image files are never deleted.', 'cetus-media-optimizer' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Handler AJAX lato admin
	// -------------------------------------------------------------------------

	/**
	 * Salva le impostazioni tramite AJAX (chiamata dalla form con JS).
	 *
	 * @return void
	 */
	public function ajax_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'cetus-media-optimizer' ) ], 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cetus_mo_save_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'cetus-media-optimizer' ) ], 403 );
		}

		// Aggiorna ogni opzione con sanitizzazione dedicata.
		$format = isset( $_POST['cetus_media_format'] )
			? sanitize_text_field( wp_unslash( $_POST['cetus_media_format'] ) )
			: 'auto';
		update_option( 'cetus_media_format', in_array( $format, [ 'auto', 'avif', 'webp', 'none' ], true ) ? $format : 'auto' );

		// Invalida il probe AVIF cachato al cambio di preferenze.
		delete_transient( 'cetus_mo_avif_probe' );

		update_option( 'cetus_media_auto_convert', isset( $_POST['cetus_media_auto_convert'] ) ? '1' : '0' );

		$provider = isset( $_POST['cetus_media_ai_provider'] )
			? sanitize_text_field( wp_unslash( $_POST['cetus_media_ai_provider'] ) )
			: 'gemini';
		update_option( 'cetus_media_ai_provider', in_array( $provider, [ 'gemini', 'openai' ], true ) ? $provider : 'gemini' );

		update_option( 'cetus_media_ai_fallback', isset( $_POST['cetus_media_ai_fallback'] ) ? '1' : '0' );
		update_option( 'cetus_media_cron_enabled', isset( $_POST['cetus_media_cron_enabled'] ) ? '1' : '0' );
		update_option( 'cetus_media_telemetry_opt_in', isset( $_POST['cetus_media_telemetry_opt_in'] ) ? '1' : '0' );

		// Qualità (1-100).
		if ( isset( $_POST['cetus_media_webp_quality'] ) ) {
			update_option( 'cetus_media_webp_quality', (string) max( 1, min( 100, absint( wp_unslash( $_POST['cetus_media_webp_quality'] ) ) ) ) );
		}
		if ( isset( $_POST['cetus_media_avif_quality'] ) ) {
			update_option( 'cetus_media_avif_quality', (string) max( 1, min( 100, absint( wp_unslash( $_POST['cetus_media_avif_quality'] ) ) ) ) );
		}

		// Lingua e prompt AI.
		if ( isset( $_POST['cetus_media_alt_text_language'] ) ) {
			update_option( 'cetus_media_alt_text_language', sanitize_text_field( wp_unslash( $_POST['cetus_media_alt_text_language'] ) ) );
		}
		if ( isset( $_POST['cetus_media_alt_text_prompt'] ) ) {
			update_option( 'cetus_media_alt_text_prompt', sanitize_textarea_field( wp_unslash( $_POST['cetus_media_alt_text_prompt'] ) ) );
		}

		// Chiavi API: salva solo se non vuote (evita di sovrascrivere con stringa vuota).
		if ( isset( $_POST['cetus_media_gemini_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['cetus_media_gemini_key'] ) );
			if ( '' !== $key && str_repeat( '•', 16 ) !== $key ) {
				update_option( 'cetus_media_gemini_key', $key );
			}
		}

		if ( isset( $_POST['cetus_media_openai_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['cetus_media_openai_key'] ) );
			if ( '' !== $key && str_repeat( '•', 16 ) !== $key ) {
				update_option( 'cetus_media_openai_key', $key );
			}
		}

		self::update_htaccess_mime_types();

		wp_send_json_success( [ 'message' => __( 'Settings saved successfully.', 'cetus-media-optimizer' ) ] );
	}

	/**
	 * Aggiunge (o rimuove) le regole AddType per AVIF/WebP nell'htaccess di WordPress.
	 * Usa insert_with_markers per essere idempotente e reversibile.
	 *
	 * @return void
	 */
	public static function update_htaccess_mime_types(): void {
		$htaccess = get_home_path() . '.htaccess';
		if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return;
		}

		$format = get_option( 'cetus_media_format', 'auto' );

		if ( 'none' === $format ) {
			// Nessuna conversione attiva — rimuovi le regole.
			insert_with_markers( $htaccess, 'Cetus Image Converter & AI Alt Text', [] );
			return;
		}

		$lines = [
			'<IfModule mod_mime.c>',
			'  AddType image/webp .webp',
			'  AddType image/avif .avif',
			'</IfModule>',
		];

		insert_with_markers( $htaccess, 'Cetus Image Converter & AI Alt Text', $lines );
	}

	/**
	 * Valida il formato di una chiave API tramite AJAX.
	 *
	 * @return void
	 */
	public function ajax_validate_key(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [], 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cetus_mo_save_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'cetus-media-optimizer' ) ], 403 );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( ! class_exists( 'Cetus_MO_AI' ) ) {
			wp_send_json_error( [ 'message' => 'Classe AI non disponibile.' ] );
		}

		$ai     = new Cetus_MO_AI();
		$result = $ai->validate_api_key( $provider, $api_key );

		if ( $result['valid'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	// -------------------------------------------------------------------------
	// Meta box esclusione allegato
	// -------------------------------------------------------------------------

	/**
	 * Registra il meta box nella schermata di modifica allegato.
	 *
	 * @return void
	 */
	public function register_attachment_meta_box(): void {
		add_meta_box(
			'cetus-mo-exclusion',
			__( 'Cetus Image Converter & AI Alt Text', 'cetus-media-optimizer' ),
			[ $this, 'render_attachment_exclusion_meta_box' ],
			'attachment',
			'side',
			'low'
		);
	}

	/**
	 * Render del meta box di esclusione nell'editor allegato.
	 *
	 * @param WP_Post $post Post (allegato) corrente.
	 * @return void
	 */
	public function render_attachment_exclusion_meta_box( WP_Post $post ): void {
		$excluded = get_post_meta( $post->ID, 'cetus_mo_exclude', true );
		wp_nonce_field( 'cetus_mo_meta_' . $post->ID, 'cetus_mo_meta_nonce' );
		?>
		<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
			<input type="checkbox" name="cetus_mo_exclude" value="1" <?php checked( $excluded, '1' ); ?>>
			<?php esc_html_e( 'Exclude from conversion and Alt Text AI', 'cetus-media-optimizer' ); ?>
		</label>
		<p style="font-size:11px;color:#666;margin:8px 0 0;">
			<?php esc_html_e( 'If checked, this image is skipped both during batch conversion and on upload.', 'cetus-media-optimizer' ); ?>
		</p>
		<?php
	}

	/**
	 * Salva il meta di esclusione quando l'utente salva l'allegato.
	 *
	 * @param int $post_id ID dell'allegato.
	 * @return void
	 */
	public function save_attachment_exclusion_meta( int $post_id ): void {
		$nonce = isset( $_POST['cetus_mo_meta_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['cetus_mo_meta_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'cetus_mo_meta_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$exclude = isset( $_POST['cetus_mo_exclude'] ) ? '1' : '0';
		update_post_meta( $post_id, 'cetus_mo_exclude', $exclude );
	}

	// -------------------------------------------------------------------------
	// Log conversioni
	// -------------------------------------------------------------------------

	/**
	 * Render della tabella log delle conversioni (ultime 50 voci).
	 *
	 * @return void
	 */
	private function render_log_table(): void {
		$log = get_option( 'cetus_mo_conversion_log', [] );

		if ( ! is_array( $log ) || empty( $log ) ) {
			echo '<p class="description">' . esc_html__( 'No conversions recorded yet.', 'cetus-media-optimizer' ) . '</p>';
			return;
		}

		$rows = array_slice( $log, 0, 50 );
		?>
		<div class="cmo-log-wrap">
			<table class="cmo-log-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date/Time', 'cetus-media-optimizer' ); ?></th>
						<th><?php esc_html_e( 'File', 'cetus-media-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Format', 'cetus-media-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Savings', 'cetus-media-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Status', 'cetus-media-optimizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $rows as $entry ) :
						$ts         = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
						$date       = $ts ? gmdate( 'd/m/Y H:i', $ts ) : '–';
						$filename   = isset( $entry['filename'] ) ? esc_html( $entry['filename'] ) : '–';
						$format     = isset( $entry['format'] ) ? esc_html( $entry['format'] ) : '–';
						$saved      = isset( $entry['bytes_saved'] ) ? $this->format_bytes( (int) $entry['bytes_saved'] ) : '–';
						$status     = isset( $entry['status'] ) ? esc_html( $entry['status'] ) : '–';
						$status_cls = 'converted' === ( $entry['status'] ?? '' ) ? 'cmo-log-ok' : 'cmo-log-warn';
						?>
					<tr>
						<td><?php echo esc_html( $date ); ?></td>
						<td class="cmo-log-filename"><?php echo esc_html( $filename ); ?></td>
						<td><span class="cmo-badge-format"><?php echo esc_html( $format ); ?></span></td>
						<td><?php echo esc_html( $saved ); ?></td>
						<td><span class="<?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $status ); ?></span></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $log ) > 50 ) : ?>
			<p class="description" style="margin-top:6px;">
				<?php
				printf(
					/* translators: %d: numero voci totali nel log */
					esc_html__( 'Showing the last 50 of %d total entries.', 'cetus-media-optimizer' ),
					count( $log )
				);
				?>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handler AJAX: svuota il log delle conversioni.
	 *
	 * @return void
	 */
	public function ajax_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [], 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cetus_mo_bulk_nonce' ) ) {
			wp_send_json_error( [], 403 );
		}

		update_option( 'cetus_mo_conversion_log', [] );
		wp_send_json_success( [ 'message' => __( 'Log cleared.', 'cetus-media-optimizer' ) ] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Formatta un numero di byte in una stringa leggibile (KB, MB, GB).
	 *
	 * @param int $bytes Numero di byte.
	 * @return string
	 */
	private function format_bytes( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 2 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 2 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}

	// -------------------------------------------------------------------------
	// Box recensione
	// -------------------------------------------------------------------------

	/**
	 * Render del box che invita l'utente a lasciare una recensione su WordPress.org.
	 * Usa la classe nativa `.card` di WordPress per integrarsi con l'admin UI.
	 *
	 * @return void
	 */
	private function render_review_box(): void {
		$review_url = 'https://wordpress.org/support/plugin/cetus-media-optimizer/reviews/#new-post';
		?>
		<div class="card cmo-review-card" style="max-width:760px;margin-top:24px;padding:20px 24px;box-sizing:border-box;">
			<div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
				<div style="font-size:32px;line-height:1;flex-shrink:0;" aria-hidden="true">⭐</div>
				<div style="flex:1;min-width:200px;">
					<h3 style="margin:0 0 8px;">
						<?php esc_html_e( 'Do you like Cetus Image Converter & AI Alt Text?', 'cetus-media-optimizer' ); ?>
					</h3>
					<p style="margin:0 0 14px;color:#50575e;font-size:13px;">
						<?php esc_html_e( 'Cetus Image Converter & AI Alt Text is 100% free and open source. If it is optimizing your media library and improving your Core Web Vitals scores, support LivQ\'s development by leaving a 5-star review on WordPress.org.', 'cetus-media-optimizer' ); ?>
					</p>
					<a href="<?php echo esc_url( $review_url ); ?>"
						class="button button-primary"
						target="_blank"
						rel="noopener noreferrer">
						<span class="dashicons dashicons-star-filled" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
						<?php esc_html_e( 'Leave a 5-star review', 'cetus-media-optimizer' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// CSS inline
	// -------------------------------------------------------------------------

	/**
	 * Restituisce il CSS inline minimo per il pannello di amministrazione.
	 * Utilizza classi native WordPress per notice, tabelle e form.
	 * Solo sovrascritture strettamente necessarie.
	 *
	 * @return string
	 */
	private function get_inline_css(): string {
		return '
/* ─── Quality slider ─── */
.cmo-quality-slider { accent-color: #2271b1; cursor: pointer; width: 200px; vertical-align: middle; }
.cmo-quality-value { display: inline-block; min-width: 36px; text-align: center; font-weight: 700; font-size: 14px; color: #2271b1; background: #e0eaf8; border-radius: 4px; padding: 1px 6px; margin-left: 6px; }

/* ─── API key validation feedback ─── */
.cmo-key-status { font-size: 13px; margin-left: 4px; }
.cmo-key-status.ok   { color: #00a32a; }
.cmo-key-status.fail { color: #d63638; }

/* ─── Progress bar (no WP native equivalent) ─── */
.cmo-progress-wrap { background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px 20px; margin-bottom: 20px; }
.cmo-progress-bar-track { background: #dcdcde; border-radius: 20px; height: 16px; overflow: hidden; margin-bottom: 8px; }
.cmo-progress-bar-fill { height: 100%; background: linear-gradient(90deg, #2271b1, #135e96); border-radius: 20px; transition: width .8s cubic-bezier(.4,0,.2,1); }
.cmo-progress-info { display: flex; justify-content: space-between; font-size: 13px; color: #50575e; }
.cmo-progress-meta { display: flex; justify-content: space-between; font-size: 11px; color: #787c82; margin-top: 4px; min-height: 16px; }
.cmo-progress-detail { margin-top: 6px; font-size: 12px; color: #787c82; min-height: 18px; }

/* ─── Log table ─── */
.cmo-log-wrap { max-height: 380px; overflow-y: auto; border: 1px solid #c3c4c7; border-radius: 4px; }
.cmo-log-table { margin: 0 !important; font-size: 13px; }
.cmo-log-table th { background: #f6f7f7; position: sticky; top: 0; z-index: 1; }
.cmo-log-filename { font-family: monospace; font-size: 12px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cmo-badge-format { background: #e0eaf8; color: #2271b1; border-radius: 3px; padding: 1px 6px; font-size: 11px; font-weight: 700; }
.cmo-log-ok   { color: #00a32a; font-weight: 600; }
.cmo-log-warn { color: #dba617; font-weight: 600; }

/* ─── Danger button ─── */
.cmo-btn-danger { color: #d63638 !important; border-color: #d63638 !important; }
.cmo-btn-danger:hover { background: #d63638 !important; color: #fff !important; }

/* ─── Dashicons dentro pulsanti: reset allineamento e colore ─── */
.button .dashicons,
.button-hero .dashicons {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 1em;
	height: 1em;
	font-size: 1em;
	line-height: 1;
	vertical-align: middle;
	position: relative;
	top: -1px;
	margin-right: 4px;
	color: inherit;
}
.button-hero .dashicons {
	font-size: 18px;
	width: 18px;
	height: 18px;
}

/* ─── Alt text preview box ─── */
.cmo-alttext-preview { background: #f0f6ff; border: 1px solid #c3d9f7; border-radius: 4px; padding: 14px 18px; margin-bottom: 16px; display: flex; flex-wrap: wrap; align-items: center; gap: 0 24px; }
.cmo-alttext-preview__count { display: flex; align-items: baseline; gap: 6px; }
.cmo-alttext-preview__num { font-size: 28px; font-weight: 700; color: #1d2327; line-height: 1; }
.cmo-alttext-preview__label { font-size: 13px; color: #50575e; }
.cmo-alttext-preview__note { width: 100%; margin: 8px 0 0; font-size: 12px; color: #50575e; }

/* ─── Orphan info ─── */
#cmo-orphan-info { padding: 8px 12px; background: #fff3cd; border-left: 4px solid #dba617; font-size: 14px; color: #614200; margin-bottom: 8px; }
.cmo-error-log { max-height: 220px; overflow-y: auto; border: 1px solid #f0b8b8; border-radius: 4px; background: #fff8f8; font-size: 12px; font-family: monospace; }
.cmo-error-log-entry { display: flex; gap: 8px; padding: 5px 10px; border-bottom: 1px solid #fce8e8; }
.cmo-error-log-entry:last-child { border-bottom: none; }
.cmo-error-log-file { color: #555; min-width: 160px; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex-shrink: 0; }
.cmo-error-log-msg { color: #c0392b; }
		';
	}

	// -------------------------------------------------------------------------
	// JS inline
	// -------------------------------------------------------------------------

	/**
	 * Restituisce il JavaScript inline per la logica del pannello.
	 * Tab switching rimosso (ora gestito tramite URL nativi WordPress).
	 * showNotice aggiornata per usare #cmo-notice-wrap con classi .notice native.
	 *
	 * @return string
	 */
	private function get_inline_js(): string {
		return '
(function($) {
	"use strict";

	var CMO = window.CetusMO || {};
	var batchRunning  = false;
	var batchPaused   = false;
	var tickTimeout   = null;
	var batchStartTime = null;
	var batchTotal     = 0;

	/* ── Quality sliders ── */
	function updateSlider(inputId, valId) {
		var $input = $("#" + inputId);
		var $val   = $("#" + valId);
		$val.text($input.val());
		$input.on("input", function() { $val.text($(this).val()); });
	}
	updateSlider("cmo_webp_quality", "cmo_webp_quality_val");
	updateSlider("cmo_avif_quality", "cmo_avif_quality_val");

	/* Nascondi/mostra il gruppo qualità quando si sceglie "Nessuna conversione" */
	$("input[name=\'cetus_media_format\']").on("change", function() {
		var isNone = $(this).val() === "none";
		// Nascondi/mostra qualità di conversione.
		if (isNone) {
			$("#cmo-quality-group").hide();
		} else {
			$("#cmo-quality-group").show();
		}
		// Disabilita/abilita "Nuovi Upload" con feedback visivo.
		var $cb   = $("#cmo_auto_convert");
		var $lbl  = $("#cmo-label-auto-convert");
		var $note = $("#cmo-auto-convert-note");
		if (isNone) {
			$cb.prop("disabled", true).prop("checked", false);
			$lbl.css("opacity", "0.5");
			$note.show();
		} else {
			$cb.prop("disabled", false);
			$lbl.css("opacity", "1");
			$note.hide();
		}
	});

	/* ── Reset file convertiti ── */
	$("#cmo-btn-reset").on("click", function() {
		if (!confirm(CMO.i18n.confirm_reset)) return;
		var $btn = $(this).prop("disabled", true);
		$.post(CMO.ajax_url, { action: "cetus_mo_reset_files", nonce: CMO.nonce }, function(r) {
			if (r.success) {
				// Ricarica la pagina per aggiornare tutti i contatori (Da convertire, statistiche, log).
				window.location.reload();
			} else {
				showNotice("error", CMO.i18n.error);
				$btn.prop("disabled", false);
			}
		}).fail(function() {
			showNotice("error", CMO.i18n.error);
			$btn.prop("disabled", false);
		});
	});

	/* ── Svuota log ── */
	$("#cmo-btn-clear-log").on("click", function() {
		if (!confirm(CMO.i18n.confirm_clear_log)) return;
		$.post(CMO.ajax_url, { action: "cetus_mo_clear_log", nonce: CMO.nonce }, function(r) {
			if (r.success) {
				$("#cmo-log-section .cmo-log-wrap").html("<p class=\"description\">" + CMO.i18n.no_conversions + "</p>");
				showNotice("success", r.data.message);
			}
		});
	});

	/* ── Validazione chiave API ── */
	$(document).on("click", ".cmo-validate-key", function() {
		var $btn      = $(this);
		var provider  = $btn.data("provider");
		var inputId   = $btn.data("input");
		var apiKey    = $("#" + inputId).val().trim();
		var $status   = $("#" + inputId + "_status");

		if (!apiKey) {
			$status.text(CMO.i18n.key_invalid).attr("class", "cmo-key-status fail");
			return;
		}

		$btn.prop("disabled", true).text("…");

		$.post(CMO.ajax_url, {
			action  : "cetus_mo_validate_key",
			nonce   : CMO.save_nonce,
			provider: provider,
			api_key : apiKey
		}, function(response) {
			if (response.success) {
				$status.text("✓ " + response.data.message).attr("class", "cmo-key-status ok");
			} else {
				$status.text("✗ " + response.data.message).attr("class", "cmo-key-status fail");
			}
		}).fail(function() {
			$status.text(CMO.i18n.error).attr("class", "cmo-key-status fail");
		}).always(function() {
			$btn.prop("disabled", false).text(CMO.i18n.verify);
		});
	});

	/* ── Salvataggio impostazioni ── */
	$("#cmo-save-btn, #cmo-save-btn-bottom").on("click", function(e) {
		e.preventDefault();
		var $btn  = $(this);
		var data  = $("#cmo-settings-form").serializeArray();
		data.push({ name: "action", value: "cetus_mo_save_settings" });
		data.push({ name: "nonce",  value: CMO.save_nonce });

		$btn.prop("disabled", true);

		$.post(CMO.ajax_url, data, function(response) {
			if (response.success) {
				window.location.reload();
			} else {
				showNotice("error", response.data ? response.data.message : CMO.i18n.error);
				$btn.prop("disabled", false);
			}
		}).fail(function() {
			showNotice("error", CMO.i18n.error);
			$btn.prop("disabled", false);
		});
	});

	/* ── Avvio batch ── */
	$("#cmo-btn-start").on("click", function() {
		$.post(CMO.ajax_url, { action: "cetus_mo_batch_start", nonce: CMO.nonce }, function(r) {
			if (!r.success) { showNotice("error", (r.data && r.data.message) ? r.data.message : CMO.i18n.error); return; }
			if (r.data.status === "empty") { showNotice("success", (r.data.message) ? r.data.message : CMO.i18n.done); return; }
			batchRunning   = true;
			batchPaused    = false;
			batchStartTime = null;
			batchTotal     = r.data.total || 0;
			showProgressWrap(true);
			$("#cmo-progress-text").text("0 / " + batchTotal);
			$("#cmo-progress-percent").text("0%");
			$("#cmo-progress-bar").css("width", "0%");
			$("#cmo-progress-speed").text("");
			$("#cmo-progress-eta").text(CMO.i18n.estimating);
			updateBatchButtons();
			scheduleTick();
		}).fail(function() { showNotice("error", CMO.i18n.error); });
	});

	/* ── Pausa ── */
	$("#cmo-btn-pause").on("click", function() {
		$.post(CMO.ajax_url, { action: "cetus_mo_batch_pause", nonce: CMO.nonce }, function(r) {
			if (!r.success) { showNotice("error", (r.data && r.data.message) ? r.data.message : CMO.i18n.error); return; }
			batchPaused = true;
			clearTimeout(tickTimeout);
			updateBatchButtons();
		});
	});

	/* ── Riprendi ── */
	$("#cmo-btn-resume").on("click", function() {
		$.post(CMO.ajax_url, { action: "cetus_mo_batch_resume", nonce: CMO.nonce }, function(r) {
			if (!r.success) { showNotice("error", (r.data && r.data.message) ? r.data.message : CMO.i18n.error); return; }
			batchPaused = false;
			scheduleTick();
			updateBatchButtons();
		});
	});

	/* ── Stop ── */
	$("#cmo-btn-stop").on("click", function() {
		clearTimeout(tickTimeout);
		$.post(CMO.ajax_url, { action: "cetus_mo_batch_stop", nonce: CMO.nonce }, function() {
			batchRunning = false;
			batchPaused  = false;
			showProgressWrap(false);
			updateBatchButtons();
			showNotice("success", CMO.i18n.process_stopped);
		});
	});

	/* ── Tick del batch ── */
	function scheduleTick() {
		if (!batchRunning || batchPaused) return;
		var tickStart = Date.now();
		$.post(CMO.ajax_url, { action: "cetus_mo_batch_tick", nonce: CMO.nonce }, function(r) {
			if (!r.success) {
				batchRunning = false;
				showProgressWrap(false);
				updateBatchButtons();
				showNotice("error", (r.data && r.data.message) ? r.data.message : CMO.i18n.error);
				return;
			}
			var d = r.data;
			if (!batchStartTime && d.processed > 0) batchStartTime = Date.now() - (Date.now() - tickStart);
			updateProgress(d);
			if (d.errors_log && d.errors_log.length) {
				appendBulkErrors(d.errors_log);
			}
			if (d.status === "done") {
				batchRunning = false;
				updateBatchButtons();
				$("#cmo-progress-speed").text("");
				$("#cmo-progress-eta").text(CMO.i18n.done);
				showNotice("success", CMO.i18n.done + " " + CMO.i18n.converted_stat + " " + d.converted + " | " + CMO.i18n.skipped_stat + " " + d.skipped + " | " + CMO.i18n.errors_stat + " " + d.errors);
			} else if (d.status === "running") {
				tickTimeout = setTimeout(scheduleTick, 600);
			}
		}).fail(function() { showNotice("error", CMO.i18n.error); });
	}

	function updateProgress(d) {
		var pct = d.percent || 0;
		$("#cmo-progress-bar").css("width", pct + "%");
		$("#cmo-progress-text").text(d.processed + " / " + d.total);
		$("#cmo-progress-percent").text(pct + "%");
		$("#cmo-progress-detail").text(
			CMO.i18n.converted_stat + " " + d.converted + "  |  " + CMO.i18n.skipped_stat + " " + d.skipped + "  |  " + CMO.i18n.errors_stat + " " + d.errors
		);

		if (batchStartTime && d.processed > 0) {
			var elapsedSec  = (Date.now() - batchStartTime) / 1000;
			var speed       = d.processed / elapsedSec;
			var remaining   = (d.total - d.processed) / speed;
			var speedLabel  = speed >= 1
				? speed.toFixed(1) + " img/s"
				: (speed * 60).toFixed(1) + " img/min";
			$("#cmo-progress-speed").text(speedLabel);
			$("#cmo-progress-eta").text(remaining > 0 ? CMO.i18n.estimated_time + " " + fmtTime(remaining) : "");
		}

		var mb = (d.bytes_saved / 1048576).toFixed(2);
		$("#cmo-bytes-saved").text(mb + " MB");
	}

	function fmtTime(sec) {
		sec = Math.round(sec);
		var h = Math.floor(sec / 3600);
		var m = Math.floor((sec % 3600) / 60);
		var s = sec % 60;
		if (h > 0) return h + "h " + m + "m";
		if (m > 0) return m + "m " + s + "s";
		return s + "s";
	}

	function updateBatchButtons() {
		$("#cmo-btn-start").toggle(!batchRunning);
		$("#cmo-btn-pause").toggle(batchRunning && !batchPaused);
		$("#cmo-btn-resume").toggle(batchRunning && batchPaused);
		$("#cmo-btn-stop").toggle(batchRunning);
	}

	function showProgressWrap(show) {
		$("#cmo-progress-wrap").toggle(show);
		if (!show) { $("#cmo-bulk-errors-wrap").hide(); $("#cmo-bulk-errors-log").empty(); }
	}

	function appendBulkErrors(log) {
		var wrap = $("#cmo-bulk-errors-wrap").show();
		var logEl = $("#cmo-bulk-errors-log");
		$.each(log, function(i, e) {
			var row = $("<div>").addClass("cmo-error-log-entry");
			row.append($("<span>").addClass("cmo-error-log-file").text(e.file));
			row.append($("<span>").addClass("cmo-error-log-msg").text(e.message));
			logEl.append(row);
		});
		logEl.scrollTop(logEl[0].scrollHeight);
	}

	/* ── Generazione Alt Text in massa ── */
	var alttextRunning = false;

	$("#cmo-btn-alttext").on("click", function() {
		if (alttextRunning) return;
		alttextRunning = true;
		var $btn = $(this).prop("disabled", true);
		$("#cmo-alttext-progress").show();
		runAlttextTick(0, 0, 0, 0);
	});

	function runAlttextTick(offset, total, done, errors) {
		$.post(CMO.ajax_url, {
			action : "cetus_mo_alttext_tick",
			nonce  : CMO.nonce,
			offset : offset
		}, function(r) {
			if (!r.success) {
				showNotice("error", CMO.i18n.error);
				resetAlttext();
				return;
			}
			var d = r.data;
			total  = d.total;
			done  += d.done_this_tick;
			errors += d.errors_this_tick;

			var processed = offset + d.processed_this_tick;
			var pct = total > 0 ? Math.round((processed / total) * 100) : 100;
			$("#cmo-alttext-bar").css("width", pct + "%");
			$("#cmo-alttext-text").text(processed + " / " + total);
			$("#cmo-alttext-percent").text(pct + "%");
			$("#cmo-alttext-detail").text(CMO.i18n.generated_stat + " " + done + "  |  " + CMO.i18n.already_present + " " + d.skipped_this_tick + "  |  " + CMO.i18n.errors_stat + " " + errors);

			if (d.done) {
				showNotice("success", CMO.i18n.generated_stat + " " + done);
				resetAlttext();
			} else {
				setTimeout(function() { runAlttextTick(processed, total, done, errors); }, 600);
			}
		}).fail(function() {
			showNotice("error", CMO.i18n.error);
			resetAlttext();
		});
	}

	function resetAlttext() {
		alttextRunning = false;
		$("#cmo-btn-alttext").prop("disabled", false);
	}

	/* ── Scansione file orfani ── */
	$("#cmo-btn-scan-orphans").on("click", function() {
		var $btn = $(this).prop("disabled", true).text(CMO.i18n.scanning);
		$.post(CMO.ajax_url, { action: "cetus_mo_scan_orphans", nonce: CMO.nonce }, function(r) {
			if (!r.success) { showNotice("error", CMO.i18n.error); return; }
			var d = r.data;
			$("#cmo-orphan-result").show();
			if (d.count === 0) {
				$("#cmo-orphan-info").text(CMO.i18n.no_orphans);
				$("#cmo-btn-convert-orphans").hide();
			} else {
				$("#cmo-orphan-info").text(d.message);
				$("#cmo-btn-convert-orphans").show();
			}
		}).fail(function() { showNotice("error", CMO.i18n.error); })
		.always(function() { $btn.prop("disabled", false).text(CMO.i18n.scan_orphans_btn); });
	});

	/* ── Converti file orfani ── */
	var orphanPaused = false;
	var orphanRunning = false;
	var orphanTickTimer = null;

	function updateOrphanButtons() {
		$("#cmo-btn-convert-orphans").toggle(!orphanRunning);
		$("#cmo-btn-orphan-pause").toggle(orphanRunning && !orphanPaused);
		$("#cmo-btn-orphan-resume").toggle(orphanRunning && orphanPaused);
		$("#cmo-btn-orphan-stop").toggle(orphanRunning);
	}

	function appendOrphanErrors(log) {
		var wrap = $("#cmo-orphan-errors-wrap").show();
		var logEl = $("#cmo-orphan-errors-log");
		$.each(log, function(i, e) {
			var row = $("<div>").addClass("cmo-error-log-entry");
			row.append($("<span>").addClass("cmo-error-log-file").text(e.file));
			row.append($("<span>").addClass("cmo-error-log-msg").text(e.message));
			logEl.append(row);
		});
		logEl.scrollTop(logEl[0].scrollHeight);
	}

	function resetOrphan() {
		orphanRunning = false;
		orphanPaused  = false;
		clearTimeout(orphanTickTimer);
		updateOrphanButtons();
		$("#cmo-orphan-progress-wrap").hide();
	}

	function orphanTick() {
		$.post(CMO.ajax_url, { action: "cetus_mo_orphan_tick", nonce: CMO.nonce }, function(r) {
			if (!r.success) { showNotice("error", CMO.i18n.error); resetOrphan(); return; }
			var d = r.data;
			$("#cmo-orphan-bar").css("width", d.percent + "%");
			$("#cmo-orphan-progress-text").text(
				d.processed + " / " + d.total + " — " +
				d.converted + " " + CMO.i18n.converted_label + ", " +
				d.errors + " " + CMO.i18n.errors_label
			);
			if (d.errors_log && d.errors_log.length) {
				appendOrphanErrors(d.errors_log);
			}
			if (d.status === "done") {
				var mb = (d.bytes_saved / (1024*1024)).toFixed(2);
				showNotice("success", d.converted + " " + CMO.i18n.orphans_done + " — " + mb + " MB");
				resetOrphan();
			} else if (d.status === "paused") {
				// Non schedulare il prossimo tick, aspetta il resume.
			} else {
				orphanTickTimer = setTimeout(orphanTick, 300);
			}
		}).fail(function() { showNotice("error", CMO.i18n.error); resetOrphan(); });
	}

	$("#cmo-btn-convert-orphans").on("click", function() {
		if (!confirm(CMO.i18n.confirm_orphan)) return;

		$.post(CMO.ajax_url, { action: "cetus_mo_orphan_start", nonce: CMO.nonce }, function(r) {
			if (!r.success) { showNotice("error", CMO.i18n.error); return; }
			if (r.data.status === "empty") {
				var msg = (r.data.reason === "already_optimized")
					? CMO.i18n.orphan_already_opt
					: CMO.i18n.done;
				showNotice("success", msg);
				return;
			}

			orphanRunning = true;
			orphanPaused  = false;
			$("#cmo-orphan-errors-wrap").hide();
			$("#cmo-orphan-errors-log").empty();
			$("#cmo-orphan-progress-wrap").show();
			updateOrphanButtons();
			orphanTick();
		}).fail(function() { showNotice("error", CMO.i18n.error); });
	});

	$("#cmo-btn-orphan-pause").on("click", function() {
		$.post(CMO.ajax_url, { action: "cetus_mo_orphan_pause", nonce: CMO.nonce }, function(r) {
			if (!r.success) return;
			orphanPaused = true;
			clearTimeout(orphanTickTimer);
			updateOrphanButtons();
		});
	});

	$("#cmo-btn-orphan-resume").on("click", function() {
		$.post(CMO.ajax_url, { action: "cetus_mo_orphan_resume", nonce: CMO.nonce }, function(r) {
			if (!r.success) return;
			orphanPaused = false;
			updateOrphanButtons();
			orphanTick();
		});
	});

	$("#cmo-btn-orphan-stop").on("click", function() {
		clearTimeout(orphanTickTimer);
		$.post(CMO.ajax_url, { action: "cetus_mo_orphan_stop", nonce: CMO.nonce }, function() {
			resetOrphan();
			showNotice("success", CMO.i18n.orphan_stopped);
		});
	});

	/* ── Utility: mostra un notice nativo WP in #cmo-notice-wrap ── */
	function showNotice(type, message) {
		var cls = (type === "success") ? "notice-success" : "notice-error";
		var $wrap = $("#cmo-notice-wrap");
		$wrap.html("<div class=\"notice " + cls + " is-dismissible\"><p>" + message + "</p></div>");
		clearTimeout($wrap.data("t"));
		$wrap.data("t", setTimeout(function() { $wrap.find(".notice").fadeOut(400, function() { $(this).remove(); }); }, 6000));
	}

})(jQuery);
		';
	}
}
