# CHANGELOG – auth_mcc

Este documento recoge los cambios funcionales, técnicos y correctivos realizados en el plugin **auth_mcc**.

---

## Versión 1.0

**Fecha:** 2026-03-26

### Correcciones

- Establecimiento del versionado funcional del plugin en **1.0**.
- Establecimiento de la licencia del plugin en **GNU GPL v3** (sin sufijo "or later").
- Corrección de codename y ruta de instalación para mantener la convención oficial del plugin:
  - codename definitivo: **`auth_mcc`**
  - ruta de instalación definitiva: **`/auth/mcc`**
- Alineación técnica completa tras la corrección:
  - clase principal (`auth_plugin_mcc`), namespace (`auth_mcc`) y callbacks de observers
  - ajustes en `mdl_config_plugins` bajo `auth_mcc/*`
  - pantallas/rutas internas de error en `/auth/mcc/error.php`
  - ficheros de idioma renombrados a `lang/es/auth_mcc.php` y `lang/en/auth_mcc.php`

---

## Versión 2.6.0

**Fecha:** 2026-03-26

### Cambios principales

- Renombrado estructural del plugin:
  - nombre de proyecto a **Moodle Cl@ve Connect**
  - codename Moodle a **`auth_mcc`**
  - ruta de instalación a **`/auth/mcc`**
- Actualización técnica completa del componente para alinearse con el nuevo codename:
  - clase principal de autenticación, namespace de observers, callbacks de eventos y `component`
  - configuración en `mdl_config_plugins` bajo `auth_mcc/*`
  - rutas internas de la pantalla intermedia de errores (`/auth/mcc/error.php`)
  - ficheros de idioma renombrados a `lang/es/auth_mcc.php` y `lang/en/auth_mcc.php`
- Alineación de documentación funcional y técnica con la nueva nomenclatura del plugin.

---

## Versión 2.5.3

**Fecha:** 2026-03-25

### Correcciones

- Ajuste del flujo LDAP con credenciales incorrectas para preservar acceso por Cl@ve:
  - en `auth.php`, cuando LDAP devuelve `ldap_invalid_credentials`, el plugin intenta localizar
    el usuario canónico (`NIF/NIE`) y restablecer su reposo `auth=oidc` antes de redirigir a
    `/auth/mcc/error.php?type=pwd`.
  - con este ajuste, un fallo de contraseña en LDAP no deja al usuario existente fuera del estado
    por defecto requerido para acceso por Cl@ve.

---

## Versión 2.5.2

**Fecha:** 2026-03-25

### Cambios principales

- Separación del manejo de errores LDAP en el flujo `E+NIF/NIE`:
  - en `auth.php`, cuando LDAP devuelve `ldap_invalid_credentials`, el plugin redirige ahora a
    `/auth/mcc/error.php?type=pwd`.
  - los errores LDAP técnicos, de disponibilidad o de backend no válidos siguen redirigiendo a
    `/auth/mcc/error.php?type=ldap`.
- En `error.php`, se añade soporte explícito para `type=pwd`, mostrando el contenido de
  `custom_error_pwd_html`.
- Alineación de cadenas de idioma y documentación para reflejar el nuevo mensaje específico de
  credenciales LDAP incorrectas.

---

## Versión 2.5.1

**Fecha:** 2026-03-19

### Correcciones

- Corrección del secuestro de errores en `/login/index.php` que podía desviar flujos LDAP al mensaje personalizado de Cl@ve:
  - en `auth.php`, `loginpage_hook()` deja de interceptar de forma global los parámetros
    `error=authpreventaccountcreation` y `error=errorauthloginfailednouser`.
  - con este ajuste, el flujo LDAP-first vuelve a completar correctamente la autocreación en `mdl_user`
    cuando LDAP valida y el usuario no existe.
- Se mantiene la gestión del caso Cl@ve/OIDC "usuario no existente" en `classes/observer.php`,
  limitada al callback real de OIDC para evitar falsos positivos.

---

## Versión 2.5.0

**Fecha:** 2026-03-18

### Cambios principales

- Mejora del flujo Cl@ve/OIDC ante discrepancias de mayúsculas/minúsculas en `username`:
  - en `classes/observer.php`, cuando se detecta `AUTH_LOGIN_NOUSER` en callback real OIDC,
    el plugin intenta una conciliación previa en `mdl_user` usando comparación:
    `UPPER(username) = UPPER(?)`.
  - si la conciliación devuelve un único usuario elegible, completa el login sin mostrar error.
  - si no hay coincidencia (o existe ambigüedad), mantiene el comportamiento de fallback:
    redirección a `/auth/mcc/error.php?type=clave`.
- Se mantiene el modelo canónico de identidad en Moodle con `username` en NIF/NIE (mayúsculas).
- Se mantiene la exclusión de usuarios de emergencia en este flujo de conciliación.

---

## Versión 2.4.4

**Fecha:** 2026-03-17

### Correcciones

- Ajuste del detector de error Cl@ve/OIDC en `classes/observer.php` para evitar disparo prematuro:
  - la redirección a `/auth/mcc/error.php?type=clave` se aplica solo en callback real de OIDC
    (retorno con parámetros `code`+`state`, `id_token` o `error`).
  - se evita mostrar el mensaje personalizado al iniciar el flujo (click inicial en botón Cl@ve) antes de autenticación.
