# ARCHITECTURE.md – Arquitectura del Plugin auth_mcc

## 1. Visión general

El plugin **auth_mcc** actúa como una **capa de coherencia de identidad y autenticación** entre los siguientes sistemas:

* **Moodle**
* **Cl@ve** (mediante Keycloak OIDC)
* **LDAP corporativo**
* **Formulario de login estándar de Moodle**

El objetivo principal es garantizar que el estado final de los usuarios en Moodle sea siempre consistente:

```
mdl_user.username = NIF/NIE
mdl_user.auth     = oidc
```

independientemente del mecanismo de acceso utilizado.

---

# 2. Componentes principales

El plugin está compuesto por cuatro componentes funcionales.

## auth.php

Responsable de:

* Interceptar el flujo del **formulario de login**
* Gestionar el flujo **LDAP-first**
* Resolver usuarios Moodle
* Crear usuarios cuando LDAP valida pero el usuario no existe
* Mostrar **mensajes personalizados de error**
* Mantener coherencia con el método OIDC

Puntos clave:

* `loginpage_hook()`
* `ldap_bind_check()`
* `create_moodle_user_from_ldap()`
* `set_user_auth_to_oidc()`

---

## observer.php

Gestiona eventos del sistema Moodle.

Eventos utilizados:

```
user_login_failed
user_loggedin
user_created
user_updated
user_password_updated
```

Responsabilidades:

* Gestionar el caso OIDC "usuario no existente" en callback real y redirigir a `error.php?type=clave`
* Forzar reposo `auth = oidc`
* Mantener histórico de contraseñas
* Restaurar hash desde `mdl_user_password_history`
* Normalizar usernames autocreados por LDAP

---

## settings.php

Define la configuración administrativa del plugin.

Parámetros principales:

```
enable_hotswitch
openid_authname
emergency_usernames
custom_error_clave_html
custom_error_ldap_html
custom_error_pwd_html
```

Estos parámetros se almacenan en:

```
mdl_config_plugins
```

---

## version.php

Define metadatos del plugin:

* versión
* requisitos de Moodle
* estado de madurez

---

# 3. Arquitectura de autenticación

El plugin soporta tres flujos de autenticación.

```
                ┌─────────────┐
                │    Cl@ve    │
                │  Keycloak   │
                └──────┬──────┘
                       │ OIDC
                       ▼
                 ┌──────────┐
                 │ auth_oidc│
                 └────┬─────┘
                      │
                      ▼
               ┌───────────────┐
               │auth_mcc│
               └──────┬────────┘
                      │
                      ▼
                 Moodle Core
```

---

# 4. Flujo OIDC (Cl@ve)

```
Usuario
   │
   ▼
Cl@ve / Keycloak
   │
   ▼
auth_oidc
   │
   ▼
auth_mcc
```

Proceso:

1. Cl@ve autentica al usuario.
2. Keycloak devuelve token OIDC.
3. Moodle valida el token mediante `auth_oidc`.
4. El plugin:

   * obtiene `preferred_username`
   * normaliza identidad
   * concilia usuario en Moodle con comparación `UPPER(username) = UPPER(?)` cuando procede.

### Caso 1: usuario existe

```
login correcto
auth = oidc
```

### Caso 2: usuario no existe

```
si no hay coincidencia unívoca tras conciliación UPPER(...)
observer.php (evento user_login_failed en callback OIDC real)
redirección a /auth/mcc/error.php?type=clave
mensaje custom_error_clave_html
```

---

# 5. Flujo LDAP-first

Este flujo se activa cuando el usuario introduce:

```
E+NIF/NIE
```

Arquitectura:

```
Usuario
  │
  ▼
Formulario login
  │
  ▼
auth_mcc
  │
  ▼
LDAP
```

Proceso:

1. Usuario introduce `E+NIF/NIE`.
2. Plugin autentica directamente contra LDAP.
   - Si LDAP devuelve `invalid credentials`:

