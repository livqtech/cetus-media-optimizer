<?php
/**
 * Gestione dei processi batch asincroni per l'ottimizzazione di massa.
 *
 * Utilizza AJAX sicuro con nonce per avviare, mettere in pausa, fermare
 * e interrogare lo stato dell'elaborazione. Ogni chiamata processa un
 * piccolo batch per non superare i limiti di timeout del server.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_Bulk_Processor' ) ) {
	return;
}

/**
 * Class Cetus_MO_Bulk_Processor
 */
class Cetus_MO_Bulk_Processor {

	/**
	 * Numero di immagini elaborate per ogni tick AJAX.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 5;

	/**
	 * Chiave del transient usato come lock per evitare esecuzioni parallele.
	 *
	 * @var string
	 */
	private const LOCK_KEY = 'cetus_mo_batch_lock';

	/**
	 * Chiave dell'opzione DB per lo stato del processo corrente.
	 *
	 * @var string
	 */
	private const PROGRESS_KEY = 'cetus_mo_batch_progress';

	/**
	 * Istanza dell'ottimizzatore.
	 *
	 * @var Cetus_MO_Optimizer|null
	 */
	private ?Cetus_MO_Optimizer $optimizer = null;

	/**
	 * Istanza AI (per la generazione automatica alt text durante il batch).
	 *
	 * @var Cetus_MO_AI|null
	 */
	private ?Cetus_MO_AI $ai = null;

	// -------------------------------------------------------------------------
	// Inizializzazione
	// -------------------------------------------------------------------------

