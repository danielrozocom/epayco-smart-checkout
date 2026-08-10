=== ePayco Smart Checkout ===
Stable tag: 1.2.8

Cambios:
- Autor: Daniel Rozo (https://danielrozo.com/)
- En la lista de plugins aparece el link “Ajustes” para abrir la configuración.
- Se eliminó el campo de Título (el formulario ya no muestra título).
- Form sin borde/sombra (se integra con tu diseño).
- Límites por divisa:
  - COP siempre editable.
  - USD siempre sincronizado automáticamente desde COP usando la TRM del día (bloqueado).
- En móvil siempre abre checkout en modo standard (no configurable) para evitar zoom.

- UX admin mejorada + USD siempre automático desde COP (sin toggle manual).

- Mensajes mejorados: 'Creando sesión…' y si está en standard: 'Redirigiendo al checkout…'.

- Mensaje 'Procesando…' y eliminación total de bordes del form.

- Fix mobile: espera a que cargue checkout-v2.js antes de abrir; mensaje claro si un optimizador lo bloquea.

- Public/Private Key ahora se configuran desde el panel del plugin (no wp-config.php).

- Fix mobile: usa window.ePayco (según docs). Quitado hint de rango automático. 'Procesando…' solo en el mensaje.

- v3.1: eliminado totalmente el borde del contenedor del formulario.

- v3.2: mensaje único 'Procesando…'; el botón solo se deshabilita (sin cambiar texto).

- v3.4: selector de divisa opcional (mostrar/ocultar) + color picker real de WordPress.

- v3.5: mensaje de checkout simplificado + checkbox divisa guarda bien al desactivar + botón no cambia texto.

- v3.6: eliminado cualquier borde del contenedor del shortcode.

- v1.1.4: Agregada opción "URL de Respuesta" en los ajustes para redirigir al usuario a una página personalizada al finalizar el pago.

- v1.1.6: Agregado formato automático de miles (puntos) al digitar el monto, enviando el valor como entero limpio a ePayco.

- v1.1.7: Corregida asociación de la llave pública en la inicialización del checkout.

- v1.1.9: Soporte de decimales en montos y límites de transacción, y corrección para permitir guardar y editar los límites USD manualmente al desactivar la sincronización automática.

- v1.2.0: Corrección de error fatal de PHP (falta de cierre en la función epayco_scs_field_limits en la administración).

- v1.2.1: Agregada validación en tiempo real para bloquear/habilitar el botón de pago y alertar si el monto está fuera del rango permitido.

- v1.2.2: Reempaquetado del plugin para forzar la actualización de WordPress.

- v1.2.3: Fix crítico: eliminado el parámetro `key` de `ePayco.checkout.configure()` al usar el flujo de sessionId, corrigiendo el error "At path: sessionId -- Expected a value of type never". Formato de montos en admin: COP con puntos de miles (5.000), USD con coma y decimal (1,250.00). Sanitización mejorada en PHP para guardar montos con separadores correctamente.

- v1.2.4: Fix de compatibilidad: se envolvió el objeto de configuración del checkout en un Proxy de JavaScript para ocultar dinámicamente la propiedad `key`. Esto soluciona los errores de "Expected a value of type never" causados por prototype pollution (cuando otros plugins o temas de WordPress definen Object.prototype.key de forma global).

- v1.2.5: Simplificación del sistema de actualización: se eliminó la dependencia de GitHub Actions. WordPress ahora consulta la API de etiquetas (Tags) de GitHub y descarga el zipball generado automáticamente por GitHub directamente.

- v1.2.6: USD siempre automático desde COP usando la TRM del día. Se eliminó el toggle manual de USD y los campos USD quedan permanentemente bloqueados en el admin.

- v1.2.7: Fix crítico: se reemplazó el Proxy de configuración del checkout por una whitelist estricta de propiedades (sessionId, type, test). Esto soluciona el error "Expected a value of type never" causado por prototype pollution agresiva en algunos temas/plugins de WordPress.

- v1.2.8: Fix definitivo: se reemplazó el Proxy por un objeto congelafo (Object.freeze) con prototipo nulo (Object.create(null)). Esto elimina por completo cualquier interferencia de prototype pollution de otros plugins/temas en la configuración del checkout.
