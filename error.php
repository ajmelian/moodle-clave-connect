<?php
require('../../config.php');

$type = required_param('type', PARAM_ALPHA);

$PAGE->set_url('/auth/mcc/error.php', ['type' => $type]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('pluginname', 'auth_mcc'));
$PAGE->set_heading(get_string('pluginname', 'auth_mcc'));

$config = get_config('auth_mcc');

$html = '';
if ($type === 'clave') {
    $html = (string)($config->custom_error_clave_html ?? '');
} else if ($type === 'ldap') {
    $html = (string)($config->custom_error_ldap_html ?? '');
} else if ($type === 'pwd') {
    $html = (string)($config->custom_error_pwd_html ?? '');
}

if ($html === '') {
    $html = '<p>No se ha podido completar la operación solicitada.</p>';
}

echo $OUTPUT->header();

echo html_writer::start_div('loginbox clearfix');
echo html_writer::start_div('loginpanel');

echo $OUTPUT->notification(
    format_text($html, FORMAT_HTML, ['trusted' => false, 'noclean' => false]),
    \core\output\notification::NOTIFY_ERROR
);

echo html_writer::div(
    html_writer::link(
        new moodle_url('/login/index.php'),
        get_string('continue'),
        ['class' => 'btn btn-primary']
    ),
    'mt-3'
);

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
