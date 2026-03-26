# auth_mcc — Moodle Cl@ve Connect (Keycloak OIDC)

**Versión:** 1.0
**Fecha:** 2026-03-26
**Codename:** auth_mcc
**Directorio de instalación:** `/auth/mcc`
**Autor:** Aythami Melián Perdomo - hola@ajmelian.info
**Licencia:** GNU GPL v3
**Compatibilidad:** Moodle 4.5.4+ (PHP 8.1 o superior)

---

# Descripción general

El plugin **auth_mcc** proporciona un puente de autenticación entre Moodle y **Cl@ve** mediante **Keycloak (OIDC)**.

El objetivo del plugin es mantener una **coherencia operativa en la base de usuarios de Moodle**, asegurando que:

* el reposo de `mdl_user.auth` sea **`oidc`**
* el `username` canónico sea **NIF/NIE**
* los accesos manuales o LDAP **no rompan el flujo OIDC**
* los errores de autenticación puedan mostrarse mediante **mensajes HTML configurables**

El plugin actúa como **capa de coherencia entre Cl@ve, LDAP y el formulario de login de Moodle**.

Importante:

El acceso por **Cl@ve/OIDC no utiliza el formulario estándar de login**, por lo que el plugin **no modifica el flujo OIDC**, sino que interviene en:

* resolución de usuarios
* normalización de identificadores
* flujo del formulario manual / LDAP
* coherencia del método de autenticación

---

# Nomenclatura pública

La denominación pública y soportada de este plugin es:

```
auth_mcc
/auth/mcc
```

La denominación `auth_clavebridge` corresponde a una nomenclatura privada previa de cliente y **no** forma parte de la distribución pública actual.

---

# Objetivos funcionales

## Reposo global de autenticación

Tras cualquier login correcto:

* Cl@ve (OIDC)
* LDAP
* Manual

el usuario queda con:

```
mdl_user.auth = oidc
```

---

## Username canónico

El username en Moodle debe ser siempre:

```
NIF/NIE
```

Nunca:

```
E+NIF/NIE
```

El plugin normaliza automáticamente cuando detecta variantes.

---

## Preservación de contraseñas locales

El plugin **no crea ni modifica contraseñas**.

Solo:

* preserva hashes existentes
* restaura el hash desde `mdl_user_password_history` si Moodle lo deja en `not cached`.

Hashes soportados:

```
bcrypt ($2y$...)
sha512-crypt ($6$...)
```

---

# Usuarios de emergencia (break-glass)

El plugin permite definir **usuarios excluidos completamente del flujo automático**.

Objetivo:

Garantizar acceso administrativo incluso si hay incidencias en:

* LDAP
* OIDC
* Cl@ve
* Keycloak

Estos usuarios:

* no cambian `auth`
* no se normaliza su username
* no se manipula su contraseña
* no se ejecuta lógica LDAP-first

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

---

# Mensajes personalizados de error

El plugin permite definir **mensajes HTML configurables** para errores mostrados en la pantalla de login.

Esto evita depender de los mensajes estándar de Moodle.

## Error de acceso por Cl@ve

Caso:

* usuario autenticado en Cl@ve
* pero **no existe en Moodle**

Configuración:

```
custom_error_clave_html
```

Ejemplo:

```html
<h3>No dispone de acceso a esta plataforma</h3>
<p>Su identidad ha sido validada mediante Cl@ve pero no tiene permisos para acceder.</p>
```

---

## Error de autenticación LDAP

Caso:

* usuario inexistente en LDAP
* error técnico LDAP
* indisponibilidad del servicio LDAP

Configuración:

```
custom_error_ldap_html
```

Ejemplo:

```html
<h3>Error de autenticación</h3>
<p>No se ha podido validar su identidad en el sistema corporativo.</p>
```

---

## Error de credenciales LDAP

Caso:

* LDAP devuelve credenciales incorrectas

Configuración:

```
custom_error_pwd_html
```

Ejemplo:

```html
<h3>Credenciales incorrectas</h3>
<p>Revise su usuario y contraseña e inténtelo de nuevo.</p>
```

---

# Flujo de acceso por Cl@ve (OIDC)

1. Keycloak autentica mediante Cl@ve.
2. Se devuelve un token OIDC con `preferred_username`.
3. Moodle valida el token mediante `auth_oidc`.
4. El plugin:

* busca usuario por `NIF/NIE`
* soporta variantes `E+NIF/NIE`

Si el usuario existe:

```
login correcto
auth = oidc
```

Si no existe:

```
se intenta conciliación case-insensitive con UPPER(username)=UPPER(?)
```

Si tras la conciliación no existe:

```
mensaje personalizado
custom_error_clave_html
```

---

# Flujo de acceso mediante formulario

El formulario se usa para:

* Manual
* LDAP

---

## Login `E+NIF/NIE`

Flujo:

1. autenticación directa contra LDAP
   - si LDAP devuelve credenciales incorrectas:

```
mensaje personalizado
custom_error_pwd_html
```

Si el usuario canónico ya existe en Moodle, antes de mostrar ese error el plugin restablece:

```
auth = oidc
```

   - si LDAP devuelve error técnico o indisponibilidad:

```
mensaje personalizado
custom_error_ldap_html
```

2. si LDAP valida:

```
E12345678Z → 12345678Z
```

3. búsqueda de usuario Moodle

Si existe:

```
login
auth = oidc
```

Si no existe:

```
autocreación
username = NIF/NIE
auth = oidc
```

---

## Login sin `E+NIF/NIE`

Flujo:

1. búsqueda en Moodle
2. si existe:

```
login normal
```

3. tras login:

```
auth = oidc
```

Si no existe:

```
error usuario no disponible
```

---

# Instalación

Copiar el plugin en:

```
/auth/mcc/
```

Ejecutar:

```
php admin/cli/upgrade.php
php admin/cli/purge_caches.php
```

Activar en:

```
Administración del sitio
→ Plugins
→ Autenticación
→ Gestionar autenticación
→ Moodle Cl@ve Connect
```

---

# Eventos y auditoría

El plugin puede generar trazas para:

* fallos de autenticación
* normalización de usuarios
* cambios automáticos de método de autenticación

---

# Soporte y seguridad

Canal de soporte y notificación de vulnerabilidades:

```
hola@ajmelian.info
```

Para el proceso de reporte responsable, ver `SECURITY.md`.

---

# Documentación

Documentos incluidos en el plugin:

```
README.md
CONFIGURATION.md
CHANGELOG.md
SECURITY.md
ARCHITECTURE.md
CONTRIBUTING.md
RELEASE_CHECKLIST.md
RELEASE_NOTES_v1.0.md
LICENSE
```
