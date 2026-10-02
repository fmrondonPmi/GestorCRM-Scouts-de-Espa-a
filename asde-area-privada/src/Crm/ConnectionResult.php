<?php
namespace AsdeBalears\AreaPrivada\Crm;

defined( 'ABSPATH' ) || exit;

final class ConnectionResult {

	/**
	 * @param string[] $details  Líneas informativas (endpoint usado, usuario...).
	 * @param string[] $warnings Avisos no bloqueantes (p. ej. permisos de escritura).
	 */
	public function __construct(
		public bool $ok,
		public string $message,
		public array $details = array(),
		public array $warnings = array()
	) {}

	public static function fail( string $message, array $details = array() ): self {
		return new self( false, $message, $details );
	}

	public function to_array(): array {
		return array(
			'ok'       => $this->ok,
			'message'  => $this->message,
			'details'  => $this->details,
			'warnings' => $this->warnings,
		);
	}
}
