<?php
namespace AsdeBalears\AreaPrivada\Informes;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Install\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Columnas disponibles en el generador de informes (campos del CRM de ASDE,
 * ver docs/04 §7.3).
 *
 * Cada columna: [etiqueta, grupo, campos CRM, tipo, nivel]
 *   tipo:  texto | fecha | bool | sino | enum | edad | seccion | num_local | nombre_tutor | pago
 *   nivel: todos  → cualquiera con acceso a informes (dentro de su ámbito)
 *          salud  → requiere asde_view_health (scouters: solo su sección, solo lectura)
 *          global → visión global (Secretaría, Coordinación, administradores) y, desde la v0.58, también
 *                   los scouters (de los socios de sus secciones, que es todo lo que ven): «un scouter de
 *                   sección ve todos los datos de los asociados de su sección». Las familias, no.
 *          pagos  → requiere acceso a Tesorería y el módulo activo (v0.25): la cuota
 *                   de la familia de cada socio, de Tesorería (no del CRM)
 * Los IBAN y datos bancarios NO están en el catálogo.
 */
final class Catalogo {

	public const GRUPOS = array(
		'identificacion' => 'Identificación',
		'membresia'      => 'Membresía',
		'contacto'       => 'Contacto',
		'tutores'        => 'Familia / tutores',
		'autorizaciones' => 'Autorizaciones y formación',
		'pagos'          => 'Cuota y pagos',
		'salud'          => 'Salud y alimentación',
	);

	/** Filtro por lo pagado (v0.25): quién ha pagado la cuota y quién no. */
	public const FILTROS_PAGO = array(
		''            => 'Todos',
		'pagado'      => 'Han pagado toda la cuota',
		'debe'        => 'Les falta algo por pagar',
		'vencido'     => 'Con plazos vencidos sin pagar',
		'sin_familia' => 'Sin familia en Tesorería',
	);