	/**
	 * Registra tutti gli handler AJAX.
	 *
	 * @return void
	 */
	public function init(): void {
		// Solo utenti autenticati con i permessi giusti.
		add_action( 'wp_ajax_cetus_mo_batch_start', [ $this, 'ajax_start' ] );
		add_action( 'wp_ajax_cetus_mo_batch_tick', [ $this, 'ajax_tick' ] );
		add_action( 'wp_ajax_cetus_mo_batch_pause', [ $this, 'ajax_pause' ] );
		add_action( 'wp_ajax_cetus_mo_batch_resume', [ $this, 'ajax_resume' ] );
		add_action( 'wp_ajax_cetus_mo_batch_stop', [ $this, 'ajax_stop' ] );
		add_action( 'wp_ajax_cetus_mo_batch_status', [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_cetus_mo_scan_orphans', [ $this, 'ajax_scan_orphans' ] );
		add_action( 'wp_ajax_cetus_mo_orphan_convert', [ $this, 'ajax_orphan_convert' ] );
		add_action( 'wp_ajax_cetus_mo_orphan_start', [ $this, 'ajax_orphan_start' ] );
		add_action( 'wp_ajax_cetus_mo_orphan_tick', [ $this, 'ajax_orphan_tick' ] );
		add_action( 'wp_ajax_cetus_mo_orphan_pause', [ $this, 'ajax_orphan_pause' ] );
		add_action( 'wp_ajax_cetus_mo_orphan_resume', [ $this, 'ajax_orphan_resume' ] );
		add_action( 'wp_ajax_cetus_mo_orphan_stop', [ $this, 'ajax_orphan_stop' ] );
		add_action( 'wp_ajax_cetus_mo_alttext_tick', [ $this, 'ajax_alttext_tick' ] );
		add_action( 'wp_ajax_cetus_mo_reset_files', [ $this, 'ajax_reset_files' ] );
	}

	// -------------------------------------------------------------------------
	// Handler AJAX: avvio
	// -------------------------------------------------------------------------

	/**
	 * Avvia un nuovo processo batch sulla libreria media.
	 * Recupera tutti gli allegati immagine e li mette in coda.
	 *
	 * @return void  (termina con wp_send_json_*)
	 */
	public function ajax_start(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		if ( get_transient( self::LOCK_KEY ) ) {
			wp_send_json_error(
				[
					'message' => __( 'A process is already running. Use Pause or Stop before restarting.', 'cetus-media-optimizer' ),
				]
			);
		}

		$queue = $this->build_queue();

		if ( empty( $queue ) ) {
			wp_send_json_success(
				[
					'status'  => 'empty',
					'message' => __( 'No images to optimize found in the library.', 'cetus-media-optimizer' ),
				]
			);
		}

		$progress = [
			'status'      => 'running',
			'queue'       => $queue,
			'total'       => count( $queue ),
			'processed'   => 0,
			'converted'   => 0,
			'skipped'     => 0,
			'errors'      => 0,
			'bytes_saved' => 0,
			'started_at'  => time(),
		];

		update_option( self::PROGRESS_KEY, $progress );
		set_transient( self::LOCK_KEY, '1', HOUR_IN_SECONDS );

		// Se il cron è abilitato, pianifica i tick automatici ogni 5 minuti.
		if ( '1' === get_option( 'cetus_media_cron_enabled', '0' ) ) {
			if ( ! wp_next_scheduled( 'cetus_mo_cron_batch' ) ) {
				wp_schedule_event( time(), 'cetus_mo_five_minutes', 'cetus_mo_cron_batch' );
			}
		}

		wp_send_json_success(
			[
				'status'  => 'started',
				'total'   => $progress['total'],
				'message' => sprintf(
					/* translators: %d: numero di immagini in coda */
					__( 'Process started. %d images queued.', 'cetus-media-optimizer' ),
					$progress['total']
				),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handler AJAX: tick (un singolo batch)
	// -------------------------------------------------------------------------

	/**
	 * Elabora il prossimo batch di immagini in coda.
	 * Il frontend chiama questo endpoint in loop finché lo status non è 'done'.
	 *
	 * @return void
	 */
	public function ajax_tick(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$progress = $this->get_progress();

		if ( null === $progress || 'running' !== $progress['status'] ) {
			wp_send_json_error(
				[
					'status'  => $progress['status'] ?? 'idle',
					'message' => __( 'No active process.', 'cetus-media-optimizer' ),
				]
			);
		}

		$optimizer  = $this->get_optimizer();
		$ai         = $this->get_ai();
		$batch      = array_splice( $progress['queue'], 0, self::BATCH_SIZE );
		$errors_log = [];

		foreach ( $batch as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			// Salta gli allegati esclusi tramite il meta box.
			if ( $optimizer->is_attachment_excluded( $attachment_id ) ) {
				++$progress['skipped'];
				++$progress['processed'];
				continue;
			}

			$file = get_attached_file( $attachment_id );

			if ( ! $file ) {
				++$progress['errors'];
				++$progress['processed'];
				$errors_log[] = [
					'file'    => 'ID ' . $attachment_id,
					'message' => __( 'File not found on disk.', 'cetus-media-optimizer' ),
				];
				continue;
			}

			if ( ! $optimizer->conversion_disabled() ) {
				$result = $optimizer->convert_single( $file, $attachment_id );

				if ( $result['skipped'] ) {
					++$progress['skipped'];
				} elseif ( $result['success'] ) {
					++$progress['converted'];
					$progress['bytes_saved'] += $result['bytes_saved'];
				} else {
					++$progress['errors'];
					$errors_log[] = [
						'file'    => basename( $file ),
						'message' => $result['message'],
					];
				}
			} else {
				++$progress['skipped'];
			}

			// Genera alt text con AI se almeno una chiave è configurata.
			if ( $this->ai_enabled() ) {
				$ai->generate_and_save_alt_text( $attachment_id );
			}

			++$progress['processed'];
		}

		// Controlla se la coda è esaurita.
		if ( empty( $progress['queue'] ) ) {
			$progress['status'] = 'done';
			delete_transient( self::LOCK_KEY );
		}

		update_option( self::PROGRESS_KEY, $progress );

		wp_send_json_success(
			[
				'status'      => $progress['status'],
				'total'       => $progress['total'],
				'processed'   => $progress['processed'],
				'converted'   => $progress['converted'],
				'skipped'     => $progress['skipped'],
				'errors'      => $progress['errors'],
				'bytes_saved' => $progress['bytes_saved'],
				'errors_log'  => $errors_log,
				'percent'     => $progress['total'] > 0
					? (int) round( ( $progress['processed'] / $progress['total'] ) * 100 )
					: 100,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handler AJAX: pausa
	// -------------------------------------------------------------------------

	/**
	 * Mette in pausa il processo batch corrente (senza svuotare la coda).
	 *
	 * @return void
	 */
	public function ajax_pause(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$progress = $this->get_progress();

		if ( null === $progress || 'running' !== $progress['status'] ) {
			wp_send_json_error( [ 'message' => __( 'No process running.', 'cetus-media-optimizer' ) ] );
		}

		$progress['status'] = 'paused';
		update_option( self::PROGRESS_KEY, $progress );
		delete_transient( self::LOCK_KEY );

		wp_send_json_success(
			[
				'status'  => 'paused',
				'message' => __( 'Process paused.', 'cetus-media-optimizer' ),
			]
		);
	}

	/**
	 * Riprende un processo in pausa.
	 *
	 * @return void
	 */
	public function ajax_resume(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$progress = $this->get_progress();

		if ( null === $progress || 'paused' !== $progress['status'] ) {
			wp_send_json_error( [ 'message' => __( 'No paused process to resume.', 'cetus-media-optimizer' ) ] );
		}

		if ( get_transient( self::LOCK_KEY ) ) {
			wp_send_json_error( [ 'message' => __( 'Lock active: cannot resume.', 'cetus-media-optimizer' ) ] );
		}

		$progress['status'] = 'running';
		update_option( self::PROGRESS_KEY, $progress );
		set_transient( self::LOCK_KEY, '1', HOUR_IN_SECONDS );

		wp_send_json_success(
			[
				'status'  => 'running',
				'message' => __( 'Process resumed.', 'cetus-media-optimizer' ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handler AJAX: stop
	// -------------------------------------------------------------------------

	/**
	 * Ferma e azzera il processo batch corrente.
	 *
	 * @return void
	 */
	public function ajax_stop(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		delete_transient( self::LOCK_KEY );
		delete_option( self::PROGRESS_KEY );

		wp_send_json_success(
			[
				'status'  => 'stopped',
				'message' => __( 'Process stopped and queue cleared.', 'cetus-media-optimizer' ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handler AJAX: status
	// -------------------------------------------------------------------------

	/**
	 * Restituisce lo stato corrente del processo batch (polling leggero).
	 *
	 * @return void
	 */
	public function ajax_status(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$progress = $this->get_progress();

		if ( null === $progress ) {
			wp_send_json_success( [ 'status' => 'idle' ] );
		}

		wp_send_json_success(
			[
				'status'      => $progress['status'],
				'total'       => $progress['total'],
				'processed'   => $progress['processed'],
				'converted'   => $progress['converted'],
				'skipped'     => $progress['skipped'],
				'errors'      => $progress['errors'],
				'bytes_saved' => $progress['bytes_saved'],
				'percent'     => $progress['total'] > 0
					? (int) round( ( $progress['processed'] / $progress['total'] ) * 100 )
					: 0,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handler AJAX: file orfani
	// -------------------------------------------------------------------------

	/**
	 * Avvia la scansione dei file orfani e restituisce il conteggio.
	 *
	 * @return void
	 */
	public function ajax_scan_orphans(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$result = $this->get_optimizer()->find_orphaned_files();

		wp_send_json_success(
			[
				'count'       => $result['count'],
				'total_bytes' => $result['total_bytes'],
				'total_mb'    => round( $result['total_bytes'] / ( 1024 * 1024 ), 2 ),
				'message'     => sprintf(
					/* translators: %d: numero file orfani */
					__( 'Found %d orphan files.', 'cetus-media-optimizer' ),
					$result['count']
				),
			]
		);
	}

	/**
	 * Converte e ottimizza i file orfani trovati.
	 * Opera in modalità best-effort: non fa rollback in caso di errore parziale.
	 *
	 * @return void
	 */
	public function ajax_orphan_convert(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$optimizer = $this->get_optimizer();
		$scan      = $optimizer->find_orphaned_files();

		if ( 0 === $scan['count'] ) {
			wp_send_json_success( [ 'message' => __( 'No orphan files found.', 'cetus-media-optimizer' ) ] );
		}

		$converted   = 0;
		$bytes_saved = 0;

		foreach ( $scan['files'] as $file_path ) {
			$result = $optimizer->convert_single( $file_path, 0 );

			if ( $result['success'] ) {
				++$converted;
				$bytes_saved += $result['bytes_saved'];
			}
		}

		wp_send_json_success(
			[
				'converted'   => $converted,
				'bytes_saved' => $bytes_saved,
				'message'     => sprintf(
					/* translators: 1: file convertiti 2: MB risparmiati */
					__( '%1$d orphan files optimized. Savings: %2$s MB.', 'cetus-media-optimizer' ),
					$converted,
					number_format( $bytes_saved / ( 1024 * 1024 ), 2 )
				),
			]
		);
	}

	/**
	 * Avvia la conversione degli orfani: scansiona e salva la coda.
	 *
	 * @return void
	 */
	public function ajax_orphan_start(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$optimizer = $this->get_optimizer();
		$scan      = $optimizer->find_orphaned_files();
		$format    = $optimizer->resolve_format();

		// Filtra i file companion (già convertiti o già nel formato target).
		// I file .webp/.avif generati dal plugin non sono registrati in WP e finiscono
		// nell'elenco orfani, ma non devono essere riconvertiti.
		$files = array_values(
			array_filter(
				$scan['files'],
				static function ( string $file ) use ( $optimizer, $format ): bool {
					$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
					// Salta se il file è già nel formato di destinazione.
					if ( $ext === $format ) {
						return false;
					}
					// Salta se esiste già un file convertito accanto all'originale.
					$output = $optimizer->build_output_path( $file, $format );
					return ! file_exists( $output );
				}
			)
		);

		if ( empty( $files ) ) {
			// Nessun file da convertire: distingui "niente trovato" da "già tutti ottimizzati".
			$reason = $scan['count'] > 0 ? 'already_optimized' : 'none_found';
			wp_send_json_success(
				[
					'status' => 'empty',
					'reason' => $reason,
					'total'  => 0,
				]
			);
		}

		set_transient( 'cetus_mo_orphan_queue', $files, HOUR_IN_SECONDS );
		set_transient(
			'cetus_mo_orphan_progress',
			[
				'status'      => 'running',
				'total'       => count( $files ),
				'processed'   => 0,
				'converted'   => 0,
				'errors'      => 0,
				'bytes_saved' => 0,
			],
			HOUR_IN_SECONDS
		);

		wp_send_json_success(
			[
				'status' => 'started',
				'total'  => count( $files ),
			]
		);
	}

	/**
	 * Processa il prossimo batch di file orfani.
	 *
	 * @return void
	 */
	public function ajax_orphan_tick(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$queue    = get_transient( 'cetus_mo_orphan_queue' );
		$progress = get_transient( 'cetus_mo_orphan_progress' );

		if ( ! is_array( $queue ) || ! is_array( $progress ) ) {
			wp_send_json_error( [ 'message' => __( 'No active orphan process.', 'cetus-media-optimizer' ) ] );
		}

		// Rispetta lo stato pausa/stop.
		$status = $progress['status'] ?? 'running';
		if ( 'paused' === $status || 'stopped' === $status ) {
			wp_send_json_success(
				[
					'status'      => $status,
					'total'       => $progress['total'],
					'processed'   => $progress['processed'],
					'converted'   => $progress['converted'],
					'errors'      => $progress['errors'],
					'bytes_saved' => $progress['bytes_saved'],
					'errors_log'  => [],
					'percent'     => $progress['total'] > 0
						? (int) round( ( $progress['processed'] / $progress['total'] ) * 100 )
						: 100,
				]
			);
		}

		$optimizer  = $this->get_optimizer();
		$batch      = array_splice( $queue, 0, self::BATCH_SIZE );
		$errors_log = [];

		foreach ( $batch as $file_path ) {
			$result = $optimizer->convert_single( $file_path, 0 );
			if ( $result['success'] ) {
				++$progress['converted'];
				$progress['bytes_saved'] += $result['bytes_saved'];
			} elseif ( $result['skipped'] ) {
				++$progress['skipped'];
			} else {
				++$progress['errors'];
				$errors_log[] = [
					'file'    => basename( $file_path ),
					'message' => $result['message'],
				];
			}
			++$progress['processed'];
		}

		$done = empty( $queue );

		if ( $done ) {
			$progress['status'] = 'done';
			delete_transient( 'cetus_mo_orphan_queue' );
			delete_transient( 'cetus_mo_orphan_progress' );
		} else {
			set_transient( 'cetus_mo_orphan_queue', $queue, HOUR_IN_SECONDS );
			set_transient( 'cetus_mo_orphan_progress', $progress, HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			[
				'status'      => $done ? 'done' : 'running',
				'total'       => $progress['total'],
				'processed'   => $progress['processed'],
				'converted'   => $progress['converted'],
				'errors'      => $progress['errors'],
				'bytes_saved' => $progress['bytes_saved'],
				'errors_log'  => $errors_log,
				'percent'     => $progress['total'] > 0
					? (int) round( ( $progress['processed'] / $progress['total'] ) * 100 )
					: 100,
			]
		);
	}

	/**
	 * Mette in pausa l'elaborazione orfani.
	 *
	 * @return void
	 */
	public function ajax_orphan_pause(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$progress = get_transient( 'cetus_mo_orphan_progress' );
		if ( ! is_array( $progress ) ) {
			wp_send_json_error( [ 'message' => __( 'No active orphan process.', 'cetus-media-optimizer' ) ] );
		}

		$progress['status'] = 'paused';
		set_transient( 'cetus_mo_orphan_progress', $progress, HOUR_IN_SECONDS );

		wp_send_json_success( [ 'status' => 'paused' ] );
	}

	/**
	 * Riprende l'elaborazione orfani dalla pausa.
	 *
	 * @return void
	 */
	public function ajax_orphan_resume(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$progress = get_transient( 'cetus_mo_orphan_progress' );
		if ( ! is_array( $progress ) ) {
			wp_send_json_error( [ 'message' => __( 'No active orphan process.', 'cetus-media-optimizer' ) ] );
		}

		$progress['status'] = 'running';
		set_transient( 'cetus_mo_orphan_progress', $progress, HOUR_IN_SECONDS );

		wp_send_json_success( [ 'status' => 'running' ] );
	}

	/**
	 * Ferma e pulisce l'elaborazione orfani.
	 *
	 * @return void
	 */
	public function ajax_orphan_stop(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		delete_transient( 'cetus_mo_orphan_queue' );
		delete_transient( 'cetus_mo_orphan_progress' );

		wp_send_json_success( [ 'status' => 'stopped' ] );
	}

	// -------------------------------------------------------------------------
	// Handler AJAX: generazione Alt Text in massa
	// -------------------------------------------------------------------------

	/**
	 * Elabora un singolo tick di generazione Alt Text per le immagini che
	 * non hanno ancora il meta '_wp_attachment_image_alt' valorizzato.
	 * Riceve l'offset corrente dalla chiamata JS e restituisce i dati di
	 * avanzamento per il tick successivo.
	 *
	 * @return void
	 */
	public function ajax_alttext_tick(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_request()

		// Conta il totale delle immagini senza alt text (solo alla prima chiamata).
		$total = (int) ( new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				// Filtra solo quelli senza alt text o con alt text vuoto.
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
		) )->found_posts;

		// Recupera il batch corrente (offset + BATCH_SIZE).
		$query = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ],
				'posts_per_page' => self::BATCH_SIZE,
				'offset'         => $offset,
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

		$ids = array_map( 'intval', $query->posts );
		$ai  = $this->get_ai();

		$done_this_tick    = 0;
		$skipped_this_tick = 0;
		$errors_this_tick  = 0;

		foreach ( $ids as $attachment_id ) {
			// Doppio controllo: salta se nel frattempo è stato valorizzato.
			$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( '' !== trim( (string) $existing ) ) {
				++$skipped_this_tick;
				continue;
			}

			$result = $ai->generate_and_save_alt_text( $attachment_id );

			if ( $result['success'] ) {
				++$done_this_tick;
			} else {
				++$errors_this_tick;
			}
		}

		$processed_this_tick = count( $ids );

		wp_send_json_success(
			[
				'total'               => $total,
				'processed_this_tick' => $processed_this_tick,
				'done_this_tick'      => $done_this_tick,
				'skipped_this_tick'   => $skipped_this_tick,
				'errors_this_tick'    => $errors_this_tick,
				'done'                => $processed_this_tick < self::BATCH_SIZE,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handler AJAX: reset file convertiti
	// -------------------------------------------------------------------------

	/**
	 * Elimina tutti i file WebP/AVIF dalla cartella uploads e azzera log e savings.
	 *
	 * @return void
	 */
	public function ajax_reset_files(): void {
		$this->verify_request( 'cetus_mo_bulk_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'cetus-media-optimizer' ) ], 403 );
		}

		// Interrompe un eventuale batch in corso prima di cancellare i file.
		delete_transient( self::LOCK_KEY );
		delete_option( self::PROGRESS_KEY );

		$result = $this->get_optimizer()->reset_converted_files();

		wp_send_json_success(
			[
				'deleted' => $result['deleted'],
				'errors'  => $result['errors'],
				'message' => sprintf(
					/* translators: 1: file eliminati 2: errori */
					__( '%1$d files deleted. %2$d errors.', 'cetus-media-optimizer' ),
					$result['deleted'],
					$result['errors']
				),
			]
		);
	}

	// -------------------------------------------------------------------------
	// WP-Cron: batch in background
	// -------------------------------------------------------------------------

	/**
	 * Esegue un singolo tick del batch direttamente (senza HTTP), invocato da WP-Cron.
	 * Se la coda è vuota o il cron non è abilitato, termina silenziosamente.
	 *
	 * @return void
	 */
	public function run_cron_batch(): void {
		if ( '1' !== get_option( 'cetus_media_cron_enabled', '0' ) ) {
			return;
		}

		$progress = $this->get_progress();

		if ( null === $progress || 'running' !== $progress['status'] ) {
			wp_clear_scheduled_hook( 'cetus_mo_cron_batch' );
			return;
		}

		$optimizer  = $this->get_optimizer();
		$ai         = $this->get_ai();
		$time_start = microtime( true );
		$time_limit = 25.0; // secondi — margine sicuro sotto il timeout PHP di 30s.

		// Esegue tanti batch consecutivi finché c'è coda e tempo disponibile.
		while ( ! empty( $progress['queue'] ) && ( microtime( true ) - $time_start ) < $time_limit ) {
			$batch = array_splice( $progress['queue'], 0, self::BATCH_SIZE );

			foreach ( $batch as $attachment_id ) {
				$attachment_id = (int) $attachment_id;

				if ( $optimizer->is_attachment_excluded( $attachment_id ) ) {
					++$progress['skipped'];
					++$progress['processed'];
					continue;
				}

				$file = get_attached_file( $attachment_id );
				if ( ! $file ) {
					++$progress['errors'];
					++$progress['processed'];
					continue;
				}

				if ( ! $optimizer->conversion_disabled() ) {
					$result = $optimizer->convert_single( $file, $attachment_id );
					if ( $result['skipped'] ) {
						++$progress['skipped'];
					} elseif ( $result['success'] ) {
						++$progress['converted'];
						$progress['bytes_saved'] += $result['bytes_saved'];
					} else {
						++$progress['errors'];
					}
				} else {
					++$progress['skipped'];
				}

				if ( $this->ai_enabled() ) {
					$ai->generate_and_save_alt_text( $attachment_id );
				}

				++$progress['processed'];
			}

			update_option( self::PROGRESS_KEY, $progress );
		}

		if ( empty( $progress['queue'] ) ) {
			$progress['status'] = 'done';
			delete_transient( self::LOCK_KEY );
			wp_clear_scheduled_hook( 'cetus_mo_cron_batch' );
			update_option( self::PROGRESS_KEY, $progress );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers interni
	// -------------------------------------------------------------------------

	/**
	 * Verifica il nonce e i permessi di ogni richiesta AJAX.
	 * Termina l'esecuzione con un errore 403 se i controlli falliscono.
	 *
	 * @param string $nonce_action Azione del nonce da verificare.
	 * @return void
	 */
	private function verify_request( string $nonce_action ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'cetus-media-optimizer' ) ], 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request (nonce expired or missing).', 'cetus-media-optimizer' ) ], 403 );
		}
	}

	/**
	 * Costruisce la coda di tutti gli allegati immagine da ottimizzare.
	 *
	 * @return array<int> Array di attachment ID.
	 */
	private function build_queue(): array {
		$query = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$all_ids   = array_map( 'intval', $query->posts );
		$optimizer = $this->get_optimizer();

		// Filtra subito: include solo gli ID che hanno ancora almeno un file da convertire
		// (file principale o una delle size). Così il tick non salta immagini con
		// size non ancora convertite anche se il file principale esiste già.
		return array_values(
			array_filter(
				$all_ids,
				function ( int $id ) use ( $optimizer ): bool {
					if ( $optimizer->is_attachment_excluded( $id ) ) {
						return false;
					}
					$file = get_attached_file( $id );
					if ( ! $file || ! file_exists( $file ) ) {
						return false;
					}
					$format = $optimizer->resolve_format();
					// Il file principale non è ancora convertito → includi.
					if ( ! file_exists( $optimizer->build_output_path( $file, $format ) ) ) {
						return true;
					}
					// Il file principale è convertito: controlla se manca qualche size.
					$metadata = wp_get_attachment_metadata( $id );
					if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
						return false;
					}
					// Usa dirname del file principale (path assoluto garantito) per localizzare le size.
					$size_dir = trailingslashit( dirname( $file ) );
					foreach ( $metadata['sizes'] as $size_data ) {
						if ( empty( $size_data['file'] ) ) {
							continue;
						}
						$size_path = $size_dir . $size_data['file'];
						if ( file_exists( $size_path ) && ! file_exists( $optimizer->build_output_path( $size_path, $format ) ) ) {
							return true; // Almeno una size non è ancora convertita.
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * Legge lo stato del processo dal database.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_progress(): ?array {
		$progress = get_option( self::PROGRESS_KEY );
		return is_array( $progress ) ? $progress : null;
	}

	/**
	 * Istanza lazy dell'ottimizzatore.
	 *
	 * @return Cetus_MO_Optimizer
	 */
	private function get_optimizer(): Cetus_MO_Optimizer {
		if ( null === $this->optimizer ) {
			$this->optimizer = new Cetus_MO_Optimizer();
		}
		return $this->optimizer;
	}

	/**
	 * Istanza lazy del modulo AI.
	 *
	 * @return Cetus_MO_AI
	 */
	private function get_ai(): Cetus_MO_AI {
		if ( null === $this->ai ) {
			$this->ai = new Cetus_MO_AI();
		}
		return $this->ai;
	}

	/**
	 * Controlla se almeno un provider AI è configurato e abilitabile.
	 *
	 * @return bool
	 */
	private function ai_enabled(): bool {
		$gemini_key = get_option( 'cetus_media_gemini_key', '' );
		$openai_key = get_option( 'cetus_media_openai_key', '' );

		return ! empty( $gemini_key ) || ! empty( $openai_key );
	}
}
