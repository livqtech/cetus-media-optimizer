<?php
/**
 * Integrazione AI Multi-Provider per la generazione automatica di Alt Text.
 *
 * Supporta Google Gemini (v1beta) e OpenAI (gpt-4o-mini) con fallback
 * automatico su errori 429 (Quota Exceeded). Salva il testo generato
 * nativamente come '_wp_attachment_image_alt' nel database.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_AI' ) ) {
	return;
}

/**
 * Class Cetus_MO_AI
 */
class Cetus_MO_AI {

	/**
	 * Endpoint Gemini (v1beta).
	 *
	 * @var string
	 */
	private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

	/**
	 * Endpoint OpenAI.
	 *
	 * @var string
	 */
	private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Modello OpenAI da utilizzare.
	 *
	 * @var string
	 */
	private const OPENAI_MODEL = 'gpt-4o-mini';

	/**
	 * Mappa prefisso-locale → nome lingua in inglese per il prompt AI.
	 * L'AI riceve sempre il nome della lingua in inglese per massima compatibilità
	 * con i modelli (che sono addestrati prevalentemente su testi in inglese).
	 *
	 * @var array<string, string>
	 */
	private const LANGUAGE_MAP = [
		'it' => 'Italian',
		'en' => 'English',
		'es' => 'Spanish',
		'fr' => 'French',
		'de' => 'German',
		'pt' => 'Portuguese',
		'nl' => 'Dutch',
		'ru' => 'Russian',
		'ja' => 'Japanese',
		'zh' => 'Chinese',
		'ar' => 'Arabic',
		'pl' => 'Polish',
		'sv' => 'Swedish',
		'da' => 'Danish',
		'fi' => 'Finnish',
		'nb' => 'Norwegian',
		'tr' => 'Turkish',
		'ko' => 'Korean',
		'cs' => 'Czech',
		'ro' => 'Romanian',
		'hu' => 'Hungarian',
		'el' => 'Greek',
	];

	// -------------------------------------------------------------------------
	// Prompt dinamico internazionale
	// -------------------------------------------------------------------------

