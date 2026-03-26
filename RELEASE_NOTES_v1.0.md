# Release Notes v1.0

**Proyecto:** Moodle Cl@ve Connect  
**Componente Moodle:** `auth_mcc`  
**Directorio de instalación:** `/auth/mcc`  
**Fecha:** 2026-03-26

## Resumen

Primera release pública estable del plugin de autenticación **Moodle Cl@ve Connect** para entornos Moodle con integración Cl@ve/OIDC y LDAP.

## Alcance funcional

- Capa de coherencia entre Moodle, Cl@ve/Keycloak OIDC, LDAP y login manual.
- Modelo canónico de identidad: `username = NIF/NIE`.
- Estado de reposo tras login correcto: `auth = oidc` (con excepciones explícitas).
- Soporte de usuarios de emergencia (`break-glass`) excluidos de automatismos.
- Mensajes HTML personalizados para errores de Cl@ve y LDAP en pantalla intermedia del plugin.
- Preservación/restauración de hash local desde `mdl_user_password_history` cuando aplica.

## Nomenclatura pública

La denominación oficial y pública de esta release es:

- `auth_mcc`
- `/auth/mcc`

`auth_clavebridge` fue una denominación privada de cliente y no forma parte de la distribución pública de esta versión.

## Compatibilidad

- Moodle `4.5.4+`
- PHP `8.1+`
- Requiere `auth_oidc` para flujo Cl@ve/OIDC
- Compatible con `auth_ldap` para flujo LDAP-first en login de formulario

## Instalación rápida

```bash
cp -R auth/mcc ${MOODLE_ROOT}/auth/mcc
php admin/cli/upgrade.php
php admin/cli/purge_caches.php
```

## Seguridad y soporte

- Licencia: GNU GPL v3
- Contacto de soporte y seguridad: `hola@ajmelian.info`
- Política de reporte: ver `SECURITY.md`

## Referencias

- `README.md`
- `CONFIGURATION.md`
- `ARCHITECTURE.md`
- `SECURITY.md`
- `CHANGELOG.md`
