<?php
namespace auth_mcc;

defined('MOODLE_INTERNAL') || die();

/**
 * Observers del plugin auth_mcc.
 *
 * Responsabilidades principales:
 *  1) Post-login correcto (cualquier método: manual/ldap/oidc): asegurar reposo auth=oidc,
 *     EXCEPTO si forcepasswordchange=1 o si el usuario es de emergencia (break-glass).
 *  2) Autocreación por LDAP u otros: normalizar usernames del tipo E+NIF/NIE -> NIF/NIE
 *     cuando proceda, evitando duplicidades.
 *  3) Mantener el histórico de contraseñas (mdl_user_password_history):
 *     - Insertar hash actual cuando procede (creación, cambio de password, user_updated).
 *     - Restaurar mdl_user.password desde histórico si quedó vacío / 'not cached' y existe histórico.
 *
 * Notas operativas:
 * - Cl@ve/OIDC no usa el formulario de login clásico.
 * - Para el caso OIDC "usuario no existente" (AUTH_LOGIN_NOUSER), este observer intenta
 *   primero una conciliación case-insensitive con UPPER(username) en mdl_user.
 *   Si no hay coincidencia, redirige a /auth/mcc/error.php?type=clave para
 *   mostrar el mensaje HTML configurable.
 * - Los errores LDAP y los errores de login clásico siguen gestionándose en auth.php.
 * - Los usuarios de emergencia están exentos del flujo del plugin: deben permanecer auth=manual y con
 *   password real (hash), sin conmutaciones ni restauraciones automáticas por parte del plugin.
 */
class observer {

    // =====================================================================
    // EVENTOS
    // =====================================================================

    /**
     * Gestiona fallos de login y redirige a error personalizado en el escenario OIDC sin usuario local.
     *
     * Contexto de uso:
     * - El flujo Cl@ve/OIDC se ejecuta en /auth/oidc/ y puede fallar antes de pasar por /login/index.php.
     * - En ese escenario, cuando Moodle/OIDC dispara user_login_failed con motivo AUTH_LOGIN_NOUSER,
     *   debe mostrarse el mensaje HTML configurable del plugin para Cl@ve.
     *
     * Comportamiento:
     * - Si el fallo corresponde a contexto OIDC y motivo "usuario no existente", intenta:
     *   1) Conciliar usuario por username con comparación UPPER(...) en mdl_user.
     *   2) Si encuentra un único usuario válido y no de emergencia, completa login y redirige.
     *   3) Si no encuentra conciliación, redirige a /auth/mcc/error.php?type=clave.
     * - En cualquier otro caso, no altera el flujo estándar.
     * - Limpia la intención de sesión de Cl@ve para evitar persistencia accidental entre peticiones.
     *
     * Reglas de negocio:
     * - Usuarios de emergencia: nunca se fuerzan a flujos automáticos ni a pantallas intermedias.
     *
     * Efectos secundarios:
     * - Puede emitir redirección HTTP inmediata y finalizar la petición.
     * - Puede modificar $SESSION eliminando la marca auth_mcc_intent.
     *
     * Nota de seguridad:
     * - No expone detalles técnicos del fallo al usuario final; delega el contenido en el HTML
     *   administrado y saneado por error.php.
     *
     * @param \core\event\user_login_failed $event Evento de login fallido.
     * @return void
     */
    public static function on_user_login_failed(\core\event\user_login_failed $event): void {
        if (!self::shouldRedirectClaveNoUserFailure($event)) {
            if (self::isOidcCallbackContext()) {
                self::clearClaveIntentFromSession();
            }
            return;
        }

        if (self::tryCompleteOidcLoginUsingUppercaseUsernameMatch($event)) {
            return;
        }

        self::clearClaveIntentFromSession();
        redirect(new \moodle_url('/auth/mcc/error.php', ['type' => 'clave']));
    }

