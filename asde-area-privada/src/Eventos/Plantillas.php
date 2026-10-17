<?php
namespace AsdeBalears\AreaPrivada\Eventos;

defined( 'ABSPATH' ) || exit;

/**
 * Plantillas de tareas de los eventos (doc 49, fase A1): una lista de tareas con su fecha límite como «días antes
 * del inicio» o «días después del fin». Viene sembrada la de **Acampada** (las 31 tareas del programador de
 * acampadas de ASDE, P-184); cada grupo la cambia o crea las suyas (*Eventos → Configuración → Calendario de
 * tareas*). Lo que el grupo guarda va en su configuración de Eventos (`plantillas`) y la original se puede restaurar.
 *
 * Una tarea de la plantilla: título, detalle, referencia (`inicio` | `fin`), desfase en días (negativo = antes),
 * una **comprobación automática** opcional (`auto`, fase A2) y el **enlace** a la pestaña del evento que la
 * resuelve (`enlace`: resumen, equipo, inscripciones o presupuesto).
 */
final class Plantillas {

	public const ACAMPADA = 'acampada';

	/** Plantillas como mucho por grupo, y tareas como mucho por plantilla. */
	public const MAX_PLANTILLAS = 12;

	/** Comprobaciones automáticas que entiende la fase A2. */
	public const AUTOMATICAS = array( 'presupuesto', 'equipo', 'titulos', 'comunicacion', 'autorizaciones', 'menu' );

	/** Pestañas del evento a las que puede enlazar una tarea. */
	public const ENLACES = array( 'resumen', 'equipo', 'inscripciones', 'menu', 'material', 'presupuesto' );

	/** Plantilla que propone cada tipo de actividad ('' = ninguna). */
	public const POR_TIPO = array(
		'acampada' => self::ACAMPADA,
		'ruta'     => self::ACAMPADA,
	);

	/**
	 * La plantilla de Acampada del programador de ASDE (31 tareas), tal como viene en el fichero de conocimiento.
	 *
	 * @return array{nombre:string, tareas:array[]}
	 */
	public static function incorporada(): array {
		$t = static fn( string $titulo, string $ref, int $desfase, string $detalle = '', string $auto = '', string $enlace = '' ): array => array(
			'titulo'  => $titulo,
			'detalle' => $detalle,
			'ref'     => $ref,
			'desfase' => $desfase,
			'auto'    => $auto,
			'enlace'  => $enlace,
		);
		return array(
			'nombre' => 'Acampada',
			'tareas' => array(
				$t( 'Ver lugar de acampada', 'inicio', -60 ),
				$t( 'Reservar el lugar de acampada', 'inicio', -60, 'Introducir precio por persona y día O precio total, nunca ambos.', '', 'presupuesto' ),
				$t( 'Pedir presupuesto a los autobuses y prereservar', 'inicio', -45, 'Se puede anotar el precio de uno o de dos autobuses.', '', 'presupuesto' ),
				$t( 'Buscar evaluaciones de actividades similares y revisarlas', 'inicio', -45 ),
				$t( 'Pedir presupuesto a secciones e intendencias imputables a la acampada', 'inicio', -30, 'Hasta 5 gastos.', '', 'presupuesto' ),
				$t( 'Estudiar si hay actividad especial y encargarla', 'inicio', -30, 'Anotar responsables y presupuesto.' ),
				$t( 'Recopilar información sobre la comida en stock en el local de material', 'inicio', -25 ),
				$t( 'Estimar número de educandos y scouters', 'inicio', -20, 'Por rama: Castores, Lobatos, Scouts, Escultas, Rovers y Scouters.', '', 'resumen' ),
				$t( 'Estimar el número de títulos disponibles / necesarios', 'inicio', -20, 'Títulos necesarios = educandos ÷ ratio de la Comunidad Autónoma, redondeado hacia arriba.', 'titulos', 'equipo' ),
				$t( 'Elegir cocinero, intendente y ayudantes', 'inicio', -20, '', 'equipo', 'equipo' ),
				$t( 'Elaborar menú', 'inicio', -18, 'Revisar alergias típicas de los educandos del grupo.', 'menu', 'menu' ),
				$t( 'Estimar presupuesto de comidas', 'inicio', -18, 'Precio por acampado y día × acampados (educandos + scouters) × días.', '', 'presupuesto' ),
				$t( 'Comprobar que hay suficientes scouters con titulación de manipulador de alimentos', 'inicio', -18, '', '', 'equipo' ),
				$t( 'Estimar presupuesto de acampada', 'inicio', -18, 'Calcula total y total por educando.', 'presupuesto', 'presupuesto' ),
				$t( 'Elaborar horario general de acampada y enviarlo al grupo', 'inicio', -18 ),
				$t( 'Redactar carta y fotocopiarla', 'inicio', -17 ),
				$t( 'Entregar carta en reunión local y enviarla por correo electrónico', 'inicio', -13 ),
				$t( 'Enviar carta por correos', 'inicio', -11 ),
				$t( 'Recoger autorizaciones, totales de educandos y enviar datos a las secciones', 'inicio', -8, '', 'autorizaciones', 'inscripciones' ),
				$t( 'Revisar número de títulos disponibles / necesarios', 'inicio', -8, '', 'titulos', 'equipo' ),
				$t( 'Revisar alergias médicas de asistentes, medicinas y menú', 'inicio', -8, 'Mira los avisos de alérgenos de la pestaña Menú y confírmalos o cambia el menú.', '', 'menu' ),
				$t( 'Reserva final del autobús', 'inicio', -8 ),
				$t( 'Reunión de padres', 'inicio', -7 ),
				$t( 'Presentar el plan de actividades', 'inicio', -7 ),
				$t( 'Comprar comida y embalarla', 'inicio', -3 ),
				$t( 'Enviar la ficha de la acampada a la OF (Organización Federada)', 'inicio', -3, 'Actualizar los datos antes de enviar la ficha.', 'comunicacion', 'resumen' ),
				$t( 'Preparar las intendencias y secciones el material de acampada', 'inicio', -2, '', '', 'material' ),
				$t( 'Cargar furgoneta', 'inicio', -1 ),
				$t( 'Escribir un post de la acampada en la web de grupo', 'fin', 7 ),
				$t( 'Subir fotos de la acampada a la web del grupo', 'fin', 7 ),
				$t( 'Evaluar la acampada', 'fin', 14 ),
			),
		);
	}

