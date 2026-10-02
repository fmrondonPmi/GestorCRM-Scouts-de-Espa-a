<?php
namespace AsdeBalears\AreaPrivada\Crm;

defined( 'ABSPATH' ) || exit;

/** Error al leer del CRM. El mensaje nunca incluye datos personales. */
final class CrmException extends \RuntimeException {}