    /**
     * Intenta resolver un fallo OIDC AUTH_LOGIN_NOUSER conciliando username con comparación UPPER(...).
     *
     * Contexto de uso:
     * - Se invoca únicamente desde on_user_login_failed() cuando el flujo OIDC ya fue validado
     *   externamente pero no se encontró coincidencia directa de usuario en Moodle.
     *
     * Comportamiento:
     * - Extrae el username reportado por el evento.
     * - Busca en mdl_user con una comparación case-insensitive basada en UPPER(username)=UPPER(?).
     * - Si la conciliación devuelve un único usuario elegible (no borrado, no suspendido y no de emergencia),
     *   completa el login y redirige al wantsurl o a la raíz del sitio.
     * - Si no hay coincidencia (o hay ambigüedad), no altera el flujo y devuelve false.
     *
     * Reglas de negocio:
     * - Mantener username canónico en mayúsculas no debe impedir el acceso por Cl@ve cuando el IdP
     *   entrega el claim en minúsculas.
     * - Los usuarios de emergencia quedan excluidos de automatismos.
     *
     * Efectos secundarios:
     * - Puede iniciar sesión de usuario (complete_user_login()).
     * - Puede consumir y limpiar $SESSION->wantsurl.
     * - Puede emitir redirección HTTP inmediata y finalizar la petición.
     *
     * Nota de seguridad:
     * - No crea usuarios nuevos ni expone detalles técnicos en interfaz.
     * - Ante coincidencias ambiguas, aborta la conciliación para evitar autenticaciones erróneas.
     *
     * @param \core\event\user_login_failed $event Evento de login fallido.
     * @return bool True si se completó login y se emitió redirección; false si no hubo conciliación.
     */
    private static function tryCompleteOidcLoginUsingUppercaseUsernameMatch(\core\event\user_login_failed $event): bool {
        global $CFG, $SESSION;

        $eventUsername = trim((string)($event->other['username'] ?? ''));
        if ($eventUsername === '') {
            return false;
        }

        $candidateUser = self::findUserByUsernameUsingUppercaseComparison($eventUsername);
        if (!$candidateUser) {
            return false;
        }

        if (!empty($candidateUser->deleted) || !empty($candidateUser->suspended)) {
            return false;
        }

        if (self::is_emergency_user($candidateUser)) {
            return false;
        }

        self::clearClaveIntentFromSession();
        complete_user_login($candidateUser);

        if (!empty($SESSION->wantsurl)) {
            $targetUrl = $SESSION->wantsurl;
            unset($SESSION->wantsurl);
            redirect($targetUrl);
        }

        redirect($CFG->wwwroot);
        return true;
    }

    /**
     * Garantiza consistencia post-login tras autenticación correcta en cualquier método.
     *
     * Contexto de uso:
     * - Se invoca mediante el evento core\event\user_loggedin después de un login válido
     *   (manual, LDAP, OIDC u otro método habilitado en el sitio).
     * - Forma parte de la política de reposo del plugin, que exige dejar auth en OIDC
     *   salvo excepciones operativas explícitas.
     *
     * Comportamiento:
     * - Limpia la intención de sesión auth_mcc_intent para evitar contaminación de estado
     *   entre intentos de autenticación.
     * - Si el plugin está desactivado (enable_hotswitch=0), no interviene.
     * - Carga al usuario por id y aborta si no existe, está borrado o suspendido.
     * - Excluye usuarios de emergencia (break-glass).
     * - Si forcepasswordchange=1, no conmuta auth y sólo intenta preservar/restaurar hash local.
     * - En el resto de casos, fuerza el reposo auth=oidc (o el método configurado en openid_authname)
     *   y asegura preservación/restauración de password local desde histórico cuando procede.
     *
     * Reglas de negocio:
     * - Usuarios de emergencia nunca deben ser conmutados automáticamente.
     * - Debe respetarse el flujo nativo de cambio obligatorio de contraseña de Moodle.
     *
     * Efectos secundarios:
     * - Puede actualizar mdl_user.auth.
     * - Puede actualizar mdl_user.password al restaurar un hash válido desde histórico.
     * - Puede eliminar la marca auth_mcc_intent de $SESSION.
     *
     * Nota de seguridad:
     * - No expone información sensible en salida al usuario final.
     * - Solo restaura hashes con prefijos admitidos por la política del plugin ($2y$ / $6$).
     *
     * @param \core\event\user_loggedin $event Evento de login correcto.
     * @return void
     */
    public static function on_user_loggedin(\core\event\user_loggedin $event): void {
        global $DB;

        self::clearClaveIntentFromSession();

        $enabled = (bool)get_config('auth_mcc', 'enable_hotswitch');
        if (!$enabled) {
            return;
        }

        $userid = (int)($event->relateduserid ?: $event->userid);
        if ($userid <= 0) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
        if (!$user) {
            return;
        }

        if (!empty($user->deleted) || !empty($user->suspended)) {
            return;
        }

        // Usuarios de emergencia (break-glass): no intervenir.
        if (self::is_emergency_user($user)) {
            return;
        }

        // Si debe cambiar contraseña, NO conmutamos auth: Moodle debe forzar el flujo core.
        if (!empty($user->forcepasswordchange)) {
            // Aun así, intentamos preservar/restaurar hash local si quedó "not cached" para evitar bloqueos.
            self::ensure_local_password_preserved((int)$user->id);
            return;
        }

        // Asegurar reposo auth=oidc sin tocar password.
        $openid = trim((string)get_config('auth_mcc', 'openid_authname')) ?: 'oidc';
        if ((string)$user->auth !== $openid) {
            $DB->set_field('user', 'auth', $openid, ['id' => (int)$user->id]);
        }

        // Si hubiese quedado "not cached" por acciones externas y existe histórico, restaurarlo.
        self::ensure_local_password_preserved((int)$user->id);
    }