```
redirección a /auth/mcc/error.php?type=pwd
mensaje custom_error_pwd_html
```

     Antes de redirigir, si existe el usuario canónico en Moodle, se restablece su `auth=oidc`.

   - Si LDAP devuelve fallo técnico o indisponibilidad:

```
redirección a /auth/mcc/error.php?type=ldap
mensaje custom_error_ldap_html
```

3. Si LDAP valida:

```
E12345678Z → 12345678Z
```

4. Se busca usuario Moodle.

### Usuario existente

```
login
auth = oidc
```

### Usuario inexistente

```
crear usuario
username = NIF/NIE
auth = oidc
login
```

---

# 6. Flujo login manual

Cuando el login no es `E+NIF/NIE`:

```
Usuario
  │
  ▼
Formulario login
  │
  ▼
Moodle Core
```

Proceso:

1. Se busca usuario en Moodle.
2. Si existe:

```
login normal
```

3. Tras login correcto:

```
observer → auth = oidc
```

---

# 7. Normalización de identidad

El sistema acepta variantes:

```
12345678Z
E12345678Z
```

pero el estado final en Moodle debe ser:

```
username = 12345678Z
```

Normalización aplicada en:

* `auth.php`
* `observer.php`

La búsqueda de usuarios es **case-insensitive**.

---

# 8. Gestión de contraseñas

El plugin **no genera contraseñas**.

Responsabilidades:

* preservar hash existente
* restaurar hash desde histórico si Moodle lo pierde.

Tabla utilizada:

```
mdl_user_password_history
```

Hashes soportados:

```
$2y$   bcrypt
$6$    sha512-crypt
```

---

# 9. Usuarios de emergencia

Se permite definir usuarios excluidos de la lógica del plugin.

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

Estos usuarios:

* no cambian `auth`
* no se normalizan
* no ejecutan LDAP-first
* no se manipulan sus contraseñas

Objetivo:

Garantizar acceso administrativo en caso de fallo de:

* OIDC
* LDAP
* Keycloak
* Cl@ve.

---

# 10. Gestión de errores

Los errores mostrados en el login son configurables.

Configuraciones:

```
custom_error_clave_html
custom_error_ldap_html
custom_error_pwd_html
```

Se almacenan en:

```
mdl_config_plugins
```

Renderizado:

```
/auth/mcc/error.php?type=clave  (fallback si no hay match OIDC tras conciliación UPPER)
/auth/mcc/error.php?type=ldap
/auth/mcc/error.php?type=pwd
```

La página intermedia renderiza el mensaje con:

```
$OUTPUT->notification(...)
```

El HTML pasa por:

```
format_text()
```

lo que aplica limpieza estándar de Moodle.

---

# 11. Seguridad

Principios aplicados:

* convergencia de identidad
* preservación de hashes
* eliminación de duplicados LDAP
* protección de acceso administrativo
* sanitización del HTML mostrado

Las directrices completas se documentan en:

```
SECURITY.md
```

---

# 12. Dependencias externas

El plugin depende de:

### auth_oidc

Proveedor de autenticación OIDC.

### auth_ldap

Proveedor de autenticación LDAP.

### Keycloak

Proveedor OIDC conectado a Cl@ve.

---

# 13. Esquema general de arquitectura

```
              +----------------+
              |     Cl@ve      |
              |   Keycloak     |
              +--------+-------+
                       |
                       | OIDC
                       ▼
               +---------------+
               |   auth_oidc   |
               +-------+-------+
                       |
                       ▼
              +--------------------+
              |  auth_mcc  |
              +-----+---------+----+
                    |         |
                    |         |
                    ▼         ▼
                 LDAP      Moodle Core
                               |
                               ▼
                        mdl_user
```

---

# 14. Documentación relacionada

```
README.md
CONFIGURATION.md
SECURITY.md
CHANGELOG.md
```
