# CONTRIBUTING.md

Gracias por contribuir a **Moodle Cl@ve Connect** (`auth_mcc`).

## 1. Alcance

Este repositorio publica el plugin con denominación oficial:

- Componente: `auth_mcc`
- Directorio: `/auth/mcc`

La denominación `auth_clavebridge` fue una nomenclatura privada previa y no se usa como referencia pública.

## 2. Flujo de contribución

1. Abrir una rama por cambio.
2. Realizar cambios mínimos y enfocados.
3. Actualizar documentación afectada.
4. Abrir Pull Request contra `main` usando la plantilla del repositorio.

## 3. Estándares de código

- Seguridad OWASP aplicada a Moodle/PHP.
- Uso obligatorio de APIs Moodle para parámetros y SQL.
- `camelCase` para métodos, propiedades y variables nuevas.
- PHPDoc detallado en métodos nuevos o modificados.
- No introducir credenciales, secretos ni datos sensibles en el código.

## 4. Validación mínima antes de PR

Ejecutar al menos:

```bash
php -l auth.php
php -l classes/observer.php
php -l settings.php
php -l error.php
php -l db/events.php
php -l version.php
php -l lang/es/auth_mcc.php
php -l lang/en/auth_mcc.php
```

Además, verificar manualmente los flujos del `RELEASE_CHECKLIST.md` si el cambio afecta autenticación.

## 5. Versionado

- `version.php` usa `YYYYMMDDXX` en `$plugin->version`.
- `release` mantiene versión funcional legible (actual: `1.0`).
- Si hay cambios funcionales o estructurales, actualizar `CHANGELOG.md`.

## 6. Seguridad y soporte

No reportar vulnerabilidades por issues públicos.

Canal de seguridad y soporte:

```text
hola@ajmelian.info
```

Consultar `SECURITY.md` para el proceso de reporte.
