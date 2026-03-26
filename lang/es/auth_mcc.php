<?php
/**
 * Cadenas de idioma (es) para auth_mcc.
 *
 * @package   auth_mcc
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Moodle Cl@ve Connect (Keycloak OIDC)';

// Errores funcionales (usados al redirigir a /login/index.php?error=...).
$string['auth_mcc_usernotfound'] =
    'Usuario no reconocido. No se ha encontrado ninguna cuenta en Moodle asociada al identificador proporcionado. ' .
    'Si ya dispone de cuenta, contacte con soporte.';

// Cabecera e información técnica en Settings.
$string['settings_header'] = 'Moodle Cl@ve Connect — Información';
$string['settings_intro'] =
    'Moodle Cl@ve Connect actúa como capa de reconciliación y control del flujo de autenticación, ' .
    'integrando Cl@ve (OIDC/Keycloak) con cuentas locales y resolviendo casos de LDAP y normalización de username.';
$string['settings_version'] = 'Versión instalada: {$a}';
$string['settings_howto_title'] = 'Indicador obligatorio en el formulario de login (forceauth)';

// Ajustes (Settings).
$string['openid_authname'] = 'Nombre interno del método OIDC';
$string['openid_authname_desc'] =
    'Nombre del método de autenticación OIDC utilizado en el sitio (por ejemplo, "oidc").';

$string['enable_hotswitch'] = 'Activar lógica del plugin (hotswitch)';
$string['enable_hotswitch_desc'] =
    'Si está activo, el plugin aplicará la lógica de normalización y reconciliación. ' .
    'Incluye la política de reposo auth=oidc tras autenticación, con exclusión de usuarios de emergencia.';

$string['emergency_admin_username'] = 'Usuario admin de emergencia (legacy)';
$string['emergency_admin_username_desc'] =
    'Username único de emergencia (compatibilidad). Este usuario queda excluido de las conmutaciones automáticas.';

$string['emergency_usernames'] = 'Usernames de emergencia (break-glass)';
$string['emergency_usernames_desc'] =
    'Lista de usernames (uno por línea o separados por coma) que quedan excluidos de la lógica del plugin. ' .
    'Deben permanecer con auth=manual y con password local (hash real). Se recomienda que sean cuentas de administración ' .
    'para contingencias (continuidad del servicio). Comparación case-insensitive.';

// Nuevo bloque: mensajes HTML configurables para errores en login.
$string['custom_errors_heading'] = 'Mensajes personalizados de error en la pantalla de login';
$string['custom_errors_heading_desc'] =
    'Permite definir mensajes HTML específicos, editables por el cliente, para los casos de acceso fallido por Cl@ve, por errores técnicos LDAP y por credenciales LDAP incorrectas. ' .
    'Estos mensajes se mostrarán exclusivamente en la pantalla de login y sustituyen a los mensajes estándar de Moodle gestionados por el plugin.';

$string['custom_error_clave_html'] = 'Mensaje HTML para error de acceso por Cl@ve';
$string['custom_error_clave_html_desc'] =
    'Mensaje HTML que se mostrará cuando un usuario se autentique correctamente por Cl@ve, pero no exista ninguna cuenta asociada en mdl_user. ' .
    'Puede incluir enlaces, párrafos y formato básico.';

$string['custom_error_ldap_html'] = 'Mensaje HTML para error de acceso por LDAP';
$string['custom_error_ldap_html_desc'] =
    'Mensaje HTML que se mostrará en la pantalla de login cuando falle el acceso por LDAP por causas técnicas o de disponibilidad del servicio.';

$string['custom_error_pwd_html'] = 'Mensaje HTML para error en credenciales';
$string['custom_error_pwd_html_desc'] =
    'Mensaje HTML que se mostrará cuando el flujo LDAP devuelva credenciales incorrectas.';
