<?php
/**
 * Gestisce la migrazione sicura dalla v1.x alla v2.0.
 *
 * Rimuove le opzioni e i log obsoleti del vecchio plugin, mappando
 * eventuali valori validi nelle nuove opzioni di Cetus Image Converter & AI Alt Text.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_Migrator' ) ) {
	return;
}

/**
 * Class Cetus_MO_Migrator
 */
class Cetus_MO_Migrator {

	/**
	 * Prefisso delle opzioni del vecchio plugin (v1.x).
	 *
	 * @var string
	 */
	private const LEGACY_PREFIX = 'cetus_webp_';

	/**
	 * Opzioni legacy conosciute da rimuovere o migrare.
	 *
	 * @var array<string, string|null> chiave_legacy => nuova_chiave (null = solo rimozione)
	 */
	private const LEGACY_MAP = [
		'cetus_webp_enabled'        => null,
		'cetus_webp_quality'        => null,
		'cetus_webp_auto_convert'   => 'cetus_media_auto_convert',
		'cetus_webp_conversion_log' => null,
		'cetus_webp_orphan_log'     => null,
		'cetus_webp_version'        => null,
		'cetus_webp_batch_progress' => null,
		'cetus_webp_batch_lock'     => null,
		'cetus_webp_openai_key'     => 'cetus_media_openai_key',
	];

	/**
	 * Esegue l'intera procedura di migrazione.
	 *
	 * Viene invocato una sola volta all'attivazione del plugin. Se la migrazione
	 * è già stata eseguita (flag presente nel DB), termina immediatamente.
	 *
	 * @return void
	 */
	public function run(): void {
		// Evita di eseguire la migrazione più volte.
		if ( get_option( 'cetus_mo_migration_done' ) ) {
			return;
		}

		// Controlla se esiste almeno un'opzione legacy per sapere se c'è da migrare.
		$has_legacy = false;
		foreach ( array_keys( self::LEGACY_MAP ) as $legacy_key ) {
			if ( false !== get_option( $legacy_key ) ) {
				$has_legacy = true;
				break;
			}
		}

		if ( $has_legacy ) {
			$this->migrate_options();
			$this->remove_legacy_transients();
			$this->log_migration_event();
		}

		// Imposta il flag di migrazione completata.
		update_option( 'cetus_mo_migration_done', '1' );
	}

	/**
	 * Migra o elimina le opzioni legacy.
	 *
	 * Per ogni coppia nella mappa: se esiste una nuova chiave di destinazione
	 * e l'opzione destinazione non è già impostata, copia il valore legacy.
	 * In ogni caso elimina l'opzione legacy dal database.
	 *
	 * @return void
	 */
	private function migrate_options(): void {
		foreach ( self::LEGACY_MAP as $legacy_key => $new_key ) {
			$legacy_value = get_option( $legacy_key );

			if ( false === $legacy_value ) {
				// L'opzione non esiste nel DB, nulla da fare.
				continue;
			}

			// Migra il valore nella nuova opzione solo se la destinazione non esiste.
			if ( null !== $new_key && false === get_option( $new_key ) ) {
				$sanitized = sanitize_text_field( (string) $legacy_value );
				update_option( $new_key, $sanitized );
			}

			// Elimina sempre l'opzione legacy.
			delete_option( $legacy_key );
		}
	}

	/**
	 * Rimuove i transient legacy che potrebbero essere rimasti nel DB.
	 *
	 * @return void
	 */
	private function remove_legacy_transients(): void {
		$transients = [
			'cetus_webp_batch_lock',
			'cetus_webp_server_diagnostics',
		];

		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}
	}

	/**
	 * Scrive un record di migrazione nelle opzioni per debug futuro.
	 *
	 * @return void
	 */
	private function log_migration_event(): void {
		update_option(
			'cetus_mo_migration_log',
			[
				'from_version' => get_option( 'cetus_webp_version', '1.x' ),
				'to_version'   => CETUS_MO_VERSION,
				'migrated_at'  => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}
}
