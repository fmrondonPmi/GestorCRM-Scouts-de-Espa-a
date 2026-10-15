<?php
/**
 * Deja el WordPress de pruebas como el Local del usuario: el grupo 684 («Grupo Scout Ejemplo»)
 * con un grupo de seguridad del CRM inventado, la URL del CRM simulado de las pruebas, la
 * organización federada y lo que hace «Instalación y entorno».
 * Uso: wp eval-file preparar.php
 */
use AsdeBalears\AreaPrivada\Crm\Crypto;

update_option( 'asde_ap_crm', array( 'url' => 'https://crm-pruebas.example.invalid' ), false );
update_option(
	'asde_ap_grupos',
	array(
		'684' => array(
			'id'             => '684',
			'numero'         => '684',
			'nombre'         => 'Grupo Scout Ejemplo',
			'usuario'        => 'usuario-api',
			'clave_enc'      => Crypto::encrypt( 'clave-de-pruebas' ),
			'crm_id'         => 'sg000000-0000-4000-8000-000000000684',
			'filtrar'        => true,
			'estado'         => 'ok',
			'mensaje'        => '',
			'detalles'       => array(),
			'avisos'         => array(),
			'probado'        => time(),
			// No con @example.invalid: sepa.php comprueba que el registro de emails no guarda ese dominio.
			'email_contacto' => 'contacto@grupo-ejemplo.test',
		),
	),
	false
);
\AsdeBalears\AreaPrivada\Domain\Grupos::olvidar();
\AsdeBalears\AreaPrivada\Domain\Organizacion::migrar();
\AsdeBalears\AreaPrivada\Install\Installer::install();
\AsdeBalears\AreaPrivada\Frontend\Visibilidad::asegurar_menu();
echo 'Grupo 684 de pruebas listo; organización: ' . \AsdeBalears\AreaPrivada\Domain\Organizacion::datos()['organizacion'] . "\n";
