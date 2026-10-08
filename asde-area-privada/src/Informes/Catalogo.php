<?php
namespace AsdeBalears\AreaPrivada\Informes;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Modulos;

defined( 'ABSPATH' ) || exit;

/**
 * Columnas disponibles en el generador de informes (campos del CRM de ASDE,
 * ver docs/04 §7.3).
 *
 * Cada columna: [etiqueta, grupo, campos CRM, tipo, nivel]
 *   tipo:  texto | fecha | bool | sino | enum | edad | seccion | num_local | nombre_tutor | pago
 *   nivel: todos  → cualquiera con acceso a informes (dentro de su ámbito)
 *          salud  → requiere asde_view_health (scouters: solo su sección, solo lectura)
 *          global → solo visión global (Secretaría, Coordinación, administradores)
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
			'num_local'        => array( asde_t( 'Nº socio/a' ), 'identificacion', array( 'numsocioantiguo_enficha_c' ), 'num_local', 'todos' ),
			'num_asde'         => array( asde_t( 'Nº Scouts de España' ), 'identificacion', array( 'numerosocio_c' ), 'texto', 'todos' ),
			'nombre'           => array( asde_t( 'Nombre' ), 'identificacion', array( 'first_name' ), 'texto', 'todos' ),
			'apellidos'        => array( asde_t( 'Apellidos' ), 'identificacion', array( 'last_name' ), 'texto', 'todos' ),
			'apodo'            => array( asde_t( 'Apodo' ), 'identificacion', array( 'apodo_c' ), 'texto', 'todos' ),
			'nacimiento'       => array( asde_t( 'Fecha de nacimiento' ), 'identificacion', array( 'birthdate' ), 'fecha', 'todos' ),
			'edad'             => array( asde_t( 'Edad' ), 'identificacion', array( 'birthdate' ), 'edad', 'todos' ),
			'genero'           => array( asde_t( 'Género' ), 'identificacion', array( 'stic_gender_c' ), 'enum', 'todos' ),
			'dni'              => array( asde_t( 'DNI / NIE' ), 'identificacion', array( 'stic_identification_number_c' ), 'texto', 'global' ),
			// Membresía.
			'seccion'          => array( asde_t( 'Sección' ), 'membresia', array( 'stic_relationship_type_c' ), 'seccion', 'todos' ),
			'cargo'            => array( asde_t( 'Cargo' ), 'membresia', array( 'cargoeducativo_c' ), 'enum', 'todos' ),
			// Contacto.
			'email'            => array( asde_t( 'Email' ), 'contacto', array( 'email1' ), 'texto', 'todos' ),
			'movil'            => array( asde_t( 'Móvil' ), 'contacto', array( 'phone_mobile' ), 'texto', 'todos' ),
			'telefono'         => array( asde_t( 'Teléfono casa' ), 'contacto', array( 'phone_home' ), 'texto', 'todos' ),
			'direccion'        => array( asde_t( 'Dirección' ), 'contacto', array( 'primary_address_street' ), 'texto', 'todos' ),
			'poblacion'        => array( asde_t( 'Población' ), 'contacto', array( 'primary_address_city' ), 'texto', 'todos' ),
			'cp'               => array( asde_t( 'Código postal' ), 'contacto', array( 'primary_address_postalcode' ), 'texto', 'todos' ),
			// Familia / tutores.
			'tutor1'           => array( asde_t( 'Tutor/a 1' ), 'tutores', array( 'tutor1_nombre_c', 'tutor1_apellidos_c' ), 'nombre_tutor', 'todos' ),
			'tutor1_movil'     => array( asde_t( 'Tutor/a 1 · móvil' ), 'tutores', array( 'tutor1_movil_c' ), 'texto', 'todos' ),
			'tutor1_email'     => array( asde_t( 'Tutor/a 1 · email' ), 'tutores', array( 'tutor1_email_c' ), 'texto', 'todos' ),
			'tutor2'           => array( asde_t( 'Tutor/a 2' ), 'tutores', array( 'tutor2_nombre_c', 'tutor2_apellidos_c' ), 'nombre_tutor', 'todos' ),
			'tutor2_movil'     => array( asde_t( 'Tutor/a 2 · móvil' ), 'tutores', array( 'tutor2_movil_c' ), 'texto', 'todos' ),
			'tutor2_email'     => array( asde_t( 'Tutor/a 2 · email' ), 'tutores', array( 'tutor2_email_c' ), 'texto', 'todos' ),
			// Autorizaciones y formación.
			'cesion_imagen'    => array( asde_t( 'Cesión de imagen' ), 'autorizaciones', array( 'cesionimagen_c' ), 'bool', 'todos' ),
			'consent_medic'    => array( asde_t( 'Consentimiento para administrar medicación' ), 'autorizaciones', array( 'datosmedicos_consmedica_c' ), 'bool', 'salud' ),
			'certificado_ds'   => array( asde_t( 'Certificado delitos sexuales' ), 'autorizaciones', array( 'certificadods_c' ), 'bool', 'todos' ),
			'certificado_ds_f' => array( asde_t( 'Fecha certificado DS' ), 'autorizaciones', array( 'certificadods_fecha_c' ), 'fecha', 'todos' ),
			'formacion_scout'  => array( asde_t( 'Formación Scout' ), 'autorizaciones', array( 'formacionscout_c' ), 'enum', 'todos' ),
			'formacion_tl'     => array( asde_t( 'Formación tiempo libre' ), 'autorizaciones', array( 'formaciontiempolibre_c' ), 'enum', 'todos' ),
			// Cuota y pagos (v0.25): de Tesorería, por la familia del socio (con sus hermanos).
			'pago_familia'     => array( asde_t( 'Familia' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_estado'      => array( asde_t( 'Estado de la cuota' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_a_pagar'     => array( asde_t( 'Cuota a pagar' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_pagado'      => array( asde_t( 'Pagado' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_pendiente'   => array( asde_t( 'Pendiente' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_vencido'     => array( asde_t( 'Vencido sin pagar' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			'pago_hechos'      => array( asde_t( 'Pagos hechos' ), 'pagos', array( 'numerosocio_c' ), 'pago', 'pagos' ),
			// Salud y alimentación (categoría especial RGPD).
			'alergias'         => array( asde_t( 'Alergias' ), 'salud', array( 'alergias_c' ), 'texto', 'salud' ),
			'alergias_obs'     => array( asde_t( 'Otros datos de alergias' ), 'salud', array( 'alergias_obs_c' ), 'texto', 'salud' ),
			'alerg_medic'      => array( asde_t( 'Alergia a medicamentos' ), 'salud', array( 'alergiamedicamentos_c' ), 'bool', 'salud' ),
			'alerg_medic_cual' => array( asde_t( '¿A qué medicamentos?' ), 'salud', array( 'alergiamedicamentos_cuales_c' ), 'texto', 'salud' ),
			'medic_reaccion'   => array( asde_t( 'Tiene medicación para reacciones alérgicas' ), 'salud', array( 'alergias_medicamentossn_c' ), 'bool', 'salud' ),
			'alim_alergia'     => array( asde_t( 'Alergia alimentaria' ), 'salud', array( 'alimentacion_alergia_c' ), 'sino', 'salud' ),
			'alim_alergia_que' => array( asde_t( 'Alimentos que producen alergia' ), 'salud', array( 'alimentacion_alerg_alimentos_c' ), 'texto', 'salud' ),
			'alim_intol'       => array( asde_t( 'Intolerancia alimentaria' ), 'salud', array( 'alimentacion_intolerancia_c' ), 'sino', 'salud' ),
			'alim_intol_que'   => array( asde_t( 'Alimentos con intolerancia' ), 'salud', array( 'alimentacion_intol_alimentos_c' ), 'texto', 'salud' ),
			'alim_regimen'     => array( asde_t( 'Régimen especial de comidas' ), 'salud', array( 'alimentacion_regimen_c' ), 'sino', 'salud' ),
			'alim_regimen_que' => array( asde_t( 'Tipo de régimen' ), 'salud', array( 'alimentacion_regimen_tipo_y__c' ), 'texto', 'salud' ),
			'alim_regimen_por' => array( asde_t( 'Motivo del régimen' ), 'salud', array( 'alimentacion_regimen_razon_c' ), 'enum', 'salud' ),
			'alim_trastorno'   => array( asde_t( 'Trastorno alimentario' ), 'salud', array( 'alimentacion_transtornos_c' ), 'sino', 'salud' ),
			'alim_trast_desc'  => array( asde_t( 'Descripción del trastorno' ), 'salud', array( 'alimentacion_transtorno_desc_c' ), 'texto', 'salud' ),
			'tratamiento'      => array( asde_t( 'Tratamiento médico' ), 'salud', array( 'medicos_tratamiento_c' ), 'enum', 'salud' ),
			'tratamiento_desc' => array( asde_t( 'Descripción del tratamiento' ), 'salud', array( 'medicos_tratamiento_desc_c' ), 'texto', 'salud' ),
			'medicacion'       => array( asde_t( 'Toma medicación' ), 'salud', array( 'datosmedicos_tomamedicacion_c' ), 'bool', 'salud' ),
			'medicacion_cual'  => array( asde_t( '¿Qué medicación?' ), 'salud', array( 'datosmedicos_medicacion_c' ), 'texto', 'salud' ),
			'enfermedades'     => array( asde_t( 'Enfermedades' ), 'salud', array( 'datosmedicos_enfermedades_c' ), 'texto', 'salud' ),
			'discapacidad'     => array( asde_t( 'Discapacidad / minusvalías' ), 'salud', array( 'datosmedicos_minusvalias_c' ), 'texto', 'salud' ),
			'vacunas'          => array( asde_t( 'Vacunas al día' ), 'salud', array( 'medicos_tienevacunas_c' ), 'bool', 'salud' ),
			'tarjeta_sanit'    => array( asde_t( 'Nº tarjeta sanitaria' ), 'salud', array( 'medicosnumtarjetasanitaria_c' ), 'texto', 'salud' ),
			'seguro'           => array( asde_t( 'Seguro médico privado' ), 'salud', array( 'medicos_tieneseguroprivado_c' ), 'bool', 'salud' ),
			'seguro_cia'       => array( asde_t( 'Compañía de seguro' ), 'salud', array( 'medicos_cia_seguro_c' ), 'texto', 'salud' ),
			'seguro_poliza'    => array( asde_t( 'Nº de póliza' ), 'salud', array( 'medicos_seguro_numpoliza_c' ), 'texto', 'salud' ),
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

	/** Columnas que puede usar el ámbito dado. @return array<string, array> */
	public static function permitidas( Ambito $ambito ): array {
		$puede_salud = $ambito->user->has_cap( 'asde_view_health' );
		$global      = $ambito->vision_global();
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
