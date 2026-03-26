<?php
/**
 * Language strings (en) for auth_mcc.
 *
 * @package   auth_mcc
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Moodle Cl@ve Connect (Keycloak OIDC)';

// Functional errors (used when redirecting to /login/index.php?error=...).
$string['auth_mcc_usernotfound'] =
    'Unrecognized user. No Moodle account was found for the provided identifier. ' .
    'If you already have an account, please contact support.';

// Settings header and technical information.
$string['settings_header'] = 'Moodle Cl@ve Connect - Information';
$string['settings_intro'] =
    'Moodle Cl@ve Connect acts as a reconciliation and control layer for authentication flows, ' .
    'integrating Cl@ve (OIDC/Keycloak) with local accounts and handling LDAP and username normalization scenarios.';
$string['settings_version'] = 'Installed version: {$a}';
$string['settings_howto_title'] = 'Required indicator in the login form (forceauth)';

// Settings.
$string['openid_authname'] = 'Internal OIDC auth method name';
$string['openid_authname_desc'] =
    'Name of the OIDC authentication method used on the site (for example, "oidc").';

$string['enable_hotswitch'] = 'Enable plugin logic (hotswitch)';
$string['enable_hotswitch_desc'] =
    'When enabled, the plugin applies normalization and reconciliation logic. ' .
    'This includes the auth=oidc resting-state policy after authentication, excluding emergency users.';

$string['emergency_admin_username'] = 'Emergency admin user (legacy)';
$string['emergency_admin_username_desc'] =
    'Single emergency username (compatibility). This user is excluded from automatic switching.';

$string['emergency_usernames'] = 'Emergency usernames (break-glass)';
$string['emergency_usernames_desc'] =
    'List of usernames (one per line or comma-separated) excluded from plugin logic. ' .
    'They must remain with auth=manual and with a local password (real hash). Recommended for admin contingency accounts ' .
    'to guarantee service continuity. Case-insensitive match.';

// Custom HTML error messages shown on login.
$string['custom_errors_heading'] = 'Custom error messages on the login screen';
$string['custom_errors_heading_desc'] =
    'Allows defining specific HTML messages, editable by the client, for Cl@ve failures, LDAP technical failures, and invalid LDAP credentials. ' .
    'These messages are shown only on the login screen and replace Moodle standard messages handled by the plugin.';

$string['custom_error_clave_html'] = 'HTML message for Cl@ve access error';
$string['custom_error_clave_html_desc'] =
    'HTML message shown when a user authenticates successfully through Cl@ve but no associated account exists in mdl_user. ' .
    'It may include links, paragraphs, and basic formatting.';

$string['custom_error_ldap_html'] = 'HTML message for LDAP access error';
$string['custom_error_ldap_html_desc'] =
    'HTML message shown on the login screen when LDAP access fails due to technical, connectivity, or service availability issues.';

$string['custom_error_pwd_html'] = 'HTML message for invalid LDAP credentials';
$string['custom_error_pwd_html_desc'] =
    'HTML message shown when the LDAP flow returns invalid credentials.';
