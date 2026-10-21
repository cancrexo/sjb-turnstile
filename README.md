# SJB Turnstile

Cloudflare Turnstile en el login, el registro, la recuperación de contraseña y los comentarios de WordPress.

Versión actual: **1.0.0**

Autor: Daniel "Cancrexo" Prol · [cancrexo@gmail.com](mailto:cancrexo@gmail.com)

---

## Requisitos

- WordPress ≥ 6.0
- PHP ≥ 8.3
- Un widget de Turnstile en Cloudflare, con la site key y la secret key. Cómo crearlo: [Crear un widget en el panel de Cloudflare](https://developers.cloudflare.com/turnstile/get-started/widget-management/dashboard/).

---

## Instalación

1. Copia la carpeta del plugin en `wp-content/plugins/sjb-turnstile`.
2. En el escritorio de WordPress: **Plugins → Plugins instalados**.
3. Activa **SJB Turnstile**. Al activar se crea la tabla de intentos si todavía no existe.

---

## Configuración

Ruta: **Ajustes → SJB Turnstile**

Los formularios se guardan por AJAX, sin recargar la página. El resultado sale en un aviso abajo a la derecha.

| Pestaña | Qué hay |
|---------|---------|
| Cloudflare | Site key y secret key. Sin las dos, Turnstile no se aplica. |
| Visualización | Un interruptor por formulario: login, registro, recuperar contraseña y comentarios. Salen encendidos. |
| Configuración | Registro de intentos, borrar la tabla al desinstalar, exportar y vaciar. |
| Acerca de | Autor, versión y fecha. |

El modo del widget (Managed, invisible, etc.) se elige en el panel de Cloudflare, al crear el widget. El hostname de la página tiene que estar autorizado en ese widget.

### Formularios

| Formulario | Dónde aparece |
|------------|----------------|
| Login | `wp-login.php` y el shortcode de The7 `[dt_simple_login_form]` |
| Registro | Formulario de registro de WordPress |
| Recuperar contraseña | Formulario de contraseña perdida |
| Comentarios | Solo visitantes. Con sesión, pingbacks y trackbacks no pasan por Turnstile. |

Si un interruptor está apagado, esa pantalla no muestra el widget ni exige el token.

### Intentos

En **Configuración**, el interruptor **Registrar intentos** sale apagado. Encendido, cada comprobación guarda fecha y hora, IP, resultado (`ok` o `fail`) y página.

- **Exportar** descarga un JSON con todos los registros.
- **Vaciar registros** borra las filas y deja la tabla creada. Pide confirmación.
- **Borrar la tabla al desinstalar** sale apagado. Encendido, al desinstalar el plugin se elimina la tabla. Las claves y el resto de ajustes se borran siempre.

La lista de la pestaña muestra 20 intentos por página. Cambiar de página o de tamaño (20, 50 o 100) pide los datos por AJAX, sin recargar la pantalla, y muestra «Espera, por favor…». El JSON de exportar incluye todos.

---

## Licencia

GPL v2 o posterior.