    /**
     * user_created: almacenar hash en histórico si procede y normalizar username si fue creado como E+NIF/NIE.
     *
     * Escenario típico:
     * - LDAP con autocreación puede crear usernames como E+NIF/NIE.
     * - Este observer intenta normalizar a NIF/NIE para que el identificador canónico en Moodle sea NIF/NIE.
     *
     * Importante:
     * - Si la normalización provocaría un duplicado (ya existe NIF/NIE), NO se modifica el registro creado
     *   para evitar colisiones en producción. Se deja trazabilidad en logs PHP.
     * - Usuarios de emergencia: excluidos.
     *
     * @param \core\event\user_created $event
     * @return void
     */
    public static function on_user_created_store_password_history(\core\event\user_created $event): void {
        global $DB;

        $userid = (int)$event->objectid;
        if ($userid <= 0) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
        if (!$user) {
            return;
        }

        if (!empty($user->deleted)) {
            return;
        }

        if (self::is_emergency_user($user)) {
            return;
        }

        // 1) Normalizar username E+NIF/NIE -> NIF/NIE si procede.
        self::normalize_username_if_prefixed_with_e((int)$user->id);

        // 2) Guardar password actual en histórico si aplica (hash real, no "not cached").
        self::store_current_password_in_history((int)$user->id);
    }

    /**
     * user_password_updated: insertar el nuevo hash en el histórico.
     *
     * @param \core\event\user_password_updated $event
     * @return void
     */
    public static function on_user_password_updated_store_password_history(\core\event\user_password_updated $event): void {
        $userid = (int)$event->relateduserid;
        if ($userid <= 0) {
            return;
        }
        self::store_current_password_in_history($userid);
    }

    /**
     * Evalúa si un fallo de login corresponde al caso funcional Cl@ve/OIDC sin usuario local.
     *
     * Contexto de uso:
     * - Se invoca desde on_user_login_failed() para decidir si debe sustituirse el mensaje
     *   estándar de auth_oidc por la pantalla intermedia controlada por este plugin.
     *
     * Comportamiento:
     * - Verifica que el motivo del evento sea AUTH_LOGIN_NOUSER.
     * - Verifica que la petición corresponda a un callback real de OIDC tras volver del IdP.
     * - Si el username reportado pertenece a un usuario de emergencia, devuelve false.
     *
     * Reglas de negocio:
     * - Solo el escenario "usuario no existente en Moodle" de Cl@ve/OIDC debe derivar
     *   al mensaje personalizado type=clave.
     *
     * Efectos secundarios:
     * - Ninguno; es un método puro de evaluación.
     *
     * Nota de seguridad:
     * - Limita la intervención a un caso concreto para evitar redirecciones indebidas
     *   en fallos de autenticación de otros métodos.
     *
     * @param \core\event\user_login_failed $event Evento de login fallido.
     * @return bool True si debe redirigirse a error.php?type=clave; false en caso contrario.
     */
    private static function shouldRedirectClaveNoUserFailure(\core\event\user_login_failed $event): bool {
        $reason = $event->other['reason'] ?? null;
        $isNoUserReason = false;
        if (defined('AUTH_LOGIN_NOUSER')) {
            $isNoUserReason = ((string)$reason === (string)AUTH_LOGIN_NOUSER);
        }

        if (!$isNoUserReason) {
            return false;
        }

        if (!self::isOidcCallbackContext()) {
            return false;
        }

        $username = trim((string)($event->other['username'] ?? ''));
        if ($username !== '' && self::is_emergency_username($username)) {
            return false;
        }

        return true;
    }

