# CONFIGURATION.md – Configuración del Plugin auth_mcc

## 1. Introducción

`auth_mcc` asegura que:

* `mdl_user.username` converja a **NIF/NIE**.
* el reposo de `mdl_user.auth` sea **oidc** tras cualquier login correcto.
* se preserve/restaure el hash local mediante `mdl_user_password_history` cuando sea necesario.
* el formulario de login soporte escenarios mixtos (Manual/LDAP) sin romper el acceso por Cl@ve.
* se puedan mostrar **mensajes personalizados HTML en la pantalla de login** para errores de autenticación. 

---

# 2. Configuración del plugin en Moodle

Ruta:

```
Administración del sitio → Plugins → Autenticación → Moodle Cl@ve Connect
```

## 2.1 enable_hotswitch

Activa las funciones automáticas del plugin:

* normalización de username
* conmutación de `mdl_user.auth`
* gestión automática de histórico de contraseñas
* flujo LDAP-first con `E+NIF/NIE`

Valor recomendado:

```
Activado
```

---

## 2.2 openid_authname

Nombre del método de autenticación OIDC instalado en Moodle.

Valor habitual:

```
oidc
```

Este valor es el que el plugin utilizará como estado de reposo de `mdl_user.auth`.

---

# 3. Usuarios de emergencia (break-glass)

Los usuarios de emergencia quedan **exentos completamente de la lógica del plugin**.

Esto permite garantizar acceso administrativo incluso si hay incidencias en LDAP, OIDC o Cl@ve.

## 3.1 emergency_usernames

Lista de usernames excluidos del flujo del plugin.

Formato:

* uno por línea
* o separados por coma

Ejemplo:

```
admin
soporte
root
```

Estos usuarios:

* no se normalizan
* no se cambia su `auth`
* no se ejecuta lógica LDAP-first
* no se modifican sus contraseñas
* no se manipula su histórico

---

## 3.2 emergency_admin_username (legacy)

Configuración heredada para compatibilidad con versiones anteriores.

Si existe, se añadirá automáticamente a la lista de usuarios de emergencia.

Valor por defecto:

```
admin
```

---

# 4. Mensajes personalizados de error

El plugin permite definir **mensajes HTML personalizados** que se mostrarán en la pantalla de login cuando falle el acceso.

Esto evita depender de los mensajes estándar de Moodle.

---

## 4.1 custom_error_clave_html

Mensaje mostrado cuando:

* el usuario se autentica correctamente en **Cl@ve**
* y, tras la conciliación case-insensitive del plugin, **no existe una coincidencia válida en `mdl_user`**

Ejemplo:

```html
<h3>No se ha encontrado su cuenta en la plataforma</h3>

<p>Su identidad ha sido validada correctamente mediante Cl@ve, pero no dispone de acceso a este servicio.</p>

<p>Contacte con el administrador del sistema si considera que debería tener acceso.</p>
```

---

## 4.2 custom_error_ldap_html

Mensaje mostrado cuando falla la autenticación **LDAP** desde el formulario.

Casos típicos:

* usuario inexistente en LDAP
* fallo técnico del servidor LDAP
* indisponibilidad del servicio LDAP

Ejemplo:

```html
<h3>Error de autenticación</h3>

<p>No se ha podido validar su identidad en el sistema corporativo.</p>

<p>Compruebe sus credenciales o contacte con soporte.</p>
```

---

## 4.3 custom_error_pwd_html

Mensaje mostrado cuando el backend **LDAP** devuelve que las credenciales introducidas son incorrectas.

Casos típicos:

* contraseña incorrecta
* combinación usuario/contraseña inválida

Ejemplo:

```html
<h3>Credenciales incorrectas</h3>

<p>No se ha podido validar su identidad con los datos introducidos.</p>

<p>Revise usuario y contraseña e inténtelo de nuevo.</p>
```

---

# 5. Configuración obligatoria de auth_oidc

Ruta:

```
Administración del sitio → Plugins → Autenticación → OpenID Connect
```

Configuración requerida:

```
Binding Username Claim: Custom
Custom claim name: preferred_username
```

Esto permite que OIDC entregue el **NIF/NIE** como identificador de usuario.

---

# 6. Configuración de auth_ldap

Si LDAP está habilitado:

* puede usar **autocreación**
* puede usar usernames `E+NIF/NIE`

El plugin normalizará automáticamente:

```
E12345678Z → 12345678Z
```

si no existe ya el usuario canónico.

---

# 7. Comportamiento por flujo

## 7.1 OIDC / Cl@ve

Cl@ve **no utiliza el formulario de login**.

Flujo:

1. autenticación externa
2. retorno a Moodle
3. resolución de usuario por `preferred_username`

Si el usuario existe:

```
login correcto
auth = oidc
```

Si no existe:

```
intento de conciliación en mdl_user con UPPER(username)=UPPER(?)
```

Si tras la conciliación sigue sin existir:

```
se muestra custom_error_clave_html
```

---

## 7.2 Login con `E+NIF/NIE`

Flujo:

1. login introducido: `E+NIF/NIE`
2. autenticación directa contra LDAP
   - si LDAP responde credenciales incorrectas:

```
se muestra custom_error_pwd_html (error.php?type=pwd)
```

Si el usuario canónico existe en `mdl_user`, el plugin restablece `auth=oidc` antes de mostrar el mensaje.

   - si LDAP responde error técnico o indisponibilidad:

```
se muestra custom_error_ldap_html (error.php?type=ldap)
```

3. si LDAP valida:

```
E12345678Z → 12345678Z
```

4. búsqueda de usuario en Moodle
5. si no existe → creación automática
6. login y reposo `auth=oidc`

---

## 7.3 Login sin `E+NIF/NIE`

Flujo:

1. búsqueda de usuario en Moodle
2. si existe:

```
login normal (core Moodle)
```

3. tras login:

```
auth = oidc
```

4. si no existe:

```
error de usuario no disponible
```

---

# 8. Consultas de verificación

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
