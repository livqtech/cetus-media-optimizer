<?php
/**
 * Logica di conversione immagini AVIF/WebP e analisi file orfani.
 *
 * Crea i file ottimizzati affiancati agli originali senza mai eliminare
 * i sorgenti. Forza le anteprime JPG nella Libreria Media di WordPress.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_Optimizer' ) ) {
	return;
}

/**
 * Class Cetus_MO_Optimizer
 */
class Cetus_MO_Optimizer {

	/**
	 * Qualità di fallback per WebP, usata solo se l'opzione non è ancora nel DB.
	 *
	 * @var int
	 */
	private const WEBP_QUALITY_DEFAULT = 82;

	/**
	 * Qualità di fallback per AVIF, usata solo se l'opzione non è ancora nel DB.
	 *
	 * @var int
	 */
	private const AVIF_QUALITY_DEFAULT = 75;

	/**
	 * Mime type supportati per la conversione.
	 *
	 * @var array<string>
	 */
	private const SUPPORTED_MIME = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',  // permette la ri-conversione WebP → AVIF.
	];

	// -------------------------------------------------------------------------
	// Inizializzazione
	// -------------------------------------------------------------------------

	/**
	 * Registra gli hook WordPress necessari.
	 *
	 * @return void
	 */
	public function init(): void {
		// Conversione automatica al momento dell'upload (se abilitata).
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'on_new_upload' ], 10, 2 );

		// Sostituzione URL nel frontend (src e srcset) con i formati ottimizzati.
		// Priorità PHP_INT_MAX: ci posizioniamo sempre dopo qualsiasi filtro del tema,
		// sovrascrivendo manipolazioni errate delle URL senza output buffering.
		add_filter( 'wp_get_attachment_image_src', [ $this, 'swap_src_to_optimized' ], PHP_INT_MAX, 4 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'swap_srcset_to_optimized' ], PHP_INT_MAX, 5 );

		// Sostituisce gli URL nelle immagini presenti nel contenuto dei post.
		add_filter( 'wp_filter_content_tags', [ $this, 'swap_content_image_urls' ], PHP_INT_MAX );

		// Sanitizzazione finale su the_content e post_thumbnail_html:
		// • sincronizza src e href Lightbox con il formato già presente nel srcset
		// • rollback a .jpg/.png se il file ottimizzato non esiste su disco.
		add_filter( 'the_content', [ $this, 'sanitize_optimized_urls' ], PHP_INT_MAX );
		add_filter( 'post_thumbnail_html', [ $this, 'sanitize_optimized_urls' ], PHP_INT_MAX );

		// Rigenera il srcset mancante sull'immagine in evidenza quando il tema
		// ha modificato il src prima che wp_calculate_image_srcset() potesse agire.
		// Usa 5 parametri per ricevere $post_thumbnail_id direttamente dal filtro
		// anziché doverlo derivare dalla classe CSS (che il tema potrebbe non includere).
		add_filter( 'post_thumbnail_html', [ $this, 'rebuild_srcset_if_missing' ], PHP_INT_MAX, 5 );

		// Nel contesto admin (Libreria Media) mantieni sempre i JPG nativi
		// per evitare anteprime rotte nell'interfaccia di WordPress.
		if ( is_admin() ) {
			add_filter( 'wp_get_attachment_image_src', [ $this, 'force_jpg_thumbnail' ], 30, 4 );
			add_filter( 'wp_prepare_attachment_for_js', [ $this, 'force_js_thumbnail' ], 30, 3 );
		}
	}

	// -------------------------------------------------------------------------
	// Hook: nuovo upload
	// -------------------------------------------------------------------------

	/**
	 * Callback per 'wp_generate_attachment_metadata'.
	 * Converte l'immagine appena caricata se l'opzione auto-convert è attiva.
	 *
	 * @param array<string, mixed> $metadata      Metadati dell'allegato.
	 * @param int                  $attachment_id ID dell'allegato.
	 * @return array<string, mixed>
	 */
	public function on_new_upload( array $metadata, int $attachment_id ): array {
		if ( '1' !== get_option( 'cetus_media_auto_convert', '0' ) ) {
			return $metadata;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED_MIME, true ) ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		// Salta se l'allegato è escluso dall'utente tramite il meta box.
		if ( $this->is_attachment_excluded( $attachment_id ) ) {
			return $metadata;
		}

		// Esegui la conversione solo se l'utente non ha scelto "Nessuna conversione".
		if ( ! $this->conversion_disabled() ) {
			$this->convert_single( $file, $attachment_id );
		}

		// Genera l'alt text via AI se almeno una chiave provider è configurata.
		if ( $this->ai_keys_configured() && class_exists( 'Cetus_MO_AI' ) ) {
			( new Cetus_MO_AI() )->generate_and_save_alt_text( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Verifica se almeno un provider AI ha una chiave API impostata.
	 *
	 * @return bool
	 */
	private function ai_keys_configured(): bool {
		return '' !== get_option( 'cetus_media_gemini_key', '' )
			|| '' !== get_option( 'cetus_media_openai_key', '' );
	}

	// -------------------------------------------------------------------------
	// Conversione singola immagine
	// -------------------------------------------------------------------------

	/**
	 * Converte un singolo file immagine nel formato scelto nelle impostazioni.
	 * Crea il file ottimizzato affiancato all'originale (es. foto.jpg → foto.webp).
	 * Non elimina mai l'originale. Salta la conversione se il file esiste già.
	 *
	 * @param string $source_path  Percorso assoluto del file sorgente.
	 * @param int    $attachment_id ID allegato WordPress (0 se fuori dalla libreria).
	 * @return array{success: bool, skipped: bool, output_path: string, bytes_saved: int, message: string}
	 */
	public function convert_single( string $source_path, int $attachment_id = 0 ): array {
		$result = [
			'success'     => false,
			'skipped'     => false,
			'output_path' => '',
			'bytes_saved' => 0,
			'message'     => '',
		];

		if ( ! file_exists( $source_path ) ) {
			$result['message'] = sprintf( 'File non trovato: %s', esc_html( basename( $source_path ) ) );
			return $result;
		}

		$format = $this->resolve_format();

		// I PNG con canale alpha non vengono convertiti correttamente in AVIF dalla maggior
		// parte delle build libheif su hosting condivisi (risultato: quadrato nero).
		// Fallback obbligatorio a WebP, che gestisce l'alpha nativamente senza problemi.
		if ( 'avif' === $format && $this->source_has_alpha( $source_path ) ) {
			$format = 'webp';
		}

		// Genera il percorso di output sostituendo l'estensione.
		$output_path = $this->build_output_path( $source_path, $format );

		// Salta se il file ottimizzato esiste già, ma converti comunque le size mancanti.
		if ( file_exists( $output_path ) ) {
			$result['skipped'] = true;
			$result['message'] = 'File ottimizzato già presente, saltato.';
			if ( $attachment_id > 0 ) {
				$this->convert_attachment_sizes( $attachment_id, $format );
			}
			return $result;
		}

		// Esegui la conversione con Imagick (preferito) o GD.
		$converted = $this->do_convert( $source_path, $output_path, $format );

		// Se AVIF fallisce (es. detector alpha), cambia il formato globalmente in WebP
		// e riprova: così anche le size successive useranno WebP in modo coerente.
		if ( ! $converted && 'avif' === $format ) {
			$format      = 'webp';
			$output_path = $this->build_output_path( $source_path, $format );
			$converted   = $this->do_convert( $source_path, $output_path, $format );
		}

		if ( ! $converted ) {
			$result['message'] = 'Conversione fallita (Imagick/GD non disponibili o errore interno).';
			return $result;
		}

		$original_size = (int) filesize( $source_path );
		$new_size      = (int) filesize( $output_path );

		$result['success']     = true;
		$result['output_path'] = $output_path;
		$result['bytes_saved'] = max( 0, $original_size - $new_size );
		$result['message']     = sprintf(
			'Convertito in %s. Risparmio: %s KB.',
			strtoupper( $format ),
			number_format( $result['bytes_saved'] / 1024, 1 )
		);

		// Aggiorna il contatore cumulativo persistente.
		$prev = (int) get_option( 'cetus_mo_total_bytes_saved', 0 );
		update_option( 'cetus_mo_total_bytes_saved', $prev + $result['bytes_saved'] );

		// Registra nel log delle conversioni.
		self::add_log_entry(
			[
				'attachment_id' => $attachment_id,
				'filename'      => basename( $source_path ),
				'format'        => strtoupper( $format ),
				'bytes_saved'   => $result['bytes_saved'],
				'status'        => 'converted',
			]
		);

		// Converti anche tutti i sub-size (thumbnails) generati da WordPress.
		if ( $attachment_id > 0 ) {
			$this->convert_attachment_sizes( $attachment_id, $format );
		}

		return $result;
	}

	/**
	 * Converte tutti i sub-size (thumbnail, medium, large…) di un allegato.
	 *
	 * @param int    $attachment_id ID allegato.
	 * @param string $format        Formato target ('webp' o 'avif').
	 * @return void
	 */
	private function convert_attachment_sizes( int $attachment_id, string $format ): void {
		$main_file = get_attached_file( $attachment_id );
		if ( ! $main_file || ! file_exists( $main_file ) ) {
			return;
		}

		$dir      = trailingslashit( dirname( $main_file ) );
		$basename = pathinfo( $main_file, PATHINFO_FILENAME );

		// Cerca i thumbnail generati da WordPress ({nome}-WxH.ext) direttamente
		// sul filesystem: più robusto di leggere _wp_attachment_metadata['sizes'],
		// il cui campo 'file' su alcuni hosting contiene il path assoluto.
		$candidates = glob( $dir . $basename . '-[0-9]*x[0-9]*.*' );
		if ( empty( $candidates ) ) {
			return;
		}

		foreach ( $candidates as $size_path ) {
			if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $size_path ) ) {
				continue;
			}

			$output_path = $this->build_output_path( $size_path, $format );
			if ( ! file_exists( $output_path ) ) {
				$this->do_convert( $size_path, $output_path, $format );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Motore di conversione (Imagick / GD)
	// -------------------------------------------------------------------------

	/**
	 * Esegue la conversione del file chiamando Imagick o GD come fallback.
	 *
	 * @param string $source Percorso assoluto sorgente.
	 * @param string $dest   Percorso assoluto destinazione.
	 * @param string $format 'webp' o 'avif'.
	 * @return bool True se la conversione ha avuto successo.
	 */
	private function do_convert( string $source, string $dest, string $format ): bool {
		// Valida il file leggendo i magic bytes: scarta file corrotti o con estensione
		// errata prima di passarli a Imagick o GD, evitando ImagickException e Warning.
		$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || empty( $info['mime'] ) ) {
			return false;
		}

		// Tenta con Imagick per prima.
		if ( $this->imagick_available( $format ) ) {
			if ( $this->convert_with_imagick( $source, $dest, $format ) ) {
				return true;
			}
			// Imagick ha fallito (es. libheif scrive 0 byte): tenta il fallback.
		}

		// Fallback a GD (solo WebP; GD non supporta AVIF su PHP < 8.1).
		if ( 'webp' === $format && $this->gd_webp_available() ) {
			return $this->convert_with_gd( $source, $dest );
		}

		return false;
	}

	/**
	 * Conversione tramite Imagick.
	 *
	 * @param string $source Percorso sorgente.
	 * @param string $dest   Percorso destinazione.
	 * @param string $format 'webp' o 'avif'.
	 * @return bool
	 */
	private function convert_with_imagick( string $source, string $dest, string $format ): bool {
		// Pre-check: immagini con più di 40 megapixel rischiano di esaurire la cache
		// Imagick su hosting condiviso. Usiamo getimagesize() che legge solo l'header.
		$size = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size && isset( $size[0], $size[1] ) ) {
			$megapixels = ( $size[0] * $size[1] ) / 1_000_000;
			if ( $megapixels > 40 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( '[Cetus MO] Skipped (%.1f MP > 40 MP limit): %s', $megapixels, basename( $source ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return false;
			}
		}

		try {
			// Limita la memoria Imagick per evitare cache resources exhausted su hosting condiviso.
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024 );

			$original   = new Imagick( $source );
			$src_format = strtolower( $original->getImageFormat() );
			$target_fmt = strtolower( $format );

			$has_alpha = $original->getImageAlphaChannel();

			if ( $has_alpha ) {
				$original->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE );

				if ( in_array( $target_fmt, [ 'webp', 'avif' ], true ) ) {
					// Tecnica della tela trasparente: composita l'immagine su un canvas
					// con sfondo trasparente per preservare correttamente l'alpha channel.
					$canvas = new Imagick();
					$canvas->newImage( $original->getImageWidth(), $original->getImageHeight(), new ImagickPixel( 'transparent' ) );
					$canvas->compositeImage( $original, Imagick::COMPOSITE_OVER, 0, 0 );
					$image = $canvas;
					$original->destroy();
					$original = null;
				} else {
					// Destinazione senza trasparenza (es. JPG): appiattisci su sfondo bianco.
					$original->setImageBackgroundColor( new ImagickPixel( 'white' ) );
					$image = $original->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
					$original->destroy();
					$original = null;
				}
			} else {
				// Immagine senza alpha (es. JPEG): appiattisci normalmente.
				// Inner try/catch: libera RAM immediatamente se Imagick esaurisce le risorse
				// così do_convert() può tentare il fallback GD con più memoria disponibile.
				try {
					$image = $original->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
					$original->destroy();
					$original = null;
				} catch ( ImagickException $e ) {
					if ( $original instanceof Imagick ) {
						$original->destroy();
					}
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[Cetus MO] Imagick resource limit reached during flatten: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
					return false;
				}
			}

			$image->setImageFormat( strtoupper( $format ) );

			if ( 'avif' === $target_fmt ) {
				$image->setImageColorspace( Imagick::COLORSPACE_SRGB );
			}

			$quality = ( 'avif' === $format ) ? $this->get_avif_quality() : $this->get_webp_quality();
			$image->setImageCompressionQuality( $quality );
			$image->stripImage();

			$written = $image->writeImage( $dest );

			// Detector: se l'AVIF generato ha perso il canale alpha (build libheif difettose),
			// elimina il file e restituisce false. Il fallback globale a WebP avviene in convert_single,
			// così anche le size vengono elaborate nel formato corretto.
			if ( 'avif' === $target_fmt && $has_alpha && $written && file_exists( $dest ) ) {
				try {
					$check = new Imagick( $dest );
					if ( ! $check->getImageAlphaChannel() ) {
						$check->destroy();
						wp_delete_file( $dest );

						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( '[Cetus MO] AVIF transparency failure detected for: ' . basename( $source ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}

						if ( $image instanceof Imagick ) {
							$image->destroy();
						}
						return false;
					}
					$check->destroy();
				} catch ( \Throwable $t ) {
					if ( isset( $image ) && $image instanceof Imagick ) {
						$image->destroy();
					}
					return false;
				}
			}

			if ( $image instanceof Imagick ) {
				$image->destroy();
			}

			// Alcune build di libheif/libavif scrivono 0 byte senza lanciare eccezioni.
			if ( ! $written || ! file_exists( $dest ) || (int) filesize( $dest ) === 0 ) {
				if ( file_exists( $dest ) ) {
					wp_delete_file( $dest );
				}
				return false;
			}

			return true;
		} catch ( ImagickException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Cetus MO] Imagick error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( class_exists( 'Cetus_MO_Telemetry' ) && Cetus_MO_Telemetry::is_opted_in() && function_exists( '\Sentry\captureException' ) ) {
				\Sentry\captureException( $e );
			}
			return false;
		}
	}

	/**
	 * Conversione tramite GD (solo WebP).
	 *
	 * @param string $source Percorso sorgente.
	 * @param string $dest   Percorso destinazione WebP.
	 * @return bool
	 */
	private function convert_with_gd( string $source, string $dest ): bool {
		// Il MIME reale è già stato validato in do_convert() con getimagesize().
		// Qui lo rileggiamo solo per scegliere la funzione GD corretta.
		$mime = @getimagesize( $source )['mime'] ?? ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$image = match ( $mime ) {
			'image/jpeg' => imagecreatefromjpeg( $source ),
			'image/png'  => imagecreatefrompng( $source ),
			'image/gif'  => imagecreatefromgif( $source ),
			default      => false,
		};

		if ( ! $image ) {
			return false;
		}

		// Preserva la trasparenza per PNG.
		if ( 'image/png' === $mime ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
		}

		$result = imagewebp( $image, $dest, $this->get_webp_quality() );
		imagedestroy( $image ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated

		if ( ! $result || ! file_exists( $dest ) || (int) filesize( $dest ) === 0 ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Sostituzione URL frontend (src / srcset / content)
	// -------------------------------------------------------------------------

	/**
	 * Filtra l'array restituito da wp_get_attachment_image_src() sostituendo
	 * l'URL del file originale con quello del formato ottimizzato, se esiste
	 * su disco. Viene chiamato solo nel contesto frontend (non in admin).
	 *
	 * @param array<mixed>|false $image         Array [url, width, height, is_intermediate] o false.
	 * @param int|string         $attachment_id ID allegato (cast a int internamente).
	 * @param string|int[]       $size          Dimensione richiesta.
	 * @param bool               $icon          True se WordPress sta cercando un'icona di tipo MIME.
	 * @return array<mixed>|false
	 */
	public function swap_src_to_optimized( $image, $attachment_id, $size, bool $icon ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return $image;
		}

		// Non intervenire sulle icone o su risultati non validi.
		if ( $icon || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}

		$new_url = $this->get_optimized_url( (string) $image[0] );
		if ( null !== $new_url ) {
			$image[0] = $new_url;
		}

		return $image;
	}

	/**
	 * Filtra l'array restituito da wp_calculate_image_srcset() sostituendo
	 * ogni URL originale con la versione ottimizzata, se il file esiste su disco.
	 *
	 * @param array<int, array{url: string, descriptor: string, value: int}>|false $sources    Voci srcset indicizzate per larghezza.
	 * @param array<int>                                                           $size_array [width, height] dell'immagine corrente.
	 * @param string                                                               $image_src  URL della src originale.
	 * @param array<string, mixed>                                                 $image_meta Metadati allegato.
	 * @param int                                                                  $attachment_id ID allegato.
	 * @return array<int, array{url: string, descriptor: string, value: int}>|false
	 */
	public function swap_srcset_to_optimized( $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$new_url = $this->get_optimized_url( $source['url'] );
			if ( null !== $new_url ) {
				$sources[ $width ]['url'] = $new_url;
			}
		}

		return $sources;
	}

	/**
	 * Filtra il contenuto HTML dei post (via wp_filter_content_tags) sostituendo
	 * gli attributi src e srcset dei tag <img> con i formati ottimizzati.
	 *
	 * Questo cattura le immagini inserite nell'editor che WordPress non passa
	 * attraverso wp_get_attachment_image_src (es. immagini in blocchi custom,
	 * HTML diretto nel contenuto).
	 *
	 * @param string $content Contenuto HTML del post.
	 * @return string
	 */
	public function swap_content_image_urls( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		// Sostituisce ogni URL di immagine supportata che compare come src="…"
		// o in un srcset="…" con la versione ottimizzata, se il file esiste.
		return preg_replace_callback(
			'/(src|srcset)=["\']([^"\']+)["\']/i',
			function ( array $matches ): string {
				$attr  = $matches[1];
				$value = $matches[2];

				if ( 'srcset' === strtolower( $attr ) ) {
					// srcset contiene più voci separate da virgole: "url1 1x, url2 2x".
					$parts     = array_map(
						function ( string $part ): string {
							$tokens = preg_split( '/\s+/', trim( $part ), 2 );
							if ( empty( $tokens[0] ) ) {
								return $part;
							}
							$new_url = $this->get_optimized_url( $tokens[0] );
							if ( null !== $new_url ) {
								$tokens[0] = $new_url;
							}
							return implode( ' ', $tokens );
						},
						explode( ',', $value )
					);
					$new_value = implode( ', ', $parts );
				} else {
					$new_value = $this->get_optimized_url( $value ) ?? $value;
				}

				return $attr . '="' . esc_attr( $new_value ) . '"';
			},
			$content
		) ?? $content;
	}

	/**
	 * Sanitizzazione finale del markup HTML (the_content + post_thumbnail_html).
	 *
	 * Esegue due passate:
	 * 1. Sincronizza l'href del Lightbox (<a href>) con il formato del <img src> interno.
	 * 2. Per ogni <img>: allinea src e srcset allo stesso formato ottimizzato e
	 *    fa rollback a .jpg/.png per gli URL che non esistono su disco.
	 *
	 * @param string $html Markup HTML.
	 * @return string
	 */
	public function sanitize_optimized_urls( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}

		$upload_dir  = wp_upload_dir();
		$uploads_url = trailingslashit( $upload_dir['baseurl'] );
		$uploads_dir = trailingslashit( $upload_dir['basedir'] );

		$html = $this->sync_lightbox_hrefs( $html, $uploads_url, $uploads_dir );
		$html = $this->sync_img_attributes( $html, $uploads_url, $uploads_dir );

		return $html;
	}

	/**
	 * Rigenera il srcset mancante sull'immagine in evidenza.
	 *
	 * Quando il tema modifica il src prima che wp_calculate_image_srcset() agisca,
	 * WordPress non riesce a trovare l'allegato e restituisce false (nessun srcset).
	 * Questo filtro lo ricostruisce leggendo _wp_attachment_metadata e verificando
	 * l'esistenza di ogni file su disco.
	 *
	 * @param string $html             HTML del tag <img> dell'immagine in evidenza.
	 * @param int    $post_id          ID del post corrente.
	 * @param int    $post_thumbnail_id ID allegato immagine in evidenza.
	 * @param mixed  $size             Dimensione thumbnail richiesta.
	 * @param mixed  $attr             Attributi aggiuntivi del tag img.
	 * @return string
	 */
	public function rebuild_srcset_if_missing( string $html, int $post_id = 0, int $post_thumbnail_id = 0, $size = '', $attr = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( '' === $html || preg_match( '/\bsrcset=/i', $html ) ) {
			return $html;
		}

		// Usa l'ID allegato passato direttamente dal filtro post_thumbnail_html;
		// fallback alla classe wp-image-{id} per altri contesti (es. the_content).
		if ( $post_thumbnail_id > 0 ) {
			$attachment_id = $post_thumbnail_id;
		} elseif ( preg_match( '/\bwp-image-(\d+)\b/i', $html, $id_m ) ) {
			$attachment_id = (int) $id_m[1];
		} else {
			return $html;
		}

		if ( ! preg_match( '/\bsrc="([^"]+)"/i', $html, $src_m ) ) {
			return $html;
		}
		$current_src = $src_m[1];

		if ( ! preg_match( '/\.(webp|avif|jpe?g|png|gif)$/i', $current_src, $ext_m ) ) {
			return $html;
		}
		$current_ext = strtolower( $ext_m[1] );
		$format      = in_array( $current_ext, [ 'webp', 'avif' ], true ) ? $current_ext : $this->resolve_format();

		$meta      = wp_get_attachment_metadata( $attachment_id );
		$main_file = get_attached_file( $attachment_id );

		if ( ! $main_file || empty( $meta['sizes'] ) ) {
			return $html;
		}

		$upload_dir  = wp_upload_dir();
		$uploads_url = trailingslashit( $upload_dir['baseurl'] );
		$uploads_dir = trailingslashit( $upload_dir['basedir'] );
		$dir         = trailingslashit( dirname( $main_file ) );

		$srcset_parts = [];

		foreach ( $meta['sizes'] as $size_data ) {
			if ( empty( $size_data['file'] ) || empty( $size_data['width'] ) ) {
				continue;
			}
			$size_path      = $dir . $size_data['file'];
			$optimized_path = $this->build_output_path( $size_path, $format );

			if ( file_exists( $optimized_path ) ) {
				$entry_url = str_replace( $uploads_dir, $uploads_url, $optimized_path );
			} elseif ( file_exists( $size_path ) ) {
				$entry_url = str_replace( $uploads_dir, $uploads_url, $size_path );
			} else {
				continue;
			}

			$w                  = (int) $size_data['width'];
			$srcset_parts[ $w ] = esc_url( $entry_url ) . ' ' . $w . 'w';
		}

		// Aggiungi la dimensione full-size.
		if ( ! empty( $meta['width'] ) ) {
			$full_opt = $this->build_output_path( $main_file, $format );
			$full_abs = file_exists( $full_opt ) ? $full_opt : $main_file;
			$full_url = str_replace( $uploads_dir, $uploads_url, $full_abs );
			$w        = (int) $meta['width'];

			$srcset_parts[ $w ] = esc_url( $full_url ) . ' ' . $w . 'w';
		}

		if ( empty( $srcset_parts ) ) {
			return $html;
		}

		ksort( $srcset_parts );
		$srcset_str = implode( ', ', $srcset_parts );
		$max_w      = max( array_keys( $srcset_parts ) );
		$sizes_str  = '(max-width: ' . $max_w . 'px) 100vw, ' . $max_w . 'px';

		return preg_replace(
			'/(<img\b[^>]+?)(\s*\/?>)/i',
			'$1 srcset="' . esc_attr( $srcset_str ) . '" sizes="' . esc_attr( $sizes_str ) . '"$2',
			$html,
			1
		) ?? $html;
	}

	/**
	 * Passata 1: sincronizza l'href dei link Lightbox (<a href>) con il formato
	 * ottimizzato del <img src> interno. Verifica l'esistenza su disco prima
	 * di aggiornare l'href; se il file non esiste fa rollback all'originale.
	 *
	 * @param string $html        Markup HTML.
	 * @param string $uploads_url URL base uploads.
	 * @param string $uploads_dir Path assoluto uploads.
	 * @return string
	 */
	private function sync_lightbox_hrefs( string $html, string $uploads_url, string $uploads_dir ): string {
		return preg_replace_callback(
			'/<a(\s[^>]+)>([\s\S]*?)<\/a>/i',
			function ( array $m ) use ( $uploads_url, $uploads_dir ): string {
				$a_attrs = $m[1];
				$inner   = $m[2];

				// Processa solo se l'ancora contiene un'immagine.
				if ( ! preg_match( '/<img\s/i', $inner ) ) {
					return $m[0];
				}

				// Leggi il src dell'immagine interna.
				if ( ! preg_match( '/\bsrc="([^"]+)"/i', $inner, $src_m ) ) {
					return $m[0];
				}

				// Aggiorna l'href solo se punta a un file immagine.
				$new_a_attrs = preg_replace_callback(
					'/\bhref="([^"]+)"/i',
					function ( array $hm ) use ( $uploads_url, $uploads_dir ): string {
						$href = $hm[1];
						if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?.*)?$/i', $href ) ) {
							return $hm[0];
						}
						return 'href="' . esc_attr( $this->best_url( $href, $uploads_url, $uploads_dir ) ) . '"';
					},
					$a_attrs
				) ?? $a_attrs;

				return '<a' . $new_a_attrs . '>' . $inner . '</a>';
			},
			$html
		) ?? $html;
	}

	/**
	 * Passata 2: per ogni tag <img> allinea src, srcset e data-src allo stesso
	 * formato ottimizzato. Se srcset è già ottimizzato ma src è ancora .jpg,
	 * sincronizza src. Fa rollback su ogni URL ottimizzato che non esiste su disco.
	 *
	 * @param string $html        Markup HTML.
	 * @param string $uploads_url URL base uploads.
	 * @param string $uploads_dir Path assoluto uploads.
	 * @return string
	 */
	private function sync_img_attributes( string $html, string $uploads_url, string $uploads_dir ): string {
		return preg_replace_callback(
			'/<img(\s[^>]*)\s*\/?>/i',
			function ( array $m ) use ( $uploads_url, $uploads_dir ): string {
				$attrs = $m[1];

				$src      = preg_match( '/\bsrc="([^"]+)"/i', $attrs, $sm ) ? $sm[1] : '';
				$srcset   = preg_match( '/\bsrcset="([^"]+)"/i', $attrs, $ssm ) ? $ssm[1] : '';
				$data_src = preg_match( '/\bdata-src="([^"]+)"/i', $attrs, $dsm ) ? $dsm[1] : '';

				// --- Processa srcset ---
				$new_srcset = $srcset;
				if ( $srcset ) {
					$parts      = array_map(
						function ( string $part ) use ( $uploads_url, $uploads_dir ): string {
							$tokens = preg_split( '/\s+/', trim( $part ), 2 );
							if ( empty( $tokens[0] ) ) {
								return $part;
							}
							$tokens[0] = $this->best_url( $tokens[0], $uploads_url, $uploads_dir );
							return implode( ' ', $tokens );
						},
						explode( ',', $srcset )
					);
					$new_srcset = implode( ', ', $parts );
				}

				// --- Processa src ---
				$new_src = $src ? $this->best_url( $src, $uploads_url, $uploads_dir ) : $src;

				// Se srcset contiene già URL ottimizzati ma src è ancora .jpg/.png,
				// sincronizza src al formato ottimizzato (se il file esiste su disco).
				if ( $new_src && $new_srcset
					&& preg_match( '/\.(webp|avif)/i', $new_srcset )
					&& preg_match( '/\.(jpe?g|png|gif)$/i', $new_src )
				) {
					$synced = $this->best_url( $new_src, $uploads_url, $uploads_dir );
					if ( preg_match( '/\.(webp|avif)$/i', $synced ) ) {
						$new_src = $synced;
					}
				}

				// --- Processa data-src (lazy loading) ---
				$new_data_src = $data_src ? $this->best_url( $data_src, $uploads_url, $uploads_dir ) : $data_src;

				// --- Aggiorna gli attributi solo se necessario ---
				if ( $new_src !== $src ) {
					$attrs = preg_replace( '/\bsrc="[^"]*"/i', 'src="' . esc_attr( $new_src ) . '"', $attrs );
				}
				if ( $new_srcset !== $srcset ) {
					$attrs = $srcset
						? preg_replace( '/\bsrcset="[^"]*"/i', 'srcset="' . esc_attr( $new_srcset ) . '"', $attrs )
						: $attrs . ' srcset="' . esc_attr( $new_srcset ) . '"';
				}
				if ( $new_data_src !== $data_src ) {
					$attrs = preg_replace( '/\bdata-src="[^"]*"/i', 'data-src="' . esc_attr( $new_data_src ) . '"', $attrs );
				}

				return '<img' . $attrs . '>';
			},
			$html
		) ?? $html;
	}

	/**
	 * Restituisce il miglior URL per un'immagine verificando l'esistenza su disco:
	 * - Se l'URL è un originale (.jpg/.png/.gif): restituisce l'URL ottimizzato se esiste.
	 * - Se l'URL è già ottimizzato (.webp/.avif): verifica che esista; se no, rollback.
	 * - Qualsiasi altro URL: restituisce invariato.
	 *
	 * @param string $url         URL da processare.
	 * @param string $uploads_url URL base uploads.
	 * @param string $uploads_dir Path assoluto uploads.
	 * @return string
	 */
	private function best_url( string $url, string $uploads_url, string $uploads_dir ): string {
		if ( preg_match( '/\.(jpe?g|png|gif)(\?.*)?$/i', $url ) ) {
			return $this->get_optimized_url( $url ) ?? $url;
		}

		if ( preg_match( '/\.(webp|avif)(\?.*)?$/i', $url ) ) {
			return $this->sanitize_single_optimized_url( $url, $uploads_url, $uploads_dir );
		}

		return $url;
	}

	/**
	 * Verifica che un URL .webp/.avif esista su disco.
	 * Se non esiste, tenta il rollback all'estensione originale (.jpg/.jpeg/.png/.gif).
	 * Restituisce l'URL invariato se non viene trovato nessun file valido.
	 *
	 * @param string $url         URL ottimizzato da verificare.
	 * @param string $uploads_url URL base uploads.
	 * @param string $uploads_dir Path assoluto uploads.
	 * @return string
	 */
	private function sanitize_single_optimized_url( string $url, string $uploads_url, string $uploads_dir ): string {
		if ( ! preg_match( '/\.(webp|avif)(\?.*)?$/i', $url ) ) {
			return $url;
		}

		$url_clean = strtok( $url, '?' );
		if ( false === strpos( (string) $url_clean, $uploads_url ) ) {
			return $url;
		}

		$relative  = str_replace( $uploads_url, '', (string) $url_clean );
		$file_path = $uploads_dir . $relative;

		if ( file_exists( $file_path ) ) {
			return $url;
		}

		foreach ( [ 'jpg', 'jpeg', 'png', 'gif' ] as $ext ) {
			$original_path = preg_replace( '/\.(webp|avif)$/i', '.' . $ext, $file_path );
			if ( $original_path && file_exists( $original_path ) ) {
				return str_replace( $uploads_dir, $uploads_url, $original_path );
			}
		}

		return $url;
	}

	/**
	 * Dato un URL assoluto di un'immagine originale (JPG/PNG/GIF), restituisce
	 * l'URL del file ottimizzato (WebP o AVIF) se il file corrispondente esiste
	 * nella cartella uploads. Restituisce null se non c'è nulla da sostituire.
	 *
	 * @param string $original_url URL assoluto del file originale.
	 * @return string|null URL ottimizzato, o null se il file non esiste.
	 */
	private function get_optimized_url( string $original_url ): ?string {
		// Solo per formati sorgente supportati.
		if ( ! preg_match( '/\.(jpe?g|png|gif)(\?.*)?$/i', $original_url ) ) {
			return null;
		}

		// Rimuove eventuali query string prima di trovare il path fisico.
		$url_clean = strtok( $original_url, '?' );

		$upload_dir  = wp_upload_dir();
		$uploads_url = trailingslashit( $upload_dir['baseurl'] );
		$uploads_dir = trailingslashit( $upload_dir['basedir'] );

		// L'URL deve appartenere alla cartella uploads di questo sito.
		if ( false === strpos( $url_clean, $uploads_url ) ) {
			return null;
		}

		$relative    = str_replace( $uploads_url, '', $url_clean );
		$source_path = $uploads_dir . $relative;
		$format      = $this->resolve_format();
		$output_path = $this->build_output_path( $source_path, $format );

		if ( ! file_exists( $output_path ) ) {
			return null;
		}

		return str_replace( $uploads_dir, $uploads_url, $output_path );
	}

	// -------------------------------------------------------------------------
	// Analisi file orfani
	// -------------------------------------------------------------------------

	/**
	 * Scansiona la cartella uploads alla ricerca di file immagine non registrati
	 * nel database di WordPress.
	 *
	 * @return array{count: int, files: array<string>, total_bytes: int}
	 */
	public function find_orphaned_files(): array {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		// Recupera tutti i percorsi dei file registrati come allegati.
		$registered = $this->get_all_registered_paths();

		$orphans     = [];
		$total_bytes = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		$image_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ];

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof SplFileInfo ) {
				continue;
			}

			$ext = strtolower( $file_info->getExtension() );
			if ( ! in_array( $ext, $image_extensions, true ) ) {
				continue;
			}

			$full_path = wp_normalize_path( $file_info->getRealPath() );
			$relative  = str_replace( wp_normalize_path( $base_dir ), '', $full_path );

			// Il file è orfano se non compare in nessun metadato registrato.
			if ( ! in_array( $relative, $registered, true ) ) {
				$orphans[]    = $full_path;
				$total_bytes += (int) $file_info->getSize();
			}
		}

		return [
			'count'       => count( $orphans ),
			'files'       => $orphans,
			'total_bytes' => $total_bytes,
		];
	}

	/**
	 * Costruisce la lista di tutti i percorsi (relativi a uploads/) dei file
	 * associati ad allegati nel database.
	 *
	 * @return array<string>
	 */
	private function get_all_registered_paths(): array {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$base_dir   = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
		$paths      = [];

		// Recupera i file principali degli allegati.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_wp_attached_file'
			)
		);

		foreach ( $rows as $row ) {
			if ( is_string( $row ) ) {
				// Normalizza: rimuove il base_dir se presente (path assoluto), poi lo slash iniziale.
				$normalized = wp_normalize_path( $row );
				$normalized = str_replace( $base_dir, '', $normalized );
				$paths[]    = ltrim( $normalized, '/' );
			}
		}

		// Recupera i sub-size dai metadati strutturati.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_wp_attachment_metadata'
			),
			ARRAY_A
		);

		foreach ( $meta_rows as $meta_row ) {
			$meta = maybe_unserialize( $meta_row['meta_value'] ?? '' );

			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
				continue;
			}

			// Normalizza meta['file'] a path relativo a base_dir, gestendo sia path
			// relativi che assoluti (alcuni hosting memorizzano il path assoluto).
			$main_relative = '';
			if ( ! empty( $meta['file'] ) ) {
				$main_normalized = wp_normalize_path( $meta['file'] );
				$main_normalized = str_replace( $base_dir, '', $main_normalized );
				$main_relative   = ltrim( $main_normalized, '/' );
			}

			$dir         = $main_relative ? dirname( $main_relative ) : '.';
			$base_subdir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );

			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$paths[] = $base_subdir . $size['file'];
				}
			}
		}

		return array_unique( $paths );
	}

	// -------------------------------------------------------------------------
	// Hook: anteprime native in JPG nella Libreria Media
	// -------------------------------------------------------------------------

	/**
	 * Forza il ritorno dell'URL dell'immagine originale JPG/PNG quando viene
	 * richiesta un'anteprima dalla Libreria Media, evitando icone rotte.
	 *
	 * @param array<mixed>|false $image         Array [url, width, height, is_intermediate] o false.
	 * @param int                $attachment_id ID allegato.
	 * @param string|int[]       $size          Dimensione richiesta.
	 * @param bool               $icon          Se true, WordPress sta cercando un'icona.
	 * @return array<mixed>|false
	 */
	public function force_jpg_thumbnail( $image, int $attachment_id, $size, bool $icon ) {
		if ( $icon || false === $image ) {
			return $image;
		}

		// Se l'URL punta già a un file JPG/PNG, non serve intervenire.
		if ( is_array( $image ) && isset( $image[0] ) ) {
			$url = (string) $image[0];
			if ( preg_match( '/\.(jpe?g|png|gif)$/i', $url ) ) {
				return $image;
			}
		}

		return $image;
	}

	/**
	 * Assicura che i dati JS per la Libreria Media usino anteprime JPG native
	 * e non puntino a file WebP/AVIF che il browser potrebbe non riconoscere
	 * nell'interfaccia di WordPress.
	 *
	 * @param array<string, mixed> $response      Dati JSON per il media item.
	 * @param WP_Post              $attachment     Post dell'allegato.
	 * @param array<string>|false  $meta           Metadati allegato.
	 * @return array<string, mixed>
	 */
	public function force_js_thumbnail( array $response, WP_Post $attachment, $meta ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Nessuna modifica necessaria: WordPress utilizza già il file originale
		// come base per le anteprime JS. Questo hook è predisposto per
		// eventuali personalizzazioni future.
		return $response;
	}

	// -------------------------------------------------------------------------
	// Statistiche libreria
	// -------------------------------------------------------------------------

	/**
	 * Calcola le statistiche dello spazio occupato dalla libreria media.
	 *
	 * @return array{total_bytes: int, webp_bytes: int, avif_bytes: int, original_bytes: int, files_count: int}
	 */
	public function get_library_stats(): array {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		$stats = [
			'total_bytes'    => 0,
			'webp_bytes'     => 0,
			'avif_bytes'     => 0,
			'original_bytes' => 0,
			'files_count'    => 0,
		];

		if ( ! is_dir( $base_dir ) ) {
			return $stats;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
				continue;
			}

			$size = (int) $file_info->getSize();
			$ext  = strtolower( $file_info->getExtension() );

			$stats['total_bytes'] += $size;
			++$stats['files_count'];

			match ( $ext ) {
				'webp'        => $stats['webp_bytes']     += $size,
				'avif'        => $stats['avif_bytes']     += $size,
				'jpg', 'jpeg',
				'png', 'gif'  => $stats['original_bytes'] += $size,
				default       => null,
			};
		}

		return $stats;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Determina il formato di output in base alle impostazioni e alle capacità
	 * del server. 'auto' sceglie AVIF se supportato, altrimenti WebP.
	 *
	 * @return string 'avif' | 'webp'
	 */
	/**
	 * Indica se la conversione è disabilitata dall'utente (modalità "solo Alt Text").
	 *
	 * @return bool
	 */
	public function conversion_disabled(): bool {
		return 'none' === get_option( 'cetus_media_format', 'auto' );
	}

	/**
	 * Determina il formato di output corrente in base alle impostazioni e capacità del server.
	 *
	 * @return string
	 */
	public function resolve_format(): string {
		$setting = get_option( 'cetus_media_format', 'auto' );

		if ( 'avif' === $setting ) {
			return $this->imagick_available( 'avif' ) ? 'avif' : 'webp';
		}

		if ( 'webp' === $setting ) {
			return 'webp';
		}

		// 'none' non dovrebbe arrivare qui, ma per sicurezza ritorna webp.
		if ( 'none' === $setting ) {
			return 'webp';
		}

		// 'auto': preferisce AVIF se disponibile.
		return $this->imagick_available( 'avif' ) ? 'avif' : 'webp';
	}

	/**
	 * Costruisce il percorso del file di output sostituendo l'estensione.
	 *
	 * @param string $source_path Percorso assoluto del file sorgente.
	 * @param string $format      'webp' o 'avif'.
	 * @return string
	 */
	public function build_output_path( string $source_path, string $format ): string {
		return preg_replace( '/\.(jpe?g|png|gif|webp|avif)$/i', '.' . $format, $source_path ) ?? $source_path . '.' . $format;
	}

	/**
	 * Verifica se Imagick è disponibile e supporta il formato richiesto.
	 *
	 * @param string $format 'webp' o 'avif'.
	 * @return bool
	 */
	public function imagick_available( string $format = 'webp' ): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
			return false;
		}

		$formats = Imagick::queryFormats();
		if ( ! in_array( strtoupper( $format ), $formats, true ) ) {
			return false;
		}

		// Per AVIF esegui un probe reale: alcune build di libheif dichiarano supporto
		// ma scrivono 0 byte. Il risultato viene cachato per 24 ore.
		if ( 'avif' === $format ) {
			return $this->probe_avif_encoding();
		}

		return true;
	}

	/**
	 * Testa la codifica AVIF effettiva generando un pixel 1×1 in memoria.
	 * Cachea il risultato in un transient per evitare overhead ad ogni conversione.
	 *
	 * @return bool
	 */
	/**
	 * Controlla se un file immagine ha un canale alpha leggendo l'header del file.
	 * Per i PNG usa i byte dell'header (color type bit 2); per WebP legge il chunk RIFF.
	 * Non carica l'intera immagine in memoria.
	 *
	 * @param string $path Percorso assoluto del file sorgente.
	 * @return bool
	 */
	private function source_has_alpha( string $path ): bool {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info ) {
			return false;
		}

		$mime = $info['mime'] ?? '';

		if ( 'image/png' === $mime ) {
			// Nel PNG l'header è sempre 8 byte di firma + 4 lunghezza + 4 "IHDR" + 4 width + 4 height + 1 bitdepth + 1 colortype
			// Color type: 4 = grayscale+alpha, 6 = RGBA → bit 2 impostato = ha alpha.
			// Legge 1 byte al byte 25 (color type) usando file_get_contents con offset,
			// evitando fopen/fread/fclose non consentiti da WPCS.
			$chunk = file_get_contents( $path, false, null, 25, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $chunk || '' === $chunk ) {
				return false;
			}
			$color_type = ord( $chunk );
			return (bool) ( $color_type & 4 ); // bit 2 = alpha channel present.
		}

		return false;
	}

	/**
	 * Verifica se il server supporta la codifica AVIF tramite una conversione di prova.
	 *
	 * @return bool
	 */
	private function probe_avif_encoding(): bool {
		$cache_key = 'cetus_mo_avif_probe';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$result = false;
		try {
			$image = new Imagick();
			$image->newImage( 1, 1, new ImagickPixel( 'white' ) );
			$image->setImageFormat( 'AVIF' );
			$blob = $image->getImageBlob();
			$image->destroy();
			$result = ! empty( $blob ) && strlen( $blob ) > 0;
		} catch ( \Throwable $e ) {
			$result = false;
		}

		set_transient( $cache_key, $result ? '1' : '0', DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Verifica se GD supporta la creazione di immagini WebP.
	 *
	 * @return bool
	 */
	public function gd_webp_available(): bool {
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'gd_info' ) ) {
			return false;
		}

		$info = gd_info();
		return ! empty( $info['WebP Support'] );
	}

	/**
	 * Verifica se GD supporta AVIF (richiede PHP >= 8.1 e libavif).
	 *
	 * @return bool
	 */
	public function gd_avif_available(): bool {
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'gd_info' ) ) {
			return false;
		}

		$info = gd_info();
		return ! empty( $info['AVIF Support'] );
	}

	/**
	 * Restituisce la qualità WebP configurata dall'utente (1-100).
	 *
	 * @return int
	 */
	private function get_webp_quality(): int {
		return max( 1, min( 100, (int) get_option( 'cetus_media_webp_quality', self::WEBP_QUALITY_DEFAULT ) ) );
	}

	/**
	 * Restituisce la qualità AVIF configurata dall'utente (1-100).
	 *
	 * @return int
	 */
	private function get_avif_quality(): int {
		return max( 1, min( 100, (int) get_option( 'cetus_media_avif_quality', self::AVIF_QUALITY_DEFAULT ) ) );
	}

	/**
	 * Verifica se un allegato è stato escluso dall'utente tramite il meta box.
	 *
	 * @param int $attachment_id ID allegato.
	 * @return bool
	 */
	public function is_attachment_excluded( int $attachment_id ): bool {
		return '1' === get_post_meta( $attachment_id, 'cetus_mo_exclude', true );
	}

	/**
	 * Aggiunge una voce al log delle conversioni (max 200 voci, LIFO).
	 *
	 * @param array{attachment_id: int, filename: string, format: string, bytes_saved: int, status: string} $entry Dati della voce.
	 * @return void
	 */
	public static function add_log_entry( array $entry ): void {
		$log = get_option( 'cetus_mo_conversion_log', [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		array_unshift( $log, array_merge( $entry, [ 'time' => time() ] ) );
		update_option( 'cetus_mo_conversion_log', array_slice( $log, 0, 200 ) );
	}

	/**
	 * Conta le immagini nella libreria che non hanno ancora un file convertito
	 * nel formato di output corrente (usato per mostrare il contatore "Da convertire").
	 *
	 * @return int
	 */
	public function count_unconverted_images(): int {
		$query = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::SUPPORTED_MIME,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			]
		);

		$format = $this->resolve_format();
		$count  = 0;

		foreach ( $query->posts as $id ) {
			$file = get_attached_file( (int) $id );
			if ( ! $file ) {
				continue;
			}

			$output = $this->build_output_path( $file, $format );
			if ( ! file_exists( $output ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Elimina tutti i file WebP e AVIF generati dal plugin nella cartella uploads.
	 * Non tocca mai i file originali JPG/PNG/GIF.
	 * Azzera il log e il contatore cumulativo dei risparmi.
	 *
	 * @return array{deleted: int, errors: int}
	 */
	public function reset_converted_files(): array {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$counts     = [
			'deleted' => 0,
			'errors'  => 0,
		];

		if ( ! is_dir( $base_dir ) ) {
			return $counts;
		}

		// Recupera i percorsi assoluti di tutti i file WebP/AVIF registrati come
		// allegati originali nel database, per non eliminarli.
		$original_webp_avif = [];
		$query              = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => [ 'image/webp', 'image/avif' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		foreach ( $query->posts as $id ) {
			$file = get_attached_file( (int) $id );
			if ( $file ) {
				$original_webp_avif[ realpath( $file ) ?: $file ] = true;
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
				continue;
			}

			$ext = strtolower( $file_info->getExtension() );
			if ( ! in_array( $ext, [ 'webp', 'avif' ], true ) ) {
				continue;
			}

			$path = $file_info->getRealPath();

			// Salta i file originali registrati nel database WordPress.
			if ( isset( $original_webp_avif[ $path ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( wp_delete_file( $path ) ) {
				++$counts['deleted'];
			} else {
				++$counts['errors'];
			}
		}

		update_option( 'cetus_mo_conversion_log', [] );
		update_option( 'cetus_mo_total_bytes_saved', '0' );

		return $counts;
	}
}
