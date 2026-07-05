<?php
/**
 * Aggiunge link rapidi nella riga del plugin nella pagina plugins.php.
 *
 * @package CetusMediaOptimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_Plugin_Links' ) ) {
	return;
}

/**
 * Class Cetus_MO_Plugin_Links
 */
class Cetus_MO_Plugin_Links {

	/**
	 * Registra il filtro sui link di azione del plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter(
			'plugin_action_links_' . plugin_basename( CETUS_MO_FILE ),
			[ $this, 'add_action_links' ]
		);
	}

	/**
	 * Aggiunge il link "Impostazioni" prima degli altri link di azione.
	 *
	 * @param array<string, string> $links Link esistenti.
	 * @return array<string, string>
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=cetus-media-optimizer' ) ),
			esc_html__( 'Settings', 'cetus-media-optimizer' )
		);

		return array_merge( [ 'settings' => $settings_link ], $links );
	}
}
