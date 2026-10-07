<?php
/**
 * Lo mínimo de WordPress y del plugin que usa el CRM simulado (tests/lib/CrmSimulado.php)
 * para poder servirlo sin WordPress (router.php). Solo se declara lo que no exista.
 */

namespace AsdeBalears\AreaPrivada\Crm {

	if ( ! interface_exists( EscritorCrm::class ) ) {
		/** La misma firma que src/Crm/EscritorCrm.php. */
		interface EscritorCrm {
			public function set_entry( string $module, string $id, array $values ): string;

			public function get_entry( string $module, string $id, array $fields, array $links = array() ): ?array;
		}
	}

	if ( ! class_exists( Settings::class ) ) {
		/** En el servidor no hay URL del CRM que interceptar: se contesta directamente. */
		final class Settings {
			public static function url(): string {
				return '';
			}
		}
	}

	if ( ! class_exists( ClientFactory::class ) ) {
		final class ClientFactory {
			public static function olvidar(): void {}
		}
	}
}

namespace {

	if ( ! function_exists( 'remove_accents' ) ) {
		/** Lo justo para comparar sin acentos (castellano, catalán, euskera y gallego). */
		function remove_accents( string $texto ): string {
			return strtr(
				$texto,
				array(
					'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
					'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
					'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'l·l' => 'll',
					'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
					'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O',
					'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U', 'Ñ' => 'N', 'Ç' => 'C', 'L·L' => 'LL',
				)
			);
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $datos ) {
			return json_encode( $datos, JSON_UNESCAPED_UNICODE );
		}
	}
}
