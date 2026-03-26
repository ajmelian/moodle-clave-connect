<?php
/**
 * Plugin de autenticación puente para Cl@ve (Keycloak OIDC).
 *
 * Objetivo general
 * ---------------
 * Este plugin NO altera el flujo de autenticación OIDC/Cl@ve (se inicia fuera del formulario),
 * pero sí controla el flujo del formulario de login clásico para resolver el caso LDAP y la
 * normalización de usernames.
 *
 * Nuevo flujo de formulario (login/index.php)
 * ------------------------------------------
 * A) Si el login introducido es E+NIF/NIE (mayúsculas o minúsculas):
 *    1) Validar credenciales directamente contra LDAP usando el login tal cual (E...).
 *    2) Si LDAP devuelve credenciales incorrectas:
 *       - si existe el usuario canónico en Moodle (NIF/NIE), restablecer auth=oidc.
 *       - redirigir a /auth/mcc/error.php?type=pwd.
 *    3) Si LDAP devuelve error técnico o indisponibilidad:
 *       - redirigir a /auth/mcc/error.php?type=ldap.
 *    4) Si LDAP OK → eliminar la E inicial, quedando NIF/NIE.
 *    5) Buscar usuario en mdl_user por username = NIF/NIE (case-insensitive).
 *       - Si existe: iniciar sesión (complete_user_login) y fijar auth=oidc.
 *       - Si no existe: crear usuario con datos traídos de LDAP, username=NIF/NIE, auth=oidc,
 *         e iniciar sesión.
 *
 * B) Si el login NO es E+NIF/NIE:
 *    1) Buscar usuario en mdl_user por username = login introducido (case-insensitive).
 *    2) Si existe: no se hace login aquí (para no bypassear password); se deja al core autenticar.
 *       Tras login correcto, se fuerza reposo auth=oidc en user_authenticated_hook().
 *    3) Si no existe: devolver error "usuario no existente" al login.
 *
 * Usuarios de emergencia (break-glass)
 * -----------------------------------
 * Se admite un conjunto de usuarios de emergencia, configurables, que quedan EXENTOS de la lógica del plugin.
 * Requisitos funcionales:
 *  - username no sigue patrón NIF/NIE (puede ser admin/root/soporte/etc.).
 *  - auth debe permanecer SIEMPRE en 'manual'.
 *  - password debe permanecer con hash real (no "not cached").
 *
 * Por tanto, para estos usuarios:
 *  - NO se normaliza username.
 *  - NO se fuerza auth=oidc (ni en pre-login ni en post-login).
 *  - NO se ejecuta LDAP-first, autocreación ni restauraciones de password/histórico.
 *
 * Mensajes personalizados de error
 * --------------------------------
 * El plugin permite configurar desde settings tres mensajes HTML:
 *  - custom_error_clave_html
 *  - custom_error_ldap_html
 *  - custom_error_pwd_html
 *
 * En esta versión, dichos mensajes se muestran en una página intermedia propia del plugin:
 *   /auth/mcc/error.php?type=clave
 *   /auth/mcc/error.php?type=ldap
 *   /auth/mcc/error.php?type=pwd
 *
 * @package     auth_mcc
 * @category    authentication
 * @author      Aythami Melián Perdomo - hola@ajmelian.info
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/authlib.php');

class auth_plugin_mcc extends auth_plugin_base {

    /**
     * Constructor del plugin.
     */
    public function __construct() {
        $this->authtype = 'mcc';
        $this->config   = get_config('auth_mcc');
    }

    /**
     * Hook del formulario de login (se ejecuta al cargar /login/index.php).
     *
     * Responsabilidades:
     * - Gestionar el flujo del formulario (solo en POST).
     *
     * NOTA:
     * - Cl@ve/OIDC no usa este formulario para autenticarse.
     * - La gestión del caso "OIDC sin usuario local" se realiza en observer.php al capturar
     *   user_login_failed en callback OIDC real, evitando falsos positivos en /login/index.php.
     *
     * @return void
     */
    public function loginpage_hook() {
        global $DB, $CFG, $SESSION;

        // A partir de aquí solo actuamos si realmente se ha enviado el formulario.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        // Feature flag.
        if (!get_config('auth_mcc', 'enable_hotswitch')) {
            return;
        }

        // Datos del formulario.
        $rawusername = trim(optional_param('username', '', PARAM_RAW_TRIMMED));
        $password    = (string)optional_param('password', '', PARAM_RAW);

        if ($rawusername === '') {
            return;
        }

        // Usuarios de emergencia (break-glass): no intervenir nunca.
        if ($this->is_emergency_username($rawusername)) {
            return;
        }

        // Caso A: E+NIF/NIE -> LDAP-first.
        if ($this->is_e_prefixed_nifnie($rawusername)) {
            $ldapuser  = trim($rawusername);
            $ldapattrs = null;
            $ldperr    = null;

            // 1) Validación directa contra LDAP.
            $ok = $this->ldap_bind_check($ldapuser, $password, $ldapattrs, $ldperr);
            if (!$ok) {
                if ($ldperr === 'ldap_invalid_credentials') {
                    // Si existe usuario canónico, devolverlo a reposo OIDC para no bloquear el acceso por Cl@ve.
                    $canonicalUsername = strtoupper($this->strip_e_prefix($ldapuser));
                    if ($canonicalUsername !== '') {
                        $canonicalUser = $this->find_user_by_username_ci($canonicalUsername);
                        if ($canonicalUser) {
                            $this->set_user_auth_to_oidc($canonicalUser);
                        }
                    }
                    $this->redirect_to_custom_error_page('pwd');
                    return;
                }
                $this->redirect_to_custom_error_page('ldap');
                return;
            }

            // 2) Canonicalizar a NIF/NIE (sin E) para Moodle.
            $canonical = strtoupper($this->strip_e_prefix($ldapuser));

            // Forzamos el username canónico en request/POST para consistencia.
            $_POST['username']    = $canonical;
            $_REQUEST['username'] = $canonical;

            // 3) Buscar usuario Moodle por username canónico (case-insensitive).
            $user = $this->find_user_by_username_ci($canonical);

            // 4) Si no existe, crearlo con datos del LDAP.
            if (!$user) {
                $user = $this->create_moodle_user_from_ldap($canonical, $ldapattrs);
            }

            // 5) Forzar reposo OIDC y login.
            $this->set_user_auth_to_oidc($user);

            complete_user_login($user);

            // Respetar wantsurl.
            if (!empty($SESSION->wantsurl)) {
                $target = $SESSION->wantsurl;
                unset($SESSION->wantsurl);
                redirect($target);
            }

            redirect($CFG->wwwroot);
            return;
        }

        // Caso B: NO E+NIF/NIE -> existencia en Moodle.
        $exists = $DB->record_exists_select(
            'user',
            'deleted = 0 AND LOWER(username) = LOWER(?)',
            [$rawusername]
        );

        if (!$exists) {
            $this->redirect_login_error('auth_mcc_usernotfound');
            return;
        }

        // Si existe, dejamos que Moodle continúe con su auth normal.
        return;
    }

    /**
     * Hook post-autenticación compatible con auth_plugin_base.
     *
     * Se ejecuta tras cualquier login correcto (manual/ldap/oidc/otros).
     * Política requerida: tras autenticarse, el usuario queda en reposo auth=oidc.
     *
     * Excepciones:
     * - Usuarios de emergencia (break-glass): no se modifica auth, deben permanecer manual.
     * - Si forcepasswordchange está activo, NO forzamos oidc hasta que el usuario complete
     *   el cambio de contraseña (para no romper el flujo core de cambio).
     *
     * @param stdClass $user     Usuario autenticado (por referencia).
     * @param string   $username Username usado en el login.
     * @param string   $password Contraseña tecleada (si aplica).
     * @return void
     */
    public function user_authenticated_hook(&$user, $username, $password) {
        if (empty($user) || empty($user->id)) {
            return;
        }

        if (!get_config('auth_mcc', 'enable_hotswitch')) {
            return;
        }

        if ($this->is_emergency_user($user)) {
            return;
        }

        if (!empty($user->forcepasswordchange)) {
            return;
        }

        $this->set_user_auth_to_oidc($user);
    }

    /**
     * Este plugin no debe provocar el marcado "not cached" por sí mismo.
     *
     * @return bool
     */
    public function prevent_local_passwords() {
        return false;
    }

    /**
     * El plugin no ofrece UI de cambio de contraseña.
     *
     * @return bool
     */
    public function can_change_password() {
        return false;
    }

    /**
     * Método requerido por la interfaz de auth_plugin_base (no operativo).
     *
     * @param stdClass $user
     * @param string   $newpassword
     * @return bool
     */
    public function user_update_password($user, $newpassword) {
        return true;
    }

    // ==========================================================
    // Helpers internos (privados)
    // ==========================================================

    /**
     * Obtiene la lista normalizada de usernames de emergencia (break-glass).
     *
     * @return string[] Usernames normalizados a minúsculas, sin duplicados.
     */
    private function get_emergency_usernames(): array {
        $list = [];

        $legacy = trim((string)get_config('auth_mcc', 'emergency_admin_username'));
        if ($legacy !== '') {
            $list[] = $legacy;
        }

        $raw = (string)get_config('auth_mcc', 'emergency_usernames');
        if ($raw !== '') {
            $raw = str_replace(["\r", ";"], ["\n", ","], $raw);
            $parts = preg_split('/[\n,]+/', $raw) ?: [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $list[] = $p;
                }
            }
        }

        $norm = [];
        foreach ($list as $u) {
            $u = trim($u);
            if ($u === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($u, 'UTF-8') : strtolower($u);
            $norm[$key] = true;
        }

        return array_keys($norm);
    }

    /**
     * Indica si un username pertenece al conjunto de usuarios de emergencia.
     *
     * @param string $username Username introducido o persistido.
     * @return bool
     */
    private function is_emergency_username(string $username): bool {
        $username = trim($username);
        if ($username === '') {
            return false;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
        return in_array($key, $this->get_emergency_usernames(), true);
    }

    /**
     * Wrapper para comprobar por objeto usuario Moodle.
     *
     * @param stdClass $user Usuario Moodle.
     * @return bool
     */
    private function is_emergency_user(\stdClass $user): bool {
        return !empty($user->username) && $this->is_emergency_username((string)$user->username);
    }

    /**
     * Determina si un username es E+NIF o E+NIE (acepta mayúsculas/minúsculas).
     *
     * @param string $username
     * @return bool
     */
    private function is_e_prefixed_nifnie(string $username): bool {
        $u = trim($username);
        if ($u === '') {
            return false;
        }
        $u = strtoupper($u);

        if (preg_match('/^E[0-9]{8}[A-Z]$/', $u)) {
            return true;
        }

        if (preg_match('/^E[XYZ][0-9]{7}[A-Z]$/', $u)) {
            return true;
        }

        return false;
    }

    /**
     * Elimina el prefijo "E" inicial (si existe) sin alterar el resto.
     *
     * @param string $username
     * @return string
     */
    private function strip_e_prefix(string $username): string {
        $u = trim($username);
        if ($u === '') {
            return '';
        }
        if (strtoupper($u[0]) === 'E') {
            return substr($u, 1);
        }
        return $u;
    }

    /**
     * Busca un usuario en mdl_user por username de forma case-insensitive.
     *
     * @param string $username
     * @return stdClass|null
     */
    private function find_user_by_username_ci(string $username): ?\stdClass {
        global $DB;

        $username = trim($username);
        if ($username === '') {
            return null;
        }

        return $DB->get_record_select(
            'user',
            'deleted = 0 AND LOWER(username) = LOWER(?)',
            [$username],
            '*',
            IGNORE_MISSING
        ) ?: null;
    }

    /**
     * Obtiene el HTML configurado para un tipo de error personalizado.
     *
     * Tipos soportados:
     * - clave
     * - ldap
     * - pwd
     *
     * @param string $type
     * @return string
     */
    private function get_custom_error_html(string $type): string {
        if ($type === 'clave') {
            return (string)get_config('auth_mcc', 'custom_error_clave_html');
        }

        if ($type === 'ldap') {
            return (string)get_config('auth_mcc', 'custom_error_ldap_html');
        }

        if ($type === 'pwd') {
            return (string)get_config('auth_mcc', 'custom_error_pwd_html');
        }

        return '';
    }

    /**
     * Redirige a la página intermedia propia del plugin para mostrar el mensaje configurable.
     *
     * @param string $type Tipo de mensaje: clave|ldap|pwd
     * @return void
     */
    private function redirect_to_custom_error_page(string $type): void {
        redirect(new \moodle_url('/auth/mcc/error.php', ['type' => $type]));
    }

    /**
     * Validación de credenciales directamente contra LDAP.
     *
     * @param string      $ldapusername Login a validar contra LDAP (p.ej. E+NIF/NIE).
     * @param string      $password     Password tecleada.
     * @param array|null  $attrs        (salida) atributos recuperados si se consigue.
     * @param string|null $error        (salida) texto de error interno para logs.
     * @return bool
     */
    private function ldap_bind_check(string $ldapusername, string $password, ?array &$attrs, ?string &$error): bool {
        global $CFG;

        $attrs = null;
        $error = null;

        try {
            $ldapauthfile = $CFG->dirroot . '/auth/ldap/auth.php';
            if (!file_exists($ldapauthfile)) {
                $error = 'auth_ldap_not_installed';
                return false;
            }

            require_once($ldapauthfile);

            $plug = get_auth_plugin('ldap');
            if (empty($plug) || !method_exists($plug, 'user_login')) {
                $error = 'auth_ldap_plugin_unavailable';
                return false;
            }

            $ok = (bool)$plug->user_login($ldapusername, $password);
            if (!$ok) {
                $error = 'ldap_invalid_credentials';
                return false;
            }

            if (method_exists($plug, 'get_userinfo')) {
                $attrs = (array)$plug->get_userinfo($ldapusername);
            } else {
                $attrs = [];
            }

            return true;
        } catch (\Throwable $e) {
            $error = 'ldap_exception: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Crea un usuario Moodle usando datos traídos del LDAP.
     *
     * @param string     $canonicalusername Username canónico NIF/NIE.
     * @param array|null $ldapattrs         Atributos recuperados de LDAP (pueden ser null).
     * @return stdClass
     */
    private function create_moodle_user_from_ldap(string $canonicalusername, ?array $ldapattrs): \stdClass {
        global $DB, $CFG;

        $canonicalusername = trim($canonicalusername);

        $firstname = 'Usuario';
        $lastname  = 'LDAP';
        $email     = 'no-reply@invalid.local';

        if (!empty($ldapattrs)) {
            $fn = $ldapattrs['firstname'] ?? $ldapattrs['givenname'] ?? $ldapattrs['given_name'] ?? null;
            $ln = $ldapattrs['lastname']  ?? $ldapattrs['sn'] ?? $ldapattrs['family_name'] ?? null;
            $em = $ldapattrs['email']     ?? $ldapattrs['mail'] ?? null;

            if (is_string($fn) && trim($fn) !== '') {
                $firstname = trim($fn);
            }
            if (is_string($ln) && trim($ln) !== '') {
                $lastname = trim($ln);
            }
            if (is_string($em) && trim($em) !== '') {
                $email = trim($em);
            }
        }

        $openid = trim((string)get_config('auth_mcc', 'openid_authname')) ?: 'oidc';

        $now = time();
        $user = (object)[
            'auth'         => $openid,
            'confirmed'    => 1,
            'deleted'      => 0,
            'suspended'    => 0,
            'mnethostid'   => $CFG->mnet_localhost_id,
            'username'     => $canonicalusername,
            'password'     => '',
            'firstname'    => $firstname,
            'lastname'     => $lastname,
            'email'        => $email,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];

        $user->id = $DB->insert_record('user', $user);

        return $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
    }

    /**
     * Fuerza el reposo del usuario con auth=oidc (sin tocar password).
     *
     * @param stdClass $user
     * @return void
     */
    private function set_user_auth_to_oidc(\stdClass $user): void {
        global $DB;

        $openid = trim((string)get_config('auth_mcc', 'openid_authname')) ?: 'oidc';
        $emergencylist = $this->get_emergency_usernames();

        if (empty($user->id)) {
            return;
        }
        if (!empty($user->deleted) || !empty($user->suspended)) {
            return;
        }
        if (!empty($user->username)) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($user->username, 'UTF-8') : strtolower($user->username);
            if (in_array($key, $emergencylist, true)) {
                return;
            }
        }

        if (!isset($user->auth) || $user->auth !== $openid) {
            $DB->set_field('user', 'auth', $openid, ['id' => $user->id]);
            $user->auth = $openid;
        }
    }

    /**
     * Redirige al formulario de login con un error concreto.
     *
     * @param string $errorcode Código/clave de error.
     * @return void
     */
    private function redirect_login_error(string $errorcode): void {
        $url = new \moodle_url('/login/index.php', ['error' => $errorcode]);
        redirect($url);
    }

    /**
     * Restaura el password local desde histórico si quedó "not cached" o vacío.
     *
     * @param stdClass $user
     * @return void
     */
    private function ensure_local_password_preserved(\stdClass $user): void {
        global $DB;

        if ($this->is_emergency_user($user)) {
            return;
        }

        $pwd = (string)($user->password ?? '');
        $valid = ($pwd !== '' && (str_starts_with($pwd, '$2y$') || str_starts_with($pwd, '$6$')));
        if ($valid) {
            return;
        }

        if (!$DB->get_manager()->table_exists('user_password_history')) {
            return;
        }

        $cols = $DB->get_columns('user_password_history');
        if (!is_array($cols) || empty($cols)) {
            return;
        }

        $hashcol = null;
        if (array_key_exists('passwordhash', $cols)) {
            $hashcol = 'passwordhash';
        } else if (array_key_exists('hash', $cols)) {
            $hashcol = 'hash';
        } else if (array_key_exists('password', $cols)) {
            $hashcol = 'password';
        }

        if ($hashcol === null) {
            return;
        }

        $sql = "SELECT {$hashcol}
                  FROM {user_password_history}
                 WHERE userid = :userid
              ORDER BY timecreated DESC";
        $rec = $DB->get_record_sql($sql, ['userid' => $user->id], IGNORE_MULTIPLE);

        if (!$rec) {
            return;
        }

        $last = (string)($rec->{$hashcol} ?? '');
        if ($last === '') {
            return;
        }

        $isok = (str_starts_with($last, '$2y$') || str_starts_with($last, '$6$'));
        if (!$isok) {
            return;
        }

        $DB->set_field('user', 'password', $last, ['id' => $user->id]);
    }

    /**
     * Método auxiliar para redirigir a la página intermedia del plugin con el mensaje de Cl@ve.
     *
     * Se puede invocar desde otros puntos del plugin si se detecta que un usuario ha sido
     * autenticado externamente por Cl@ve pero no existe en mdl_user.
     *
     * @return void
     */
    public function redirect_clave_user_not_found_custom(): void {
        $this->redirect_to_custom_error_page('clave');
    }
}
