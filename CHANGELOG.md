# Changelog

Los cambios notables de SJB Turnstile. La versión del plugin es la de la cabecera (`1.0.0`).

## Sin publicar

- La tabla de intentos se pagina de 20 en 20 por AJAX, sin recargar la pantalla, con aviso de espera, números, anterior, siguiente y selector de 20, 50 o 100.
- Pestañas: Visualización (dónde se muestra el widget) y Configuración (intentos). La antigua Info abre Configuración.
- Botón **Probar** en Cloudflare: muestra el widget y comprueba las claves sin guardarlas.

## 1.0.0 — 2026-10-20

- Turnstile en el login, el registro, la recuperación de contraseña y los comentarios de visitantes.
- Pantalla en **Ajustes → SJB Turnstile**: Cloudflare, Configuración, Info y Acerca de.
- Guardado por una sola rutina AJAX, con aviso de éxito, aviso o error.
- Interruptores para activar cada formulario. Salen encendidos.
- Tabla de intentos (fecha y hora, IP, resultado y página), apagada hasta que se enciende el registro.
- Exportar los intentos a JSON y vaciar la tabla.
- Opción de borrar la tabla al desinstalar. Por defecto se conserva.
- El widget se escala al ancho del formulario.
- Botones con Dashicon y esquinas redondeadas.
