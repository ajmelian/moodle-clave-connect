# SECURITY.md – Directrices de Seguridad para el Plugin auth_mcc

Este documento describe las políticas, controles y requisitos de seguridad necesarios para el correcto funcionamiento del plugin **auth_mcc** en un entorno:

Moodle + Cl@ve + Keycloak (OIDC) + LDAP.

---

# 0. Contacto de seguridad

Canal oficial para incidencias de seguridad y soporte:

```
hola@ajmelian.info
```

Política de notificación:

* No publicar vulnerabilidades sin coordinación previa.
* Incluir pasos de reproducción, impacto y versión afectada.
* Se recomienda aportar propuesta de mitigación cuando sea posible.

---

# 1. Alcance

Aplica a:

* Plugin **auth_mcc**
* Integración OIDC (Keycloak) utilizada por Cl@ve mediante **auth_oidc**
* Integración LDAP (**auth_ldap**) para acceso por formulario
* Tablas sensibles:

```
mdl_user
mdl_user_password_history
mdl_config_plugins
```

---

# 2. Principios obligatorios

## 2.1 Identificador único (username): NIF/NIE

El identificador canónico del usuario en Moodle debe ser:

```
NIF/NIE
```

El formulario permite que el usuario introduzca:

```
E+NIF/NIE
```

pero el sistema debe converger siempre a:

```
NIF/NIE
```

El plugin normaliza automáticamente:

```
E12345678Z → 12345678Z
```

No se admiten identificadores alternativos como:

* email
* alias
* identificadores internos.

---

## 2.2 Reposo del método de autenticación

Tras cualquier login correcto:

* Cl@ve / OIDC
* LDAP
* Manual

el usuario debe quedar con:

```
mdl_user.auth = oidc
```

Excepción:

```
forcepasswordchange = 1
```

Durante el cambio obligatorio de contraseña el plugin no modifica el método de autenticación para no romper el flujo core de Moodle.

---

## 2.3 Prohibido `password` vacío o “not cached”

No se deben permitir estados:

```
password = ''
password = 'not cached'
```

Estos estados pueden:

* bloquear autenticaciones internas
* provocar errores en el flujo de login
* impedir cambios de contraseña.

---

## 2.4 Histórico de contraseñas obligatorio

Debe existir al menos **un registro válido en `mdl_user_password_history`** por usuario con password local.

Hashes válidos:

```
$2y$...   (bcrypt)
$6$...    (sha512-crypt)
```

El plugin:

* inserta registros en el histórico
* evita duplicados
* puede restaurar `mdl_user.password` desde el último hash válido.

---

# 3. Usuarios de emergencia (break-glass)

El plugin permite definir **usuarios de emergencia excluidos del flujo automático**.

Objetivo:

Garantizar acceso administrativo incluso si existen incidencias en:

* Cl@ve
* Keycloak
* LDAP
* OIDC

Estos usuarios:

* no cambian `auth`
* no se normaliza su username
* no se ejecuta lógica LDAP-first
* no se manipulan sus contraseñas
* no se modifica su histórico

Configuración:

```
emergency_usernames
```

Ejemplo:

```
admin
root
soporte
```

También se mantiene compatibilidad con:

```
emergency_admin_username
```

---

# 4. Control de mensajes HTML personalizados

El plugin permite configurar mensajes HTML mostrados en la pantalla de login:

```
custom_error_clave_html
custom_error_ldap_html
custom_error_pwd_html
```

Estos mensajes se almacenan en:

```
mdl_config_plugins
```

y se muestran en una página intermedia propia del plugin:

```
/auth/mcc/error.php?type=clave (fallback cuando no hay conciliación OIDC válida)
/auth/mcc/error.php?type=ldap
/auth/mcc/error.php?type=pwd
```

Regla funcional de enrutado:

* `type=pwd` para credenciales LDAP incorrectas.
* `type=ldap` para errores técnicos o de disponibilidad LDAP.
* en `type=pwd`, si el usuario canónico existe, se restablece `auth=oidc` antes de mostrar el error.

Renderizado de la página:

```
$OUTPUT->notification(...)
```

Para evitar riesgos XSS:

* el contenido pasa por `format_text`
* `trusted = false`
* `noclean = false`

Por tanto Moodle aplica su limpieza estándar de HTML.

Solo administradores con privilegios de configuración del plugin pueden modificar estos mensajes.

---

# 5. Controles implementados por el plugin

El plugin implementa los siguientes controles de seguridad:

### Normalización de identidad

* convergencia `E+NIF/NIE → NIF/NIE`
* búsqueda case-insensitive
* conciliación OIDC por `UPPER(username) = UPPER(?)` para evitar fallos por discrepancias de case

### Protección del hash

* el plugin **no genera contraseñas**
* solo preserva hashes existentes

### Gestión segura del histórico

* inserción controlada
* restauración de password desde histórico si es necesario

### Exclusión de usuarios de emergencia

* evita bloqueos administrativos

---

# 6. Consultas de verificación operativa

Usuarios con password inválido:

```sql
SELECT id, username, auth, password
FROM mdl_user
WHERE deleted = 0
AND password LIKE 'not%';
```

Usuarios sin histórico:

```sql
SELECT u.id, u.username
FROM mdl_user u
LEFT JOIN mdl_user_password_history h ON h.userid = u.id
WHERE u.deleted = 0
AND h.userid IS NULL;
```

Usuarios que no están en reposo `oidc`:

```sql
SELECT id, username, auth
FROM mdl_user
WHERE deleted = 0
AND username <> 'admin'
AND auth <> 'oidc';
```

---

# 7. Respuesta ante incidencias

## Usuario autenticado en Cl@ve pero no encontrado

Comprobar:

* existencia de `username = NIF/NIE`
* normalización de variantes `E+NIF/NIE`.
* coherencia de mayúsculas/minúsculas entre claim OIDC y `mdl_user.username` (el plugin concilia con `UPPER(...)`).

---

## Login failed (ID 3)

Comprobar:

* estado del campo `password`
* existencia de registros en `mdl_user_password_history`.

Si procede, restaurar el último hash válido.

---

## Duplicados creados por LDAP

Si LDAP autocrea usuarios con:

```
E+NIF/NIE
```

el plugin intentará normalizar.

Si existe conflicto:

* se mantiene el registro existente
* se genera traza en logs PHP.

---

# 8. Configuración recomendada en GitHub

Para publicación del repositorio:

* Activar **Private vulnerability reporting**.
* Activar **GitHub Security Advisories**.
* Activar **Secret scanning** (y push protection si está disponible en el plan).
* Mantener visible este documento (`SECURITY.md`) en la raíz.