	/** Todos los textos de la plantilla incorporada, para que se puedan traducir. @return string[] */
	public static function textos(): array {
		$out = array( self::incorporada()['nombre'] );
		foreach ( self::incorporada()['tareas'] as $t ) {
			$out[] = $t['titulo'];
			if ( '' !== $t['detalle'] ) {
				$out[] = $t['detalle'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Las plantillas del grupo: la incorporada (o la que el grupo ha cambiado) y las que ha creado.
	 *
	 * @return array<string, array{nombre:string, tareas:array[]}>
	 */
	public static function de_grupo( array $cfg = array() ): array {
		$cfg   = $cfg ?: Config::de();
		$guard = (array) ( $cfg['plantillas'] ?? array() );
		$out   = array( self::ACAMPADA => $guard[ self::ACAMPADA ] ?? self::incorporada() );
		foreach ( $guard as $id => $p ) {
			if ( self::ACAMPADA !== $id ) {
				$out[ $id ] = $p;
			}
		}
		return $out;
	}

	/** ¿La plantilla del grupo es la original, sin cambios? */
	public static function es_original( array $cfg ): bool {
		return empty( $cfg['plantillas'][ self::ACAMPADA ] );
	}

	/** Plantilla que propone el tipo de actividad, si el grupo todavía la tiene; si no, ''. */
	public static function propuesta( string $tipo, array $cfg ): string {
		$id = self::POR_TIPO[ $tipo ] ?? '';
		return '' !== $id && isset( self::de_grupo( $cfg )[ $id ] ) ? $id : '';
	}

	/** Nombre de una plantilla para enseñarlo (el de la incorporada se traduce). */
	public static function nombre( string $id, array $p ): string {
		return self::ACAMPADA === $id && self::incorporada()['nombre'] === $p['nombre'] ? asde_t( $p['nombre'] ) : $p['nombre'];
	}

	/** Título y detalle para enseñarlos: los de la plantilla original se traducen y los del grupo se dejan como están. */
	public static function texto( string $texto ): string {
		return in_array( $texto, self::textos(), true ) ? asde_t( $texto ) : $texto;
	}

	/**
	 * Limpia lo guardado: ids, nombres, referencias, desfases y comprobaciones válidos.
	 *
	 * @param mixed $en
	 * @return array<string, array{nombre:string, tareas:array[]}>
	 */
	public static function limpiar( $en ): array {
		$out = array();
		foreach ( (array) $en as $id => $p ) {
			$id     = sanitize_key( (string) $id );
			$nombre = mb_substr( trim( (string) ( $p['nombre'] ?? '' ) ), 0, 60 );
			if ( '' === $id || '' === $nombre || count( $out ) >= self::MAX_PLANTILLAS ) {
				continue;
			}
			$tareas = array();
			foreach ( (array) ( $p['tareas'] ?? array() ) as $t ) {
				$limpia = self::limpiar_tarea( (array) $t );
				if ( $limpia && count( $tareas ) < TareasTable::MAX ) {
					$tareas[] = $limpia;
				}
			}
			$out[ $id ] = array(
				'nombre' => $nombre,
				'tareas' => $tareas,
			);
		}
		return $out;
	}

	/** @return array|null La tarea limpia, o null si no tiene título. */
	public static function limpiar_tarea( array $t ): ?array {
		$titulo = mb_substr( trim( sanitize_text_field( (string) ( $t['titulo'] ?? '' ) ) ), 0, 200 );
		if ( '' === $titulo ) {
			return null;
		}
		$auto   = (string) ( $t['auto'] ?? '' );
		$enlace = (string) ( $t['enlace'] ?? '' );
		return array(
			'titulo'  => $titulo,
			'detalle' => mb_substr( trim( sanitize_text_field( (string) ( $t['detalle'] ?? '' ) ) ), 0, 400 ),
			'ref'     => in_array( $t['ref'] ?? '', TareasTable::REFS, true ) ? (string) $t['ref'] : 'inicio',
			'desfase' => max( -730, min( 730, (int) ( $t['desfase'] ?? 0 ) ) ),
			'auto'    => in_array( $auto, self::AUTOMATICAS, true ) ? $auto : '',
			'enlace'  => in_array( $enlace, self::ENLACES, true ) ? $enlace : '',
		);
	}

	/** Identificador para una plantilla nueva a partir de su nombre. */
	public static function id_para( string $nombre, array $existentes ): string {
		$base = substr( sanitize_key( remove_accents( str_replace( ' ', '-', strtolower( $nombre ) ) ) ), 0, 30 ) ?: 'plantilla';
		$id   = $base;
		for ( $n = 2; isset( $existentes[ $id ] ); $n++ ) {
			$id = $base . '-' . $n;
		}
		return $id;
	}
}
