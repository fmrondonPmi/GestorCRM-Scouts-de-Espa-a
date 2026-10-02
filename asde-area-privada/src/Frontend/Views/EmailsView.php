<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Registro de emails del grupo: si cada invitación o recuperación salió o no, y
 * cómo era el email (vista reconstruida, sin el enlace personal).
 */
final class EmailsView extends View {

	private const CAP = 'asde_manage_users';

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$mailpit = defined( 'ASDE_AP_MAILPIT_URL' ) ? (string) ASDE_AP_MAILPIT_URL : '';

		$id = (int) $this->get( 'ver' );
		if ( $id ) {
			$fila = RegistroEmails::obtener( $id );
			if ( ! $fila ) {
				return $this->denegado( 'Ese email no existe o es de otro grupo.' );
			}
			return $this->layout(
				'usuarios',
				$this->tpl(
					'usuarios/email',
					array(
						'fila'    => $fila,
						'html'    => RegistroEmails::vista_previa( $fila ),
						'autor'   => $fila['autor_id'] ? get_userdata( (int) $fila['autor_id'] ) : null,
						'url'     => $this->url( 'emails' ),
						'mailpit' => $mailpit,
					)
				)
			);
		}

		$user_id = (int) $this->get( 'usuario' );
		$estado  = $this->get( 'estado' );
		$filas   = RegistroEmails::listado( $user_id, $estado );
		$tabla   = new Tabla(
			array(
				'fecha'   => static fn( $f ) => $f['creado'],
				'usuario' => static fn( $f ) => $f['login'],
				'tipo'    => static fn( $f ) => RegistroEmails::TIPOS[ $f['tipo'] ] ?? $f['tipo'],
				'estado'  => static fn( $f ) => RegistroEmails::ESTADOS[ $f['estado'] ] ?? $f['estado'],
			),
			'fecha',
			'desc'
		);
		$total  = count( $filas );
		$pagina = $tabla->aplicar( $filas );

		// Nombres de las personas de esta página, leídos del CRM (una sola consulta).
		$nombres = array();
		$repo    = PersonaRepository::make();
		if ( $repo && $pagina ) {
			$socios = array();
			foreach ( array_unique( array_column( $pagina, 'user_id' ) ) as $uid ) {
				$socio = Usuarios::socio( (int) $uid );
				if ( $socio ) {
					$socios[ (int) $uid ] = $socio;
				}
			}
			try {
				$personas = $repo->obtener_varias( array_values( $socios ) );
				foreach ( $socios as $uid => $socio ) {
					if ( isset( $personas[ $socio ] ) ) {
						$nombres[ $uid ] = $personas[ $socio ]->nombre_completo();
					}
				}
			} catch ( CrmException $e ) {
				$nombres = array();
			}
		}

		return $this->layout(
			'usuarios',
			$this->tpl(
				'usuarios/emails',
				array(
					'filas'    => $pagina,
					'total'    => $total,
					'tabla'    => $tabla,
					'nombres'  => $nombres,
					'estado'   => isset( RegistroEmails::ESTADOS[ $estado ] ) ? $estado : '',
					'usuario'  => $user_id ? get_userdata( $user_id ) : null,
					'url'      => $this->url( 'emails' ),
					'url_usuarios' => $this->url( 'usuarios' ),
					'mailpit'  => $mailpit,
				)
			)
		);
	}
}