    /**
     * Determina si la petición actual es el callback de retorno de OIDC.
     *
     * Contexto de uso:
     * - Se utiliza para evitar redirecciones prematuras durante el inicio del flujo OIDC
     *   (cuando todavía no se ha autenticado en Cl@ve).
     *
     * Comportamiento:
     * - Exige estar en contexto /auth/oidc/.
     * - Considera callback válido cuando hay parámetros típicos de retorno del IdP:
     *   - code + state (flujo authorization code)
     *   - id_token (flujos que devuelven token directamente)
     *   - error (retorno de error desde el IdP)
     *
     * Efectos secundarios:
     * - Ninguno.
     *
     * Nota de seguridad:
     * - Restringe la lógica de error personalizado al tramo de retorno autenticado,
     *   reduciendo falsos positivos en pasos previos del login.
     *
     * @return bool True si la petición actual parece callback OIDC; false en caso contrario.
     */
    private static function isOidcCallbackContext(): bool {
        if (!self::isOidcRequestContext()) {
            return false;
        }

        $code = trim((string)optional_param('code', '', PARAM_RAW_TRIMMED));
        $state = trim((string)optional_param('state', '', PARAM_RAW_TRIMMED));
        $idtoken = trim((string)optional_param('id_token', '', PARAM_RAW_TRIMMED));
        $error = trim((string)optional_param('error', '', PARAM_RAW_TRIMMED));

        $hasAuthCodeCallback = ($code !== '' && $state !== '');
        $hasTokenCallback = ($idtoken !== '');
        $hasErrorCallback = ($error !== '');

        return ($hasAuthCodeCallback || $hasTokenCallback || $hasErrorCallback);
    }

    /**
     * Determina si la petición actual está ejecutándose en el endpoint del flujo OIDC.
     *
     * Contexto de uso:
     * - Se utiliza como señal de contexto para discriminar fallos ocurridos en /auth/oidc/
     *   frente a fallos de login clásico en /login/index.php.
     *
     * Comportamiento:
     * - Inspecciona qualified_me() y devuelve true si contiene '/auth/oidc/'.
     *
     * Efectos secundarios:
     * - Ninguno.
     *
     * Nota de seguridad:
     * - Se usa como criterio auxiliar, nunca como criterio único de autorización.
     *
     * @return bool True si la petición en curso está en flujo OIDC.
     */
    private static function isOidcRequestContext(): bool {
        $currenturl = (string)qualified_me();
        return (strpos($currenturl, '/auth/oidc/') !== false);
    }

    /**
     * Elimina la marca de intención Cl@ve de la sesión actual.
     *
     * Contexto de uso:
     * - Se invoca tanto en éxito como en fallo para evitar arrastre de estado entre
     *   peticiones de autenticación no relacionadas.
     *
     * Comportamiento:
     * - Si existe $SESSION->auth_mcc_intent, la elimina mediante unset().
     * - Si no existe, no realiza cambios.
     *
     * Efectos secundarios:
     * - Modifica el estado de sesión del usuario.
     *
     * Nota de seguridad:
     * - Reduce riesgo de aplicar lógica de Cl@ve fuera de su contexto real.
     *
     * @return void
     */
    private static function clearClaveIntentFromSession(): void {
        global $SESSION;

        if (!empty($SESSION->auth_mcc_intent)) {
            unset($SESSION->auth_mcc_intent);
        }
    }

    /**
     * user_updated: si se detecta cambio relevante, almacenar hash en histórico.
     *
     * @param \core\event\user_updated $event
     * @return void
     */
    public static function on_user_updated_maybe_store_password_history(\core\event\user_updated $event): void {
        $userid = (int)$event->objectid;
        if ($userid <= 0) {
            return;
        }
        self::store_current_password_in_history($userid);
    }

    // =====================================================================
    // HELPERS: EMERGENCIA (break-glass)
    // =====================================================================

