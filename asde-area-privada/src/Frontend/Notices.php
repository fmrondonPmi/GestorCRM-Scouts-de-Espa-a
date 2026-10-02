<?php
namespace AsdeBalears\AreaPrivada\Frontend;

defined( 'ABSPATH' ) || exit;

/** Avisos de un solo uso para el usuario conectado (patrón POST → redirect → GET). */
final class Notices {

	public static function add( string $type, string $message ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$notices   = get_transient( self::key() ) ?: array();
		$notices[] = array( $type, $message );
		set_transient( self::key(), $notices, 5 * MINUTE_IN_SECONDS );
	}

	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$notices = get_transient( self::key() );
		if ( ! $notices ) {
			return '';
		}
		delete_transient( self::key() );
		return TemplateLoader::render( 'comun/avisos', array( 'avisos' => $notices ) );
	}

	private static function key(): string {
		return 'asde_ap_notices_' . get_current_user_id();
	}
}
