<?php
/**
 * Settings page for auth_mcc.
 *
 * IMPORTANTE:
 * En plugins de autenticación (auth_*), NO se debe llamar a $ADMIN->add(...)
 * porque Moodle ya inserta la página automáticamente (load_settings()).
 * Si se llama, se producen duplicados tipo:
 *  - Duplicate admin page name: authsettingmcc
 *  - Adding a node that already exists authsettingmcc
 *
 * @package    auth_mcc
 * @copyright  2025 Aythami Melián Perdomo - hola@ajmelian.info
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Crear la página de ajustes del plugin.
    $settings = new admin_settingpage('authsettingmcc', get_string('pluginname', 'auth_mcc'));

    // --- Cabecera informativa con versión y recordatorio técnico ---
    $plugininfo = core_plugin_manager::instance()->get_plugin_info('auth_mcc');
    $versionstr = '';
    if ($plugininfo && !empty($plugininfo->versiondisk)) {
        $versionstr = html_writer::tag('code', s($plugininfo->versiondisk));
    }

    $intro  = html_writer::tag('p', get_string('settings_intro', 'auth_mcc'));
    $intro .= html_writer::tag('p', get_string('settings_version', 'auth_mcc', $versionstr));

    // Bloque sobre la intención manual/ldap en el formulario de login.
    $howto  = html_writer::tag('p', get_string('settings_howto_title', 'auth_mcc'));
    $howto .= html_writer::tag(
        'pre',
        html_writer::tag(
            'code',
            htmlentities('<input type="hidden" name="forceauth" value="manual">') . "\n" .
            htmlentities('<!-- o -->') . "\n" .
            htmlentities('<input type="hidden" name="forceauth" value="ldap">')
        )
    );

    $settings->add(new admin_setting_heading(
        'auth_mcc_header',
        get_string('settings_header', 'auth_mcc'),
        $intro . $howto
    ));

    // --- Parámetros principales ---
    $settings->add(new admin_setting_configcheckbox(
        'auth_mcc/enable_hotswitch',
        get_string('enable_hotswitch', 'auth_mcc'),
        get_string('enable_hotswitch_desc', 'auth_mcc'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'auth_mcc/openid_authname',
        get_string('openid_authname', 'auth_mcc'),
        get_string('openid_authname_desc', 'auth_mcc'),
        'oidc'
    ));

    /**
     * Compatibilidad hacia atrás:
     * - Se mantiene el setting "emergency_admin_username" (single username),
     *   pero a partir de la versión que incorpora varios usuarios de emergencia,
     *   se debe usar el nuevo setting "emergency_usernames".
     *
     * La lógica del plugin (auth.php) deberá consolidar ambas configuraciones:
     *   - emergency_admin_username (legacy) + emergency_usernames (lista)
     */
    $settings->add(new admin_setting_configtext(
        'auth_mcc/emergency_admin_username',
        get_string('emergency_admin_username', 'auth_mcc'),
        get_string('emergency_admin_username_desc', 'auth_mcc'),
        'admin'
    ));

    // --- Lista de usuarios de emergencia (break-glass) ---
    // - Un username por línea (recomendado) o separados por coma.
    // - Comparación case-insensitive en tiempo de ejecución.
    // - A estos usuarios NO se les aplica: normalización NIF/NIE, conmutación auth=oidc, ni lógica LDAP/Cl@ve.
    $settings->add(new admin_setting_configtextarea(
        'auth_mcc/emergency_usernames',
        get_string('emergency_usernames', 'auth_mcc'),
        get_string('emergency_usernames_desc', 'auth_mcc'),
        "admin\n"
    ));

    // -------------------------------------------------------------------------
    // Bloque de mensajes personalizados de error en pantalla de login.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'auth_mcc_custom_errors_heading',
        get_string('custom_errors_heading', 'auth_mcc'),
        get_string('custom_errors_heading_desc', 'auth_mcc')
    ));

    // Mensaje HTML configurable para error de acceso por Cl@ve:
    // usuario autenticado externamente, pero no existe en mdl_user.
    $defaultclaveerrorhtml = '<p>Si no ha podido acceder por ninguno de los sistemas de validación, '
        . 'póngase en contacto con Formación (Servicio de Inclusión Educativa y Formación del Profesorado).</p>'
        . '<p>Puede consultar el <a href="https://www.educastur.es/consejeria/institucional/dir-org">'
        . 'directorio de la Consejería de Educación</a>.</p>';

    $settings->add(new admin_setting_confightmleditor(
        'auth_mcc/custom_error_clave_html',
        get_string('custom_error_clave_html', 'auth_mcc'),
        get_string('custom_error_clave_html_desc', 'auth_mcc'),
        $defaultclaveerrorhtml
    ));

    // Mensaje HTML configurable para errores técnicos de LDAP:
    // - usuario no disponible en el backend LDAP
    // - fallo técnico de conexión o disponibilidad LDAP
    $defaultldaperrorhtml = '<p>No ha sido posible acceder mediante el sistema LDAP.</p>'
        . '<p>Si el problema persiste, póngase en contacto con Formación '
        . '(Servicio de Inclusión Educativa y Formación del Profesorado).</p>';

    $settings->add(new admin_setting_confightmleditor(
        'auth_mcc/custom_error_ldap_html',
        get_string('custom_error_ldap_html', 'auth_mcc'),
        get_string('custom_error_ldap_html_desc', 'auth_mcc'),
        $defaultldaperrorhtml
    ));

    // Mensaje HTML configurable para credenciales LDAP incorrectas.
    $defaultpwderrorhtml = '<p>Las credenciales introducidas son erroneas.</p>'
        . '<p>Si el problema persiste, póngase en contacto con Formación '
        . '(Servicio de Inclusión Educativa y Formación del Profesorado).</p>';

    $settings->add(new admin_setting_confightmleditor(
        'auth_mcc/custom_error_pwd_html',
        get_string('custom_error_pwd_html', 'auth_mcc'),
        get_string('custom_error_pwd_html_desc', 'auth_mcc'),
        $defaultpwderrorhtml
    ));

}