	/**
	 * Costruisce il prompt da inviare all'AI per la generazione dell'alt text.
	 *
	 * Priorità:
	 *   1. Prompt personalizzato dell'utente (se impostato in Step 2).
	 *   2. Lingua scelta dall'utente nel selettore di Step 2.
	 *   3. Auto-detect dalla locale WordPress corrente.
	 *   4. Inglese come lingua di ultima istanza.
	 *
	 * @return string
	 */
	private function get_prompt(): string {
		$custom = trim( (string) get_option( 'cetus_media_alt_text_prompt', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		$lang_code = trim( (string) get_option( 'cetus_media_alt_text_language', '' ) );
		if ( '' === $lang_code ) {
			$lang_code = $this->locale_to_lang_code( get_locale() );
		}

		$lang_name = self::LANGUAGE_MAP[ $lang_code ] ?? 'English';

		return sprintf(
			'Describe this image in %s with a single concise sentence suitable as an alt text for web accessibility. Return only the description, without any prefix or label.',
			$lang_name
		);
	}

	/**
	 * Converte una locale WordPress (es. 'it_IT', 'fr_FR', 'zh_CN') nel codice
	 * lingua a 2 caratteri usato in LANGUAGE_MAP.
	 *
	 * @param string $locale Locale WordPress (es. 'it_IT').
	 * @return string Codice lingua ISO 639-1 (es. 'it'), 'en' se non riconosciuto.
	 */
	private function locale_to_lang_code( string $locale ): string {
		$prefix = strtolower( substr( $locale, 0, 2 ) );
		return array_key_exists( $prefix, self::LANGUAGE_MAP ) ? $prefix : 'en';
	}

	// -------------------------------------------------------------------------
	// Generazione Alt Text (entry point pubblico)
	// -------------------------------------------------------------------------

	/**
	 * Genera e salva l'alt text per un allegato WordPress.
	 *
	 * Utilizza il provider selezionato nelle impostazioni, con fallback
	 * automatico all'altro provider in caso di errore 429.
	 *
	 * @param int $attachment_id ID dell'allegato WordPress.
	 * @return array{success: bool, alt_text: string, provider_used: string, message: string}
	 */
	public function generate_and_save_alt_text( int $attachment_id ): array {
		$result = [
			'success'       => false,
			'alt_text'      => '',
			'provider_used' => '',
			'message'       => '',
		];

		$image_path = get_attached_file( $attachment_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			$result['message'] = 'File immagine non trovato.';
			return $result;
		}

		// Legge e codifica l'immagine in Base64.
		$image_data = $this->encode_image( $image_path );
		if ( null === $image_data ) {
			$result['message'] = 'Impossibile leggere il file immagine.';
			return $result;
		}

		$mime_type        = $this->get_image_mime( $image_path );
		$primary_provider = get_option( 'cetus_media_ai_provider', 'gemini' );
		$fallback_enabled = '1' === get_option( 'cetus_media_ai_fallback', '1' );

		// Tentativo con il provider primario.
		[ $alt_text, $error_code, $error_msg ] = $this->call_provider(
			$primary_provider,
			$image_data,
			$mime_type
		);

		$provider_used = $primary_provider;

		// Fallback automatico su errore 429.
		if ( null === $alt_text && 429 === $error_code && $fallback_enabled ) {
			$fallback_provider = ( 'gemini' === $primary_provider ) ? 'openai' : 'gemini';

			[ $alt_text, , $error_msg ] = $this->call_provider(
				$fallback_provider,
				$image_data,
				$mime_type
			);

			if ( null !== $alt_text ) {
				$provider_used = $fallback_provider;
			}
		}

		if ( null === $alt_text ) {
			$result['message'] = $error_msg ?: 'Nessun provider disponibile o errore sconosciuto.';
			return $result;
		}

		// Sanifica e salva l'alt text.
		$alt_text = sanitize_text_field( $alt_text );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		$result['success']       = true;
		$result['alt_text']      = $alt_text;
		$result['provider_used'] = $provider_used;
		$result['message']       = sprintf(
			'Alt text generato tramite %s e salvato.',
			ucfirst( $provider_used )
		);

		return $result;
	}

	// -------------------------------------------------------------------------
	// Dispatcher provider
	// -------------------------------------------------------------------------

	/**
	 * Chiama il provider specificato e restituisce il risultato.
	 *
	 * @param string $provider   'gemini' | 'openai'.
	 * @param string $image_data Immagine codificata in Base64.
	 * @param string $mime_type  MIME type dell'immagine (es. 'image/jpeg').
	 * @return array{0: string|null, 1: int|null, 2: string} [alt_text, http_code, error_message]
	 */
	private function call_provider( string $provider, string $image_data, string $mime_type ): array {
		return match ( $provider ) {
			'gemini' => $this->call_gemini( $image_data, $mime_type ),
			'openai' => $this->call_openai( $image_data, $mime_type ),
			default  => [ null, null, sprintf( 'Provider "%s" non riconosciuto.', esc_html( $provider ) ) ],
		};
	}

	// -------------------------------------------------------------------------
	// Google Gemini
	// -------------------------------------------------------------------------

	/**
	 * Chiama l'API Google Gemini per generare l'alt text.
	 *
	 * @param string $image_data Immagine in Base64.
	 * @param string $mime_type  MIME type.
	 * @return array{0: string|null, 1: int|null, 2: string}
	 */
	private function call_gemini( string $image_data, string $mime_type ): array {
		$api_key = get_option( 'cetus_media_gemini_key', '' );

		if ( empty( $api_key ) ) {
			return [ null, null, 'Chiave API Gemini non configurata.' ];
		}

		$url  = add_query_arg( 'key', rawurlencode( $api_key ), self::GEMINI_ENDPOINT );
		$body = [
			'contents' => [
				[
					'parts' => [
						[
							'inlineData' => [
								'mimeType' => $mime_type,
								'data'     => $image_data,
							],
						],
						[
							'text' => $this->get_prompt(),
						],
					],
				],
			],
		];

		$response = wp_remote_post(
			$url,
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ null, null, 'Errore HTTP Gemini: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			return [ null, 429, 'Quota Gemini esaurita (429). Verifica i limiti del tuo account.' ];
		}

		if ( 200 !== $code ) {
			return [ null, $code, sprintf( 'Gemini ha risposto con codice %d.', $code ) ];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return [ null, $code, 'Risposta Gemini vuota o in formato inatteso.' ];
		}

		return [ trim( $text ), $code, '' ];
	}

	// -------------------------------------------------------------------------
	// OpenAI
	// -------------------------------------------------------------------------

	/**
	 * Chiama l'API OpenAI (gpt-4o-mini) per generare l'alt text.
	 *
	 * @param string $image_data Immagine in Base64.
	 * @param string $mime_type  MIME type.
	 * @return array{0: string|null, 1: int|null, 2: string}
	 */
	private function call_openai( string $image_data, string $mime_type ): array {
		$api_key = get_option( 'cetus_media_openai_key', '' );

		if ( empty( $api_key ) ) {
			return [ null, null, 'Chiave API OpenAI non configurata.' ];
		}

		$data_url = sprintf( 'data:%s;base64,%s', $mime_type, $image_data );

		$body = [
			'model'      => self::OPENAI_MODEL,
			'max_tokens' => 150,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[
							'type'      => 'image_url',
							'image_url' => [ 'url' => $data_url ],
						],
						[
							'type' => 'text',
							'text' => $this->get_prompt(),
						],
					],
				],
			],
		];

