<?php
/**
 * Plugin Name:       Cetus Image Converter & AI Alt Text
 * Plugin URI:        https://github.com/livqtech/cetus-media-optimizer
 * Description:       Advanced media optimizer: converts images to AVIF/WebP, auto-generates Alt Text via AI (Gemini / OpenAI), detects orphan files and manages your media library.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            LivQ
 * Author URI:        https://livq.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cetus-media-optimizer
 * Domain Path:       /languages
 *
 * @package CetusMediaOptimizer
 */

// Blocca l'accesso diretto al file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Carica l'autoloader di Composer (Sentry SDK e dipendenze).
$cetus_mo_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $cetus_mo_autoloader ) ) {
	require_once $cetus_mo_autoloader;
}
unset( $cetus_mo_autoloader );

// Impedisce il caricamento duplicato.
if ( defined( 'CETUS_MO_VERSION' ) ) {
	return;
}

/**
 * Costanti del plugin. Definite una sola volta grazie al check precedente.
 */
define( 'CETUS_MO_VERSION', '1.0.0' );
define( 'CETUS_MO_FILE', __FILE__ );
define( 'CETUS_MO_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'CETUS_MO_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'CETUS_MO_INCLUDES', CETUS_MO_DIR . 'includes/' );
define( 'CETUS_MO_SLUG', 'cetus-media-optimizer' );

/**
 * Carica le classi del plugin con require_once sicuro.
 *
 * L'ordine è importante: Migrator prima di tutto perché può agire
 * sulle opzioni legacy prima che le altre classi le leggano.
 *
 * @return void
 */
function cetus_mo_load_classes(): void {
	$files = [
		'class-migrator.php',
		'class-cetus-telemetry.php',
		'class-cetus-optimizer.php',
		'class-cetus-ai.php',
		'class-bulk-processor.php',
		'class-cetus-admin.php',
		'class-plugin-links.php',
	];

	foreach ( $files as $file ) {
		$path = CETUS_MO_INCLUDES . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

cetus_mo_load_classes();

/**
 * Hook di attivazione: crea opzioni di default ed esegue la migrazione
 * dalla v1.x se necessario.
 *
 * @return void
 */
function cetus_mo_activate(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// Imposta i valori di default solo se non esistono già.
	$defaults = [
		'cetus_media_format'            => 'auto',
		'cetus_media_auto_convert'      => '0',
		'cetus_media_ai_provider'       => 'gemini',
		'cetus_media_ai_fallback'       => '1',
		'cetus_media_gemini_key'        => '',
		'cetus_media_openai_key'        => '',
		'cetus_media_telemetry_opt_in'  => '0',
		'cetus_media_webp_quality'      => '82',
		'cetus_media_avif_quality'      => '75',
		'cetus_media_alt_text_language' => '',
		'cetus_media_alt_text_prompt'   => '',
		'cetus_media_cron_enabled'      => '0',
		'cetus_mo_total_bytes_saved'    => '0',
		'cetus_media_version'           => CETUS_MO_VERSION,
	];

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}

	// Esegui la migrazione dal vecchio plugin se le classi sono disponibili.
	if ( class_exists( 'Cetus_MO_Migrator' ) ) {
		$migrator = new Cetus_MO_Migrator();
		$migrator->run();
	}

	// Aggiorna la versione salvata.
	update_option( 'cetus_media_version', CETUS_MO_VERSION );

	// Aggiunge le regole MIME type nell'htaccess (AddType image/avif, image/webp).
	if ( class_exists( 'Cetus_MO_Admin' ) ) {
		Cetus_MO_Admin::update_htaccess_mime_types();
	}

	// Flush delle rewrite rules per sicurezza.
	flush_rewrite_rules();
}
register_activation_hook( CETUS_MO_FILE, 'cetus_mo_activate' );

/**
 * Hook di disattivazione: pulisce le opzioni temporanee (transient) e
 * resetta i lock dei processi batch. Non elimina i dati utente.
 *
 * @return void
 */
function cetus_mo_deactivate(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	delete_transient( 'cetus_mo_batch_lock' );
	delete_option( 'cetus_mo_batch_progress' );
	wp_clear_scheduled_hook( 'cetus_mo_cron_batch' );

	// Rimuove le regole MIME dall'htaccess.
	$htaccess = get_home_path() . '.htaccess';
	if ( function_exists( 'insert_with_markers' ) && file_exists( $htaccess ) && is_writable( $htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		insert_with_markers( $htaccess, 'Cetus Image Converter & AI Alt Text', [] );
	}

	flush_rewrite_rules();
}
register_deactivation_hook( CETUS_MO_FILE, 'cetus_mo_deactivate' );

/**
 * Punto di avvio principale del plugin.
 * Inizializza le classi solo nell'hook 'plugins_loaded' per garantire che
 * WordPress sia completamente caricato.
 *
 * @return void
 */
function cetus_mo_init(): void {
	// Inizializza Sentry (solo se opt-in attivo e SDK disponibile).
	if ( class_exists( 'Cetus_MO_Telemetry' ) ) {
		Cetus_MO_Telemetry::init();
	}

	// Inizializza il pannello di amministrazione solo nel contesto admin.
	if ( is_admin() && class_exists( 'Cetus_MO_Admin' ) ) {
		( new Cetus_MO_Admin() )->init();
	}

	// Inizializza l'ottimizzatore (per i nuovi upload automatici).
	if ( class_exists( 'Cetus_MO_Optimizer' ) ) {
		( new Cetus_MO_Optimizer() )->init();
	}

	// Inizializza il processore batch per gli handler AJAX.
	if ( class_exists( 'Cetus_MO_Bulk_Processor' ) ) {
		( new Cetus_MO_Bulk_Processor() )->init();
	}

	// Aggiunge il link "Impostazioni" nella riga del plugin in plugins.php.
	if ( class_exists( 'Cetus_MO_Plugin_Links' ) ) {
		( new Cetus_MO_Plugin_Links() )->init();
	}
}
/**
 * Registra l'intervallo cron personalizzato "ogni 5 minuti".
 *
 * @param array<string, array{interval: int, display: string}> $schedules Schedule esistenti.
 * @return array<string, array{interval: int, display: string}>
 */
function cetus_mo_add_cron_schedule( array $schedules ): array {
	if ( ! isset( $schedules['cetus_mo_five_minutes'] ) ) {
		$schedules['cetus_mo_five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Cetus MO)', 'cetus-media-optimizer' ),
		];
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'cetus_mo_add_cron_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

add_action( 'plugins_loaded', 'cetus_mo_init' );

// Registra il contenuto della privacy policy appena WP è pronto.
add_action(
	'admin_init',
	function (): void {
		if ( class_exists( 'Cetus_MO_Telemetry' ) ) {
			Cetus_MO_Telemetry::register_privacy_policy_content();
		}
	}
);

/**
 * Callback del WP-Cron per il batch in background.
 * Viene registrato globalmente perché WP-Cron non è legato al contesto admin.
 *
 * @return void
 */
function cetus_mo_run_cron_batch(): void {
	if ( class_exists( 'Cetus_MO_Bulk_Processor' ) ) {
		( new Cetus_MO_Bulk_Processor() )->run_cron_batch();
	}
}
add_action( 'cetus_mo_cron_batch', 'cetus_mo_run_cron_batch' );
