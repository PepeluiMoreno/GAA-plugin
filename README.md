# Gestión Afiliados Asociación (GAA) - Plugin WordPress

Plugin sencillo para gestionar solicitudes de ingreso y el registro de socios.

**Qué hace**
- **Registro de solicitudes**: proporciona un formulario público para que personas soliciten ingreso.
- **CPT `socio`**: crea un Custom Post Type privado para almacenar solicitudes y datos de socios.
- **Validación por email**: genera un token único para validar la dirección de email del solicitante.

**Shortcodes**
- **`[gaa_formulario_ingreso]`**: muestra el formulario de solicitud de ingreso.
- **`[gaa_validar_email]`**: punto para procesar la validación mediante token (enlace enviado por email).

**Hooks y comportamiento**
- Se enganchan acciones en `template_redirect` para procesar envíos y validaciones.
- Se registra el CPT `socio` en la inicialización del plugin.
- Metadatos del formulario se guardan como meta fields con el prefijo `_gaa_`.

**Archivos clave**
- `gestion-afiliados-asociacion.php` - archivo principal del plugin.
- `includes/class-ingreso.php` - lógica del formulario, creación del CPT y procesamiento.
- `includes/class-configuracion.php` - (opcional) configuración del plugin.
- `includes/class-roles.php` - (opcional) definición de roles y capacidades.

**Instalación**
1. Copiar la carpeta del plugin al directorio `wp-content/plugins/` de tu instalación WordPress.
2. Activar el plugin desde el administrador de WordPress.
3. Insertar el shortcode `[gaa_formulario_ingreso]` en una página para mostrar el formulario.

**Desarrollo y sincronización**
- El repositorio puede sincronizarse con un remoto en GitHub. Asegúrate de tener acceso y credenciales configuradas para `git push`.

**Depuración**
- Si encuentras errores de sintaxis PHP, ejecutar `php -l includes/class-ingreso.php` localmente.
- Revisar los logs de PHP/WordPress para mensajes de error o deprecated.

**Contribuir**
- Crear un fork, aplicar cambios y abrir un pull request con la descripción de la mejora.

---
Proyecto: sincronizado desde el workspace local de desarrollo.