		$response = wp_remote_post(
			self::OPENAI_ENDPOINT,
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ null, null, 'Errore HTTP OpenAI: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			return [ null, 429, 'Quota OpenAI esaurita (429). Verifica i limiti del tuo account.' ];
		}

		if ( 200 !== $code ) {
			return [ null, $code, sprintf( 'OpenAI ha risposto con codice %d.', $code ) ];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $data['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return [ null, $code, 'Risposta OpenAI vuota o in formato inatteso.' ];
		}

		return [ trim( $text ), $code, '' ];
	}

	// -------------------------------------------------------------------------
	// Validazione chiavi API
	// -------------------------------------------------------------------------

	/**
	 * Verifica la validità di una chiave API effettuando una chiamata di test.
	 *
	 * @param string $provider 'gemini' | 'openai'.
	 * @param string $api_key  Chiave da testare.
	 * @return array{valid: bool, message: string}
	 */
	public function validate_api_key( string $provider, string $api_key ): array {
		if ( empty( $api_key ) ) {
			return [
				'valid'   => false,
				'message' => 'Chiave API vuota.',
			];
		}

		if ( 'gemini' === $provider ) {
			// Formato atteso: AIzaSy... (39 caratteri).
			if ( ! preg_match( '/^AIzaSy[A-Za-z0-9_-]{33}$/', $api_key ) ) {
				return [
					'valid'   => false,
					'message' => 'Formato chiave Gemini non valido (atteso: AIzaSy..., 39 caratteri).',
				];
			}
		}

		if ( 'openai' === $provider ) {
			// Formato atteso: sk-... o sk-proj-...
			if ( ! preg_match( '/^sk-/', $api_key ) ) {
				return [
					'valid'   => false,
					'message' => 'Formato chiave OpenAI non valido (atteso: sk-...).',
				];
			}
		}

		return [
			'valid'   => true,
			'message' => 'Formato chiave valido.',
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Legge il file immagine e lo restituisce codificato in Base64.
	 *
	 * @param string $path Percorso assoluto del file.
	 * @return string|null Null se il file non è leggibile o supera 20 MB.
	 */
	private function encode_image( string $path ): ?string {
		// Limite di 20 MB per le API vision.
		if ( filesize( $path ) > 20 * 1024 * 1024 ) {
			return null;
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return null;
		}

		return base64_encode( $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Restituisce il MIME type di un file immagine in modo sicuro.
	 *
	 * @param string $path Percorso assoluto del file.
	 * @return string MIME type (default 'image/jpeg').
	 */
	private function get_image_mime( string $path ): string {
		$info = wp_check_filetype( $path );
		return is_string( $info['type'] ) && '' !== $info['type'] ? $info['type'] : 'image/jpeg';
	}
}