	/** @return array<string, array{0:string, 1:string, 2:string[], 3:string, 4:string}> */
	public static function columnas(): array {
		return array(
			// Identificación.
			'num_local'        => array( __( 'Member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'numsocioantiguo_enficha_c' ), 'num_local', 'todos' ),
			'num_asde'         => array( __( 'Scouts de España no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'numerosocio_c' ), 'texto', 'todos' ),
			'nombre'           => array( __( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'first_name' ), 'texto', 'todos' ),
			'apellidos'        => array( __( 'Surnames', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'last_name' ), 'texto', 'todos' ),
			'apodo'            => array( __( 'Nickname', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'apodo_c' ), 'texto', 'todos' ),
			'nacimiento'       => array( __( 'Date of birth', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'birthdate' ), 'fecha', 'todos' ),
			'edad'             => array( __( 'Age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'birthdate' ), 'edad', 'todos' ),
			'genero'           => array( __( 'Gender', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'stic_gender_c' ), 'enum', 'todos' ),
			'dni'              => array( __( 'ID / foreigner ID', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'identificacion', array( 'stic_identification_number_c' ), 'texto', 'global' ),
			// Membresía.
			'seccion'          => array( __( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'membresia', array( 'stic_relationship_type_c' ), 'seccion', 'todos' ),
			'cargo'            => array( __( 'Position', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'membresia', array( 'cargoeducativo_c' ), 'enum', 'todos' ),
			// Contacto.
			'email'            => array( __( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'contacto', array( 'email1' ), 'texto', 'todos' ),
			'movil'            => array( __( 'Mobile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'contacto', array( 'phone_mobile' ), 'texto', 'todos' ),
			'telefono'         => array( __( 'Home phone', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'contacto', array( 'phone_home' ), 'texto', 'todos' ),
			'direccion'        => array( __( 'Address', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'contacto', array( 'primary_address_street' ), 'texto', 'todos' ),
			'poblacion'        => array( __( 'Town', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'contacto', array( 'primary_address_city' ), 'texto', 'todos' ),
			'cp'               => array( __( 'Postcode', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'contacto', array( 'primary_address_postalcode' ), 'texto', 'todos' ),
			// Familia / tutores.
			'tutor1'           => array( __( 'Guardian 1', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'tutores', array( 'tutor1_nombre_c', 'tutor1_apellidos_c' ), 'nombre_tutor', 'todos' ),
			'tutor1_movil'     => array( __( 'Guardian 1 · mobile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'tutores', array( 'tutor1_movil_c' ), 'texto', 'todos' ),
			'tutor1_email'     => array( __( 'Guardian 1 · email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'tutores', array( 'tutor1_email_c' ), 'texto', 'todos' ),
			'tutor2'           => array( __( 'Guardian 2', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'tutores', array( 'tutor2_nombre_c', 'tutor2_apellidos_c' ), 'nombre_tutor', 'todos' ),
			'tutor2_movil'     => array( __( 'Guardian 2 · mobile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'tutores', array( 'tutor2_movil_c' ), 'texto', 'todos' ),
			'tutor2_email'     => array( __( 'Guardian 2 · email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'tutores', array( 'tutor2_email_c' ), 'texto', 'todos' ),
			// Autorizaciones y formación.
			'cesion_imagen'    => array( __( 'Image release', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'autorizaciones', array( 'cesionimagen_c' ), 'bool', 'todos' ),
			'consent_medic'    => array( __( 'Consent to administer medication', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'autorizaciones', array( 'datosmedicos_consmedica_c' ), 'bool', 'salud' ),
			'certificado_ds'   => array( __( 'Sexual offences certificate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'autorizaciones', array( 'certificadods_c' ), 'bool', 'todos' ),
			'certificado_ds_f' => array( __( 'Offences certificate date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'autorizaciones', array( 'certificadods_fecha_c' ), 'fecha', 'todos' ),
			'formacion_scout'  => array( __( 'Scout training', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'autorizaciones', array( 'formacionscout_c' ), 'enum', 'todos' ),
			'formacion_tl'     => array( __( 'Leisure-time training', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'autorizaciones', array( 'formaciontiempolibre_c' ), 'enum', 'todos' ),
			// Cuota y pagos (v0.25): de Tesorería, por la familia del socio (con sus hermanos).
			'pago_familia'     => array( __( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_estado'      => array( __( 'Fee status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_a_pagar'     => array( __( 'Fee to pay', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_pagado'      => array( __( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_pendiente'   => array( __( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_vencido'     => array( __( 'Overdue and unpaid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_hechos'      => array( __( 'Payments made', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			// Salud y alimentación (categoría especial RGPD).
			'alergias'         => array( __( 'Allergies', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alergias_c' ), 'texto', 'salud' ),
			'alergias_obs'     => array( __( 'Other allergy details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alergias_obs_c' ), 'texto', 'salud' ),
			'alerg_medic'      => array( __( 'Medication allergy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alergiamedicamentos_c' ), 'bool', 'salud' ),
			'alerg_medic_cual' => array( __( 'Which medicines?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alergiamedicamentos_cuales_c' ), 'texto', 'salud' ),
			'medic_reaccion'   => array( __( 'Has medication for allergic reactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alergias_medicamentossn_c' ), 'bool', 'salud' ),
			'alim_alergia'     => array( __( 'Food allergy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_alergia_c' ), 'sino', 'salud' ),
			'alim_alergia_que' => array( __( 'Foods that cause allergy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_alerg_alimentos_c' ), 'texto', 'salud' ),
			'alim_intol'       => array( __( 'Food intolerance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_intolerancia_c' ), 'sino', 'salud' ),
			'alim_intol_que'   => array( __( 'Foods with intolerance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_intol_alimentos_c' ), 'texto', 'salud' ),
			'alim_regimen'     => array( __( 'Special diet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_regimen_c' ), 'sino', 'salud' ),
			'alim_regimen_que' => array( __( 'Type of diet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_regimen_tipo_y__c' ), 'texto', 'salud' ),
			'alim_regimen_por' => array( __( 'Reason for the diet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_regimen_razon_c' ), 'enum', 'salud' ),
			'alim_trastorno'   => array( __( 'Eating disorder', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_transtornos_c' ), 'sino', 'salud' ),
			'alim_trast_desc'  => array( __( 'Description of the disorder', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'alimentacion_transtorno_desc_c' ), 'texto', 'salud' ),
			'tratamiento'      => array( __( 'Medical treatment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'medicos_tratamiento_c' ), 'enum', 'salud' ),
			'tratamiento_desc' => array( __( 'Description of the treatment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'medicos_tratamiento_desc_c' ), 'texto', 'salud' ),
			'medicacion'       => array( __( 'Takes medication', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'datosmedicos_tomamedicacion_c' ), 'bool', 'salud' ),
			'medicacion_cual'  => array( __( 'Which medication?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'datosmedicos_medicacion_c' ), 'texto', 'salud' ),
			'enfermedades'     => array( __( 'Illnesses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'datosmedicos_enfermedades_c' ), 'texto', 'salud' ),
			'discapacidad'     => array( __( 'Disability / impairments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'datosmedicos_minusvalias_c' ), 'texto', 'salud' ),
			'vacunas'          => array( __( 'Vaccinations up to date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'medicos_tienevacunas_c' ), 'bool', 'salud' ),
			'tarjeta_sanit'    => array( __( 'Health card no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'medicosnumtarjetasanitaria_c' ), 'texto', 'salud' ),
			'seguro'           => array( __( 'Private health insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'medicos_tieneseguroprivado_c' ), 'bool', 'salud' ),
			'seguro_cia'       => array( __( 'Insurance company', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'medicos_cia_seguro_c' ), 'texto', 'salud' ),
			'seguro_poliza'    => array( __( 'Policy no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'salud', array( 'medicos_seguro_numpoliza_c' ), 'texto', 'salud' ),
		);
	}

	/** Campos cuyo contenido cuenta para el filtro "con datos de salud relevantes". */
	public const CAMPOS_SALUD_RELEVANTE = array(
		'alergias_c'                    => 'texto',
		'alergiamedicamentos_c'         => 'bool',
		'alimentacion_alergia_c'        => 'sino',
		'alimentacion_intolerancia_c'   => 'sino',
		'alimentacion_regimen_c'        => 'sino',
		'alimentacion_transtornos_c'    => 'sino',
		'medicos_tratamiento_c'         => 'si',
		'datosmedicos_tomamedicacion_c' => 'bool',
		'datosmedicos_enfermedades_c'   => 'texto',
	);

	/** Etiquetas de valores de desplegables. */
	public const ENUMS = array(
		'stic_gender_c'                => array(
			'masculino' => 'Masculino',
			'femenino'  => 'Femenino',
			'nobinario' => 'No binario',
		),
		'medicos_tratamiento_c'        => array(
			'No'            => 'No',
			'SinRelevancia' => 'Sin relevancia o autocontrolado',
			'Si'            => 'Sí',
		),
		'alimentacion_regimen_razon_c' => array(
			'voluntariamente' => 'Voluntariamente',
			'prescripcion'    => 'Por prescripción médica',
			'otros'           => 'Otros',
		),
		'cargoeducativo_c'             => array(
			'coordinadorgrupoGS'          => 'Coordinador/a de Grupo',
			'coordinadorseccionGS'        => 'Coordinador/a de sección',
			'secretariogrupoGS'           => 'Secretario/a de Grupo',
			'tesorerogrupoGS'             => 'Tesorero/a de Grupo',
			'scouterseccionGS'            => 'Scouter de sección',
			'responsablematerialGS'       => 'Responsable de Material',
			'responsableformacionGS'      => 'Responsable de Formación',
			'responsablecomunicacionGS'   => 'Responsable de Comunicación / RRSS',
			'responsableeducacionscoutGS' => 'Responsable de Educación Scout',
		),
		'formacionscout_c'             => array(
			'ninguno'            => 'Sin formación Scout',
			'acogida'            => 'Etapa Acogida al Scouter (EAS)',
			'educador'           => 'Etapa Educador/a Scout (EES)',
			'coordinador'        => 'Etapa Coordinador/a Scout (ECS)',
			'acogidaformador'    => 'Etapa Acogida al Formador/a (EAF)',
			'formadorscouters'   => 'Etapa Formador/a de Scouters (EFS)',
			'formadorformadores' => 'Etapa Formador/a de Formadores (EFF)',
		),
		'formaciontiempolibre_c'       => array(
			'ninguno'         => 'Sin titulación',
			'monitor'         => 'Monitor/a de Tiempo Libre',
			'director'        => 'Director/a de Tiempo Libre',
			'animador'        => 'Animador/a Juvenil',
			'practicas'       => 'Prácticas de Monitor/a',
			'monitoranimador' => 'Monitor/a y Animador/a Juvenil',
			'otro'            => 'Otro',
		),
	);

	/** Lo que ve en un informe quien solo es de Tesorería (v0.37.1, P-167): quién es y sus pagos, de todo el grupo. */
	private const IDENTIDAD_TESORERIA = array( 'num_local', 'nombre', 'apellidos', 'seccion' );

	/** ¿Es Tesorería sin visión global? Ve las familias de todo el grupo, pero solo identidad y pagos. */
	public static function solo_tesoreria( Ambito $ambito ): bool {
		return ! $ambito->vision_global() && in_array( Roles::TESORERIA, $ambito->roles_vigentes(), true );
	}

	/** Columnas que puede usar el ámbito dado. @return array<string, array> */
	public static function permitidas( Ambito $ambito ): array {
		if ( self::solo_tesoreria( $ambito ) ) {
			return array_filter(
				self::columnas(),
				static fn( $c, $k ) => ( 'pagos' === $c[4] && self::ve_pagos( $ambito ) ) || in_array( $k, self::IDENTIDAD_TESORERIA, true ),
				ARRAY_FILTER_USE_BOTH
			);
		}
		$puede_salud = $ambito->user->has_cap( 'asde_view_health' );
		// v0.58: el nivel «global» (el DNI) también para el scouter: sus informes ya se limitan a sus secciones.
		$global      = $ambito->vision_global() || (bool) $ambito->secciones_scouter();
		$pagos       = self::ve_pagos( $ambito );
		return array_filter(
			self::columnas(),
			static fn( $c ) => 'todos' === $c[4] || ( 'salud' === $c[4] && $puede_salud ) || ( 'global' === $c[4] && $global ) || ( 'pagos' === $c[4] && $pagos )
		);
	}

	/** ¿Puede ver la cuota y los pagos? Quien entra en Tesorería, si el grupo la usa (v0.25). */
	public static function ve_pagos( Ambito $ambito ): bool {
		return $ambito->user->has_cap( 'asde_tesoreria_acceso' ) && Modulos::activo( Modulos::TESORERIA );
	}

	/** ¿Alguna de estas columnas es de la cuota y los pagos? */
	public static function incluye_pagos( array $claves ): bool {
		$cols = self::columnas();
		foreach ( $claves as $k ) {
			if ( isset( $cols[ $k ] ) && 'pagos' === $cols[ $k ][4] ) {
				return true;
			}
		}
		return false;
	}

	/** ¿Alguna de estas columnas es de salud? */
	public static function incluye_salud( array $claves ): bool {
		$cols = self::columnas();
		foreach ( $claves as $k ) {
			if ( isset( $cols[ $k ] ) && 'salud' === $cols[ $k ][4] ) {
				return true;
			}
		}
		return false;
	}
}
