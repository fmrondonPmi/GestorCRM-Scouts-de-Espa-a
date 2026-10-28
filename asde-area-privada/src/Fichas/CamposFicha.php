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

	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function grupos(): array {
		return array(
		'identificacion' => __( 'Personal details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'membresia'      => __( 'Membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'contacto'       => __( 'Contact', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'tutores'        => __( 'Family / guardians', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'autorizaciones' => __( 'Authorisations and training', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'salud'          => __( 'Health', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'alimentacion'   => __( 'Food', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	);
	}

	/** Siempre de solo lectura en la ficha. */
	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function solo_lectura(): array {
		return array(
		'numerosocio_c'             => __( 'It is assigned by Scouts de España.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'numsocioantiguo_enficha_c' => __( 'It is assigned and corrected from People (numbering).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'stic_relationship_type_c'  => __( 'It leaves the CRM memberships.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	);
	}

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
	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function opciones(): array {
		return array(
		'alimentacion_regimen_tipo_y__c' => array(
			'Dieta omnívora'      => __( 'It includes foods of animal and plant origin without major restrictions.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Dieta vegetariana'   => __( 'It excludes meat and fish, but may allow derivatives such as eggs and dairy (ovo-lacto-vegetarian).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Dieta vegana'        => __( 'It completely excludes any animal-origin product, including honey, dairy and eggs.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Dieta flexitariana'  => __( 'It is based mainly on plants, allowing the occasional consumption of meat or fish.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		),
	);
	}

	/** @return array<string, array{0:string, 1:string, 2:string}> campo CRM => [etiqueta, grupo, nivel] */
	public static function todos(): array {
		static $por_idioma = array(); // Las etiquetas salen traducidas: una lista por idioma.
		$idioma = determine_locale();
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
				$campos[ $crm[0] ] = array( sprintf( /* translators: %s: text */ __( '%s · first name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $etiqueta ), $grupo, $nivel );
				$campos[ $crm[1] ] = array( sprintf( /* translators: %s: text */ __( '%s · surnames', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $etiqueta ), $grupo, $nivel );
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
