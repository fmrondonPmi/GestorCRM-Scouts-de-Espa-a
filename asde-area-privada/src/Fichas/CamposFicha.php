<?php
namespace AsdeBalears\AreaPrivada\Fichas;

use AsdeBalears\AreaPrivada\Informes\Catalogo;

defined( 'ABSPATH' ) || exit;

/**
 * Campos de la ficha de una persona: los mismos que ya se pueden ver en el área
 * privada (catálogo de informes), uno por campo del CRM.
 *
 * Cada campo: [etiqueta, grupo, nivel]
 *   nivel: todos | salud (requiere ver salud) | global (solo visión global)
 * El tipo de control (texto, fecha, sí/no, desplegable…) sale de los metadatos
 * del propio CRM (Crm\Metadatos).
 */
final class CamposFicha {

	public const GRUPOS = array(
		'identificacion' => 'Datos personales',
		'membresia'      => 'Membresía',
		'contacto'       => 'Contacto',
		'tutores'        => 'Familia / tutores',
		'autorizaciones' => 'Autorizaciones y formación',
		'salud'          => 'Salud',
		'alimentacion'   => 'Alimentación',
	);

	/** Siempre de solo lectura en la ficha. */
	public const SOLO_LECTURA = array(
		'numerosocio_c'             => 'Lo asigna Scouts de España.',
		'numsocioantiguo_enficha_c' => 'Se asigna y corrige desde Personas (numeración).',
		'stic_relationship_type_c'  => 'Sale de las membresías del CRM.',
	);

	/** Campos de contacto que cada persona puede cambiar de su propia ficha. */
	public const CONTACTO_PROPIO = array( 'email1', 'phone_mobile', 'phone_home' );

	/** Solo tienen sentido para adultos: no se muestran en la ficha de un menor. */
	public const SOLO_ADULTOS = array( 'formaciontiempolibre_c', 'certificadods_c', 'certificadods_fecha_c', 'formacionscout_c' );

	/** Solo se muestran con membresía de adulto voluntario: Scouter o Voluntariado (Secciones::ADULTAS). */
	public const SOLO_VOLUNTARIOS = array( 'cargoeducativo_c' );

	/**
	 * Campos que en el CRM son texto libre pero en la ficha se eligen de una
	 * lista (se guarda el texto de la opción). Cada opción lleva su explicación,
	 * que se ve al pasar el ratón por encima.
	 *
	 * @var array<string, array<string, string>> campo => [opción => explicación]
	 */
	public const OPCIONES = array(
		'alimentacion_regimen_tipo_y__c' => array(
			'Dieta omnívora'      => 'Incluye alimentos de origen animal y vegetal sin mayores restricciones.',
			'Dieta vegetariana'   => 'Excluye la carne y el pescado, pero puede permitir derivados como huevos y lácteos (ovolactovegetariana).',
			'Dieta vegana'        => 'Elimina por completo cualquier producto de origen animal, incluyendo la miel, los lácteos y los huevos.',
			'Dieta flexitariana'  => 'Se basa principalmente en vegetales, permitiendo el consumo ocasional de carne o pescado.',
		),
	);

	/** @return array<string, array{0:string, 1:string, 2:string}> campo CRM => [etiqueta, grupo, nivel] */
	public static function todos(): array {
		static $por_idioma = array(); // Las etiquetas salen traducidas: una lista por idioma.
		$idioma = \AsdeBalears\AreaPrivada\Idiomas\Idioma::actual();
		if ( isset( $por_idioma[ $idioma ] ) ) {
			return $por_idioma[ $idioma ];
		}
		$campos = array();
		foreach ( Catalogo::columnas() as $col ) {
			list( $etiqueta, $grupo, $crm, $tipo, $nivel ) = $col;
			if ( 'edad' === $tipo ) {
				continue; // Se calcula de la fecha de nacimiento.
			}
			if ( 'nombre_tutor' === $tipo ) {
				$campos[ $crm[0] ] = array( sprintf( asde_t( '%s · nombre' ), $etiqueta ), $grupo, $nivel );
				$campos[ $crm[1] ] = array( sprintf( asde_t( '%s · apellidos' ), $etiqueta ), $grupo, $nivel );
				continue;
			}
			// En la ficha, la alimentación va en su propio apartado.
			if ( 'salud' === $grupo && str_starts_with( $crm[0], 'alimentacion_' ) ) {
				$grupo = 'alimentacion';
			}
			$campos[ $crm[0] ] = array( $etiqueta, $grupo, $nivel );
		}
		return $por_idioma[ $idioma ] = $campos;
	}
}
