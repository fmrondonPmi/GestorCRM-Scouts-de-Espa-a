<?php
namespace AsdeBalears\AreaPrivada\Sepa;

defined( 'ABSPATH' ) || exit;

/**
 * Fichero de adeudos directos SEPA Core (ISO 20022 pain.008.001.02, el que
 * aceptan los bancos españoles como «Cuaderno 19.14»).
 *
 * Estructura: cabecera del mensaje (GrpHdr) y un bloque de pago (PmtInf) por
 * cada tipo de secuencia (FRST para las autorizaciones que se usan por primera
 * vez y RCUR para el resto), cada uno con sus adeudos (DrctDbtTxInf).
 *
 * Los textos se limpian al juego de caracteres SEPA (sin tildes ni ñ) y los
 * importes van en céntimos (enteros) hasta el final para no arrastrar decimales.
 */
final class Pain008 {

	public const NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02';

	/**
	 * Lo que impediría generar un fichero válido (vacío = correcto).
	 *
	 * @param array   $cfg    Configuración del acreedor (Config::de).
	 * @param array[] $lineas Adeudos: [familia_n, umr, fecha_firma, titular, iban, secuencia, centimos, e2e].
	 * @return string[]
	 */
	public static function errores( array $cfg, string $fecha_cobro, array $lineas ): array {
		$e = Config::errores( $cfg );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha_cobro ) ) {
			$e[] = 'La fecha de cobro no es válida.';
		}
		if ( ! $lineas ) {
			$e[] = 'No hay ningún adeudo que incluir.';
		}
		foreach ( $lineas as $l ) {
			$ref = $l['familia_n'] ?? '?';
			if ( (int) $l['centimos'] <= 0 ) {
				$e[] = sprintf( 'Familia %s: el importe debe ser mayor que 0.', $ref );
			}
			if ( ! Iban::es_valido( (string) $l['iban'] ) ) {
				$e[] = sprintf( 'Familia %s: el IBAN de la autorización no es válido (o ya no se conserva).', $ref );
			}
			if ( '' === Texto::limpiar( (string) $l['titular'], 70 ) ) {
				$e[] = sprintf( 'Familia %s: falta el nombre del titular.', $ref );
			}
			if ( ! in_array( $l['secuencia'], array( 'FRST', 'RCUR' ), true ) ) {
				$e[] = sprintf( 'Familia %s: tipo de secuencia no válido.', $ref );
			}
		}
		return array_values( array_unique( $e ) );
	}

	/**
	 * @param array   $cfg     Configuración del acreedor (Config::de).
	 * @param array   $remesa  [numero, msg_id, fecha_cobro (AAAA-MM-DD), concepto, creada (AAAA-MM-DDThh:mm:ss)]
	 * @param array[] $lineas  Ver errores().
	 * @return string XML (UTF-8).
	 */
	public static function generar( array $cfg, array $remesa, array $lineas ): string {
		$doc               = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;
		$raiz              = $doc->createElementNS( self::NAMESPACE, 'Document' );
		$doc->appendChild( $raiz );
		$init = self::hijo( $doc, $raiz, 'CstmrDrctDbtInitn' );

		$acreedor = Texto::limpiar( (string) $cfg['nombre'], 70 );
		$total    = array_sum( array_map( static fn( $l ) => (int) $l['centimos'], $lineas ) );

		// Cabecera del mensaje.
		$g = self::hijo( $doc, $init, 'GrpHdr' );
		self::hijo( $doc, $g, 'MsgId', $remesa['msg_id'] );
		self::hijo( $doc, $g, 'CreDtTm', $remesa['creada'] );
		self::hijo( $doc, $g, 'NbOfTxs', (string) count( $lineas ) );
		self::hijo( $doc, $g, 'CtrlSum', Texto::importe( $total ) );
		$p = self::hijo( $doc, $g, 'InitgPty' );
		self::hijo( $doc, $p, 'Nm', $acreedor );
		$o = self::hijo( $doc, self::hijo( $doc, self::hijo( $doc, $p, 'Id' ), 'OrgId' ), 'Othr' );
		self::hijo( $doc, $o, 'Id', $cfg['ics'] );

		// Un bloque de pago por tipo de secuencia.
		foreach ( array( 'FRST', 'RCUR' ) as $seq ) {
			$del_tipo = array_values( array_filter( $lineas, static fn( $l ) => $seq === $l['secuencia'] ) );
			if ( ! $del_tipo ) {
				continue;
			}
			$subtotal = array_sum( array_map( static fn( $l ) => (int) $l['centimos'], $del_tipo ) );
			$b        = self::hijo( $doc, $init, 'PmtInf' );
			self::hijo( $doc, $b, 'PmtInfId', Texto::referencia( $remesa['numero'] . '-' . $seq ) );
			self::hijo( $doc, $b, 'PmtMtd', 'DD' );
			self::hijo( $doc, $b, 'BtchBookg', 'true' );
			self::hijo( $doc, $b, 'NbOfTxs', (string) count( $del_tipo ) );
			self::hijo( $doc, $b, 'CtrlSum', Texto::importe( $subtotal ) );
			$tipo = self::hijo( $doc, $b, 'PmtTpInf' );
			self::hijo( $doc, self::hijo( $doc, $tipo, 'SvcLvl' ), 'Cd', 'SEPA' );
			self::hijo( $doc, self::hijo( $doc, $tipo, 'LclInstrm' ), 'Cd', 'CORE' );
			self::hijo( $doc, $tipo, 'SeqTp', $seq );
			self::hijo( $doc, $b, 'ReqdColltnDt', $remesa['fecha_cobro'] );

			$cdtr = self::hijo( $doc, $b, 'Cdtr' );
			self::hijo( $doc, $cdtr, 'Nm', $acreedor );
			$dir = array_filter( array( Texto::limpiar( (string) $cfg['calle'], 70 ), Texto::limpiar( trim( $cfg['cp'] . ' ' . $cfg['poblacion'] ), 70 ) ) );
			if ( $dir ) {
				$adr = self::hijo( $doc, $cdtr, 'PstlAdr' );
				self::hijo( $doc, $adr, 'Ctry', substr( (string) $cfg['iban'], 0, 2 ) );
				foreach ( $dir as $linea ) {
					self::hijo( $doc, $adr, 'AdrLine', $linea );
				}
			}
			self::hijo( $doc, self::hijo( $doc, $b, 'CdtrAcct' ), 'IBAN', $cfg['iban'], 'Id' );
			$agente = self::hijo( $doc, self::hijo( $doc, $b, 'CdtrAgt' ), 'FinInstnId' );
			if ( '' !== $cfg['bic'] ) {
				self::hijo( $doc, $agente, 'BIC', $cfg['bic'] );
			} else {
				self::hijo( $doc, self::hijo( $doc, $agente, 'Othr' ), 'Id', 'NOTPROVIDED' );
			}
			self::hijo( $doc, $b, 'ChrgBr', 'SLEV' );
			$esquema = self::hijo( $doc, self::hijo( $doc, self::hijo( $doc, $b, 'CdtrSchmeId' ), 'Id' ), 'PrvtId' );
			$otro    = self::hijo( $doc, $esquema, 'Othr' );
			self::hijo( $doc, $otro, 'Id', $cfg['ics'] );
			self::hijo( $doc, self::hijo( $doc, $otro, 'SchmeNm' ), 'Prtry', 'SEPA' );

			foreach ( $del_tipo as $l ) {
				$t = self::hijo( $doc, $b, 'DrctDbtTxInf' );
				self::hijo( $doc, self::hijo( $doc, $t, 'PmtId' ), 'EndToEndId', Texto::referencia( (string) $l['e2e'] ) );
				$imp = self::hijo( $doc, $t, 'InstdAmt', Texto::importe( (int) $l['centimos'] ) );
				$imp->setAttribute( 'Ccy', 'EUR' );
				$mandato = self::hijo( $doc, self::hijo( $doc, $t, 'DrctDbtTx' ), 'MndtRltdInf' );
				self::hijo( $doc, $mandato, 'MndtId', Texto::referencia( (string) $l['umr'] ) );
				self::hijo( $doc, $mandato, 'DtOfSgntr', (string) $l['fecha_firma'] );
				$banco = self::hijo( $doc, self::hijo( $doc, $t, 'DbtrAgt' ), 'FinInstnId' );
				self::hijo( $doc, self::hijo( $doc, $banco, 'Othr' ), 'Id', 'NOTPROVIDED' );
				self::hijo( $doc, self::hijo( $doc, $t, 'Dbtr' ), 'Nm', Texto::limpiar( (string) $l['titular'], 70 ) );
				self::hijo( $doc, self::hijo( $doc, $t, 'DbtrAcct' ), 'IBAN', Iban::normalizar( (string) $l['iban'] ), 'Id' );
				$concepto = Texto::limpiar( trim( $remesa['concepto'] . ' ' . $l['familia_n'] ), 140 );
				self::hijo( $doc, self::hijo( $doc, $t, 'RmtInf' ), 'Ustrd', $concepto );
			}
		}
		return (string) $doc->saveXML();
	}

	/**
	 * Añade un elemento hijo (con texto, si se da). Con $envuelve, el texto va en un
	 * elemento intermedio de ese nombre: <CdtrAcct><Id><IBAN>…</IBAN></Id></CdtrAcct>.
	 */
	private static function hijo( \DOMDocument $doc, \DOMElement $padre, string $nombre, ?string $texto = null, string $envuelve = '' ): \DOMElement {
		if ( '' !== $envuelve ) {
			$padre = self::hijo( $doc, $padre, $envuelve );
		}
		$e = $doc->createElementNS( self::NAMESPACE, $nombre );
		if ( null !== $texto ) {
			$e->appendChild( $doc->createTextNode( $texto ) );
		}
		$padre->appendChild( $e );
		return $e;
	}
}
