<?php
/**
 * Fichas INVENTADAS con las que arranca el servidor de la API simulado (y a las que
 * vuelve con /reset). Grupo de prueba 999. Documentos con la letra bien calculada
 * pero inventados.
 *
 * @return array{grupo_sg:string, estado:array}
 */

$grupo_sg = 'de000002-0000-4000-8000-000000000999';
$P        = array( 'Asde_Prueba_Crm', 'persona' );
$doc      = static fn( string $d ): array => array( 'stic_identification_number_c' => $d );
$tutores  = array(
	'tutor1_nombre_c'    => 'Marta',
	'tutor1_apellidos_c' => 'Ejemplo Font',
	'tutor1_email_c'     => 'marta.ejemplo@example.invalid',
	'tutor2_nombre_c'    => 'Jordi',
	'tutor2_apellidos_c' => 'Ejemplo Roig',
);
$crm      = new Asde_Prueba_Crm(
	array(
		$P( 1, 'Laia', 'Ejemplo Font', '2011-04-12', 'Scouts', $doc( '12345678Z' ) + $tutores ),        // Con documento (13-14 años).
		$P( 2, 'Pol', 'Ejemplo Font', '2017-09-03', 'Lobatos', $tutores ),                             // Menor sin documento.
		$P( 3, 'Clara', 'Prueba Mas', '1992-02-20', 'Scouters', $doc( '87654321X' ) + array( 'email1' => 'clara.prueba@example.invalid' ) ),
		$P( 4, 'Nil', 'Prueba Vidal', '2008-11-30', 'Escultas', $doc( 'X1234567L' ) ),                 // NIE.
		$P( 5, 'Joan', 'Ficticio Pons', '1999-06-06', '', $doc( '22222222J' ) ),                       // Sin membresía activa: «quien vuelve».
	),
	$grupo_sg
);
$crm->numgrupo     = '999';
$crm->otros_grupos = array(
	$P( 21, 'Otra', 'Persona Uno', '2010-01-01', 'Scouts', $doc( '11111111H' ) ),                      // Está en otro grupo u organización.
	$P( 22, 'Otra', 'Persona Dos', '1985-05-05', 'Scouters', $doc( '66666666Q' ) ),
);
$id = static fn( int $n ): string => sprintf( 'de000000-0000-4000-8000-%012d', 9900 + $n );
$crm->alta_membresia( $id( 1 ), 'Scouts', '2024-09-01' );
$crm->alta_membresia( $id( 2 ), 'Lobatos', '2025-09-01' );
$crm->alta_membresia( $id( 3 ), 'Scouters', '2018-09-01' );
$crm->alta_membresia( $id( 4 ), 'Escultas', '2023-09-01' );
$crm->alta_membresia( $id( 5 ), 'Rovers', '2017-09-01', '2021-06-30' );

return array(
	'grupo_sg' => $grupo_sg,
	'estado'   => $crm->exportar(),
);
