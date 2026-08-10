# ePayco Smart Checkout

Plugin de WordPress para integrar el **ePayco Smart Checkout v2** mediante `sessionId` (API Apify). Panel de ajustes completo, compatible con Elementor y optimizado para móvil.

---

## 🚀 Características

- Shortcode `[epayco_smart_checkout]` para insertar el formulario de pago en cualquier página o widget de Elementor.
- Panel de ajustes en **Ajustes > ePayco Smart Checkout**.
- Soporte de dos modos de checkout:
  - **In-site (onpage)**: se abre dentro de tu página.
  - **Externo (standard)**: redirige al usuario a la pasarela de ePayco.
- En **móvil, siempre usa el modo standard** automáticamente para evitar problemas de zoom.
- **Límites de monto** configurables por divisa (COP y USD).
  - COP con formato de miles automático (`5.000`, `50.000`…).
  - USD con decimales (`1,250.00`).
  - USD siempre sincronizado automáticamente desde COP usando la **TRM del día** (bloqueado).
- **TRM actualizada diariamente** desde la API oficial del gobierno de Colombia (`datos.gov.co`) con fallback a `co.dolarapi.com`.
- Selector de divisa opcional (COP / USD).
- Validación en tiempo real del monto ingresado (bloquea el botón si está fuera del rango configurado).
- Color del botón personalizable con color picker de WordPress.
- Soporte de fuentes: Google Fonts (selector de presets) o fuente personalizada por URL.
- Compatible con **Cloudflare Rocket Loader**, **Autoptimize**, **LiteSpeed Cache**, **WP Rocket**.
- Código limpio, sin dependencias de terceros (aparte de ePayco).

---

## 📋 Instalación

1. Descarga el archivo `epayco-smart-checkout.zip` de la sección [Releases](../../releases).
2. En tu panel de WordPress, ve a **Plugins > Añadir nuevo > Subir plugin**.
3. Selecciona el archivo ZIP y haz clic en **Instalar ahora**.
4. Activa el plugin.
5. Ve a **Ajustes > ePayco Smart Checkout** y configura tus credenciales de ePayco (**Public Key** y **Private Key**).

---

## ⚙️ Configuración

| Campo                        | Descripción                                                                 |
|------------------------------|-----------------------------------------------------------------------------|
| Public Key                   | Llave pública de tu cuenta ePayco.                                          |
| Private Key                  | Llave privada de tu cuenta ePayco (solo se usa en el servidor).             |
| Entorno                      | **Pruebas** (sandbox) o **Producción**.                                     |
| Modo (desktop)               | `In-site (onpage)` o `Externo (standard)`.                                  |
| Texto del botón              | El texto que aparece en el botón de pago.                                   |
| Nombre del pago              | Nombre que se envía a ePayco como `name`.                                   |
| Descripción del pago         | Plantilla de descripción. Variables: `{site_name}`, `{amount}`, `{currency}`, `{date}`, `{page_title}`. |
| URL de Respuesta             | Redirección al finalizar el pago. Si se deja vacío, usa la página actual.   |
| Divisa por defecto           | `COP` o `USD`.                                                              |
| Mostrar selector de divisa   | Muestra u oculta el selector al usuario.                                    |
| Límites de Pago              | Monto mínimo y máximo para COP y USD.                                       |
| USD automático               | USD se calcula siempre desde COP usando la TRM del día (bloqueado). |
| Color del botón              | Color hex del botón de pago.                                                |
| Fuente                       | Google Fonts (preset) o URL personalizada.                                  |

---

## 🧩 Uso del Shortcode

Pega en cualquier página, post o widget de Elementor:

```
[epayco_smart_checkout]
```

También admite parámetros por URL para prellenar el formulario:

```
https://tusitio.com/pagos/?amount=50000&currency=COP
```

---

## 📁 Estructura del Plugin

```
epayco-smart-checkout/
├── epayco-smart-checkout.php   # Archivo principal del plugin
├── readme.txt                  # Changelog y descripción
├── admin/
│   ├── admin.css               # Estilos del panel de administración
│   └── admin.js                # Script del panel de administración
└── assets/
    ├── epayco-sc.css           # Estilos del formulario de pago
    └── epayco-sc.js            # Lógica del formulario de pago
```

---

## 📝 Changelog

### v1.2.5
- **Simplificación del sistema de actualización**: se eliminó la dependencia de GitHub Actions. WordPress ahora consulta directamente la API de etiquetas (Tags) de GitHub y descarga el zipball generado automáticamente por GitHub.

### v1.2.4
- **Fix de compatibilidad**: envuelve el objeto de configuración del checkout en un Proxy de JavaScript para ocultar dinámicamente la propiedad `key`. Esto soluciona los errores de "Expected a value of type never" causados por prototype pollution de la propiedad `key` (común en temas o plugins de WordPress de terceros).

### v1.2.3
- **Fix crítico**: corregido el error `At path: sessionId -- Expected a value of type never` al abrir el checkout. Se eliminó el parámetro `key` del objeto de configuración de `ePayco.checkout.configure()` al usar el flujo de sesión (incompatibles en la librería `checkout-v2.js`).
- Formato visual de montos en el admin: COP muestra miles con puntos (`5.000`), USD con coma y decimal (`1,250.00`).
- Sanitización mejorada en PHP para guardar correctamente los montos con separadores de miles.

### v1.2.2
- Reempaquetado del plugin para forzar la actualización de WordPress.

### v1.2.1
- Agregada validación en tiempo real: bloquea/habilita el botón de pago si el monto está fuera del rango permitido.

### v1.2.0
- Corrección de error fatal de PHP (cierre faltante en `epayco_scs_field_limits`).

### v1.1.9
- Soporte de decimales en montos y límites de transacción.
- Corrección para guardar y editar límites USD manualmente al desactivar la sincronización automática.

### v1.1.7
- Corregida asociación de la llave pública en la inicialización del checkout.

### v1.1.6
- Formato automático de miles (puntos) al digitar el monto.
- Valor enviado a ePayco como número limpio.

### v1.1.4
- Agregada opción "URL de Respuesta" en los ajustes.

### v1.1.0 — v1.1.3
- Panel de ajustes completo con credenciales, colores, fuentes y límites.
- Selector de divisa opcional.
- Compatibilidad con optimizadores de caché (Cloudflare, Autoptimize, LiteSpeed, WP Rocket).

---

## 🔒 Seguridad

- La **Private Key** solo se utiliza en el servidor (PHP) para autenticar contra la API de ePayco. Nunca se expone al navegador.
- Todos los inputs del admin están sanitizados con las funciones estándar de WordPress (`sanitize_text_field`, `esc_url_raw`, `floatval`, etc.).
- El nonce de WordPress protege el endpoint AJAX contra solicitudes no autorizadas.

---

## 📜 Licencia

GPL-2.0-or-later

---

## 👤 Autor

**Daniel Rozo**  
[danielrozo.com](https://danielrozo.com/)