- Limpieza de helper sin uso para mantener código sin bloques muertos.

---

## Versión 2.4.3

**Fecha:** 2026-03-17

### Correcciones

- Corrección del flujo de error en accesos Cl@ve/OIDC con usuario inexistente en `mdl_user`:
  - ahora `on_user_login_failed` en `classes/observer.php` detecta el caso `AUTH_LOGIN_NOUSER` en contexto OIDC
  - redirige de forma inmediata a `/auth/mcc/error.php?type=clave` para mostrar el HTML configurable del plugin
  - evita mostrar el mensaje estándar de `auth_oidc` en ese escenario.
- Limpieza defensiva de la intención de sesión `auth_mcc_intent` tras éxito/fallo de autenticación para evitar persistencia entre intentos.

---

## Versión 2.4.2

**Fecha:** 2026-03-17

### Correcciones

- Corrección de compatibilidad en `mdl_user_password_history` para soportar de forma homogénea las columnas `passwordhash`, `hash` y `password` en:
  - `auth.php`
  - `classes/observer.php`
  - `reseed_password_history.php`
  - `seed_missing_password_history.php`
- Corrección del sembrado de histórico faltante para excluir explícitamente a los usuarios de emergencia (`break-glass`) en `seed_missing_password_history.php`.
- Alineación de cadenas de mantenimiento asociadas al resembrado para reflejar los nombres de columna realmente soportados.

---

## Versión 2.4.1

**Fecha:** 2026-02-25

### Cambios principales

- Incorporación de **usuarios de emergencia (break-glass)** configurables desde la administración del plugin.
- Soporte para múltiples usernames de emergencia mediante configuración:
  - `emergency_usernames`
  - compatibilidad con `emergency_admin_username` (legacy)
- Exclusión completa de usuarios de emergencia del flujo automático del plugin:
  - no se normaliza su username
  - no se fuerza `auth = oidc`
  - no se ejecuta lógica LDAP-first
  - no se altera su password ni el histórico por automatismos del plugin
- Incorporación de **mensajes HTML personalizados configurables** para errores mostrados en la pantalla de login:
  - error de acceso por **Cl@ve** cuando el usuario autenticado externamente no existe en `mdl_user`
  - error de acceso por **LDAP**, tanto por credenciales inválidas como por fallo técnico del servicio
- Los mensajes personalizados se gestionan desde la configuración del plugin sin depender del contenido de los ficheros idiomáticos.
- Actualización del flujo de `loginpage_hook()` para:
  - interceptar errores estándar de Moodle/OIDC
  - sustituirlos por mensajes HTML configurables
  - mostrar dichos mensajes exclusivamente en la pantalla de login
- Ajuste y consolidación de `observer.php` para mantener compatibilidad con:
  - reposo `auth = oidc`
  - usuarios de emergencia
  - normalización de usuarios creados por LDAP
  - gestión de histórico de contraseñas

### Correcciones

- Corrección del problema de duplicidad de página de administración causado por inserción manual de `settings.php` en el árbol de administración.
- Corrección de permisos conceptuales del flujo de errores para que el plugin gestione mensajes personalizados desde sesión y no desde strings estándar de idioma.
- Ajuste documental y funcional del flujo de autenticación para reflejar:
  - Cl@ve
  - LDAP
  - Manual
  - Usuarios de emergencia

---

## Versión 2.2.0

**Fecha:** 2026-01-29

### Cambios principales

- Reposo global: tras cualquier login correcto (Cl@ve/OIDC, Manual o LDAP) el usuario queda con `auth = oidc`.
- Alineación del flujo de formulario:
  - Si el login es `E+NIF/NIE` (case-insensitive), se autentica primero contra **LDAP** y, si valida, se transforma a `NIF/NIE` para operaciones Moodle.
  - Búsqueda de usuario Moodle case-insensitive.
  - Si el usuario no existe tras LDAP válido, se crea con `username=NIF/NIE` y `auth=oidc`.
- Normalización de usuarios autocreados por LDAP: `E+NIF/NIE` → `NIF/NIE`, evitando colisiones.
- Histórico de contraseñas robusto:
  - Se soportan hashes `$2y$...` y `$6$...`.
  - Inserción/actualización por observers (`user_created`, `user_password_updated`, `user_updated`).
  - Restauración segura del hash desde histórico si `password` está vacío o `not cached`.
  - Compatibilidad con instalaciones donde la columna del hash puede llamarse `hash` o `passwordhash`.
- Respeto de `forcepasswordchange`: no se fuerza OIDC durante el paso de cambio de contraseña obligatorio.

### Correcciones

- Eliminación de actuaciones sobre el evento `user_login_failed` orientadas a “intención Cl@ve” (Cl@ve no usa el formulario).
- Corrección de errores por lectura de metadatos de columnas (p. ej. `database_column_info`).

---

## Versión 1.4.1

**Fecha:** 2025-11-13

- Normalización automática de usernames creados con prefijo `E`.
- Respeto de `forcepasswordchange` en el flujo de login manual.
- Inserción del hash en `mdl_user_password_history` cuando falten registros.

---

## Versión 1.4.0

**Fecha:** 2025-11-06

- Documentación ampliada e instalación revisada.
- Mejoras de estabilidad en flujo Cl@ve/Keycloak.
