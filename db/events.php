<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\core\event\user_created',
        'callback'    => '\auth_mcc\observer::on_user_created_store_password_history',
        'includefile' => '/auth/mcc/classes/observer.php',
        'priority'    => 5000,
        'internal'    => false,
    ],
    [
        'eventname'   => '\core\event\user_password_updated',
        'callback'    => '\auth_mcc\observer::on_user_password_updated_store_password_history',
        'includefile' => '/auth/mcc/classes/observer.php',
        'priority'    => 5000,
        'internal'    => false,
    ],
    [
        'eventname'   => '\core\event\user_updated',
        'callback'    => '\auth_mcc\observer::on_user_updated_maybe_store_password_history',
        'includefile' => '/auth/mcc/classes/observer.php',
        'priority'    => 4000,
        'internal'    => false,
    ],

    // Interceptar fallos de login cuando venimos de flujo Cl@ve.
    [
        'eventname'   => '\core\event\user_login_failed',
        'callback'    => '\auth_mcc\observer::on_user_login_failed',
        'includefile' => '/auth/mcc/classes/observer.php',
        'priority'    => 9999,
        'internal'    => false,
    ],

    // Post-login (manual/ldap/oidc): forzar reposo auth=oidc y coherencia de password/histórico.
    [
        'eventname'   => '\core\event\user_loggedin',
        'callback'    => '\auth_mcc\observer::on_user_loggedin',
        'includefile' => '/auth/mcc/classes/observer.php',
        'priority'    => 9999,
        'internal'    => false,
    ],
];