    /**
     * Obtiene la lista normalizada de usernames de emergencia (break-glass).
     *
     * Fuentes de configuración:
     * - auth_mcc/emergency_usernames (lista; uno por línea o CSV).
     * - auth_mcc/emergency_admin_username (legacy; un único username).
     *
     * @return string[] usernames normalizados a minúsculas, sin duplicados.
     */
    private static function get_emergency_usernames(): array {
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
     * @param string $username
     * @return bool
     */
    private static function is_emergency_username(string $username): bool {
        $username = trim($username);
        if ($username === '') {
            return false;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
        return in_array($key, self::get_emergency_usernames(), true);
    }

    /**
     * Wrapper por objeto usuario Moodle.
     *
     * @param \stdClass $user
     * @return bool
     */
    private static function is_emergency_user(\stdClass $user): bool {
        return !empty($user->username) && self::is_emergency_username((string)$user->username);
    }

    // =====================================================================
    // HELPERS: BÚSQUEDA / NORMALIZACIÓN / PASSWORD HISTORY
    // =====================================================================

    /**
     * Busca un usuario en Moodle por username de forma case-insensitive.
     *
     * @param string $username
     * @return \stdClass|null
     */
    private static function find_user_ci(string $username): ?\stdClass {
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
     * Busca un usuario en Moodle por username usando comparación case-insensitive con UPPER(...).
     *
     * Contexto de uso:
     * - Conciliación del flujo Cl@ve/OIDC cuando el claim de username puede venir en minúsculas
     *   y el identificador canónico en Moodle se conserva en mayúsculas.
     *
     * Comportamiento:
     * - Ejecuta búsqueda en mdl_user con condición:
     *   deleted = 0 AND UPPER(username) = UPPER(?)
     * - Limita resultados a dos registros para detectar ambigüedad.
     * - Devuelve usuario solo cuando existe una coincidencia única.
     *
     * Reglas de negocio:
     * - Ante múltiples coincidencias case-insensitive, no se devuelve usuario para evitar
     *   autenticaciones no deterministas.
     *
     * Efectos secundarios:
     * - Puede escribir una traza en logs PHP cuando detecta ambigüedad.
     *
     * Nota de seguridad:
     * - Usa placeholders de Moodle, sin interpolación directa de entrada externa.
     *
     * @param string $username Username recibido desde flujo OIDC.
     * @return \stdClass|null Usuario único encontrado o null si no hay coincidencia unívoca.
     */
    private static function findUserByUsernameUsingUppercaseComparison(string $username): ?\stdClass {
        global $DB;

        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $matches = $DB->get_records_select(
            'user',
            'deleted = 0 AND UPPER(username) = UPPER(?)',
            [$username],
            'id ASC',
            '*',
            0,
            2
        );

        if (empty($matches)) {
            return null;
        }

        if (count($matches) > 1) {
            error_log(
                "[auth_mcc] findUserByUsernameUsingUppercaseComparison: coincidencia ambigua para username={$username}"
            );
            return null;
        }

        $match = reset($matches);
        return ($match instanceof \stdClass) ? $match : null;
    }

    /**
     * Normaliza el username si está prefijado con "E" y cumple patrón E+NIF/NIE.
     *
     * Reglas:
     * - Si username = E+NIF/NIE (case-insensitive), el objetivo canónico es NIF/NIE (sin E).
     * - Si ya existe un usuario con username canónico, NO se modifica para evitar colisión; se deja traza.
     * - Si no existe, se actualiza username (normalizado a MAYÚSCULAS) y se fija auth=oidc.
     * - Usuarios de emergencia: excluidos.
     *
     * @param int $userid
     * @return void
     */
    private static function normalize_username_if_prefixed_with_e(int $userid): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
        if (!$user || !empty($user->deleted)) {
            return;
        }

        if (self::is_emergency_user($user)) {
            return;
        }

        $u = trim((string)$user->username);
        if ($u === '') {
            return;
        }

        $up = strtoupper($u);

        // E + NIF: E + 8 dígitos + letra.
        $isEnif = (bool)preg_match('/^E[0-9]{8}[A-Z]$/', $up);
        // E + NIE: E + (X|Y|Z) + 7 dígitos + letra.
        $isEnie = (bool)preg_match('/^E[XYZ][0-9]{7}[A-Z]$/', $up);

        if (!$isEnif && !$isEnie) {
            return;
        }

        $canonical = substr($up, 1); // quitar la E

        // ¿Existe ya un usuario con el canónico? -> no tocar para evitar duplicidades.
        $existing = self::find_user_ci($canonical);
        if ($existing && (int)$existing->id !== (int)$user->id) {
            error_log(
                "[auth_mcc] normalize_username_if_prefixed_with_e: duplicado detectado. " .
                "userid={$user->id}, username={$user->username}, canonical={$canonical}, existingid={$existing->id}"
            );
            return;
        }

        // Actualizar username a NIF/NIE canónico y fijar auth=oidc.
        $openid = trim((string)get_config('auth_mcc', 'openid_authname')) ?: 'oidc';

        $DB->set_field('user', 'username', $canonical, ['id' => (int)$user->id]);

        if ((string)$user->auth !== $openid) {
            $DB->set_field('user', 'auth', $openid, ['id' => (int)$user->id]);
        }
    }

    /**
     * Restaura mdl_user.password desde el último hash del histórico si:
     * - password actual está vacío o es 'not cached'
     * - y existe un hash válido en mdl_user_password_history.
     *
     * Usuarios de emergencia: excluidos.
     *
     * @param int $userid
     * @return void
     */
    private static function ensure_local_password_preserved(int $userid): void {
        global $DB;

        if ($userid <= 0) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
        if (!$user || !empty($user->deleted)) {
            return;
        }

        if (self::is_emergency_user($user)) {
            return;
        }

        // Si ya hay hash real, no tocar.
        $pwd = (string)($user->password ?? '');
        $hasrealhash = ($pwd !== '' && (strpos($pwd, '$2y$') === 0 || strpos($pwd, '$6$') === 0));
        if ($hasrealhash) {
            return;
        }

        if (!$DB->get_manager()->table_exists('user_password_history')) {
            return;
        }

        // Detectar campo hash de forma robusta (passwordhash/hash/password).
        $cols = $DB->get_columns('user_password_history');
        if (!is_array($cols) || empty($cols)) {
            return;
        }

        $hashfield = null;
        if (array_key_exists('passwordhash', $cols)) {
            $hashfield = 'passwordhash';
        } else if (array_key_exists('hash', $cols)) {
            $hashfield = 'hash';
        } else if (array_key_exists('password', $cols)) {
            $hashfield = 'password';
        }

        if ($hashfield === null) {
            return;
        }

        $sql = "SELECT {$hashfield} AS h
                  FROM {user_password_history}
                 WHERE userid = :uid
              ORDER BY timecreated DESC";
        $last = $DB->get_record_sql($sql, ['uid' => $userid], IGNORE_MULTIPLE);

        if (!$last || empty($last->h)) {
            return;
        }

        $lastHash = (string)$last->h;
        $isok = (strpos($lastHash, '$2y$') === 0 || strpos($lastHash, '$6$') === 0);
        if (!$isok) {
            return;
        }

        $DB->set_field('user', 'password', $lastHash, ['id' => $userid]);
    }

    /**
     * Inserta el hash actual de mdl_user.password en mdl_user_password_history si procede.
     *
     * Reglas:
     * - Solo inserta si existe hash real (prefijos $2y$... o $6$...).
     * - No inserta si password está vacío o es 'not cached'.
     * - Evita duplicados (mismo hash ya presente como último registro).
     * - Usuarios de emergencia: excluidos.
     *
     * @param int $userid
     * @return void
     */
    private static function store_current_password_in_history(int $userid): void {
        global $DB;

        if ($userid <= 0) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
        if (!$user || !empty($user->deleted)) {
            return;
        }

        if (self::is_emergency_user($user)) {
            return;
        }

        if (!$DB->get_manager()->table_exists('user_password_history')) {
            return;
        }

        $pwd = (string)($user->password ?? '');
        if ($pwd === '' || $pwd === 'not cached') {
            return;
        }

        // Aceptar hash tipo bcrypt ($2y$) o sha512crypt ($6$) según el entorno.
        $isok = (strpos($pwd, '$2y$') === 0 || strpos($pwd, '$6$') === 0);
        if (!$isok) {
            return;
        }

        // Detectar columna hash (passwordhash/hash/password) de forma robusta.
        $cols = $DB->get_columns('user_password_history');
        if (!is_array($cols) || empty($cols)) {
            return;
        }

        $hashfield = null;
        if (array_key_exists('passwordhash', $cols)) {
            $hashfield = 'passwordhash';
        } else if (array_key_exists('hash', $cols)) {
            $hashfield = 'hash';
        } else if (array_key_exists('password', $cols)) {
            $hashfield = 'password';
        }

        if ($hashfield === null) {
            return;
        }

        // Evitar insertar duplicado si el último hash coincide con el actual.
        $sql = "SELECT {$hashfield} AS h
                  FROM {user_password_history}
                 WHERE userid = :uid
              ORDER BY timecreated DESC";
        $last = $DB->get_record_sql($sql, ['uid' => $userid], IGNORE_MISSING);

        if ($last && !empty($last->h) && hash_equals((string)$last->h, $pwd)) {
            return;
        }

        $record = (object)[
            'userid'      => $userid,
            $hashfield    => $pwd,
            'timecreated' => time(),
        ];

        $DB->insert_record('user_password_history', $record);
    }
}
