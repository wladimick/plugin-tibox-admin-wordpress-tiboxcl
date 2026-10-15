# Tibox Admin · v1.0.0

Un solo plugin que reemplaza los snippets de WPCode de landings, leads, medición y slider. Queda un menú lateral **Tibox Admin** con estas pestañas:

| Pestaña | Qué hace | Reemplaza estos snippets de WPCode |
|---|---|---|
| **Resumen** | Muestra cifras rápidas (landings, leads, vistas, clics de contacto y portal) y el estado de cada módulo | — |
| **Landings** | Gestiona las landings `/lp/` (modos WordPress, lienzo y HTML completo), activa o pausa la campaña de Ads y trae el importador ZIP de Claude Design | Gestor de Landing Pages v2 · Importador ZIP Claude Design |
| **Leads** | Lista los leads, filtra por landing y exporta CSV para Excel | Endpoint universal de formularios + almacenamiento de leads · WebOps Marketing Lead Bridge |
| **Actividad del sitio** | Mide clics en teléfono, correo, WhatsApp, portal cliente y botones, además de formularios, FAQ, secciones y scroll. Muestra el embudo de cada landing | Medición de interacciones (si se creó) |
| **Slider** | Edita hasta 8 slides del `[tbx_hero_lite]` y elige las imágenes desde Medios | PHP Slider Ligero · Función JS Slider · Estilos de Slider |
| **Formularios y correos** | Define el destinatario (Para), las copias (CC), el remitente, la confirmación al contacto y el envío a WebOps | — |
| **Módulos** | Enciende o apaga cada módulo | — |

Cuánto pesa: 250 KB y no usa librerías externas. El CSS y el JS del slider se cargan solo en las páginas que lo usan, y el script de medición pesa unos 10 KB (unos 3 KB comprimido) y se carga con `defer`.

## Instalación (sin cortar nada)

Los datos no se pierden en la migración. El plugin usa las mismas tablas (`wp_tibox_landing_leads`), el mismo tipo de contenido (`tibox_landing`), las mismas rutas (`/lp/`, `/wp-json/tibox/v1/lead`) y los mismos metadatos que ya usan los snippets.

1. **Respalda primero** con la copia de cPanel o UpdraftPlus.
2. Ve a Plugins › Añadir nuevo › Subir plugin, sube `tibox-admin.zip` y actívalo. Todos los módulos parten **apagados**, así que el sitio sigue funcionando igual que antes.
3. Repite estos pasos con cada módulo, de a uno:
   1. En WPCode, **desactiva** (no borres) los snippets que ese módulo reemplaza.
   2. En Tibox Admin › Módulos, **enciende** el módulo y guarda.
   3. Prueba la landing, el formulario y el slider.
   - Si un snippet sigue activo, el módulo no carga: aparece como «Bloqueado» con un aviso. Así se evitan errores fatales y leads duplicados.
4. Ve a Ajustes › Enlaces permanentes › Guardar (el plugin también lo hace al activar el módulo Landings).
5. Prueba con un lead real. Debe llegar el correo interno a contacto@ con las copias, también el correo de confirmación al contacto, y el lead debe aparecer en la pestaña Leads.
6. Cuando todo funcione durante una semana, **borra** los snippets desactivados.

### Orden recomendado
Landings → Leads → Slider → Actividad del sitio.

## Configuración

- **WebOps:** las credenciales siguen en `wp-config.php` (`TIBOX_WEBOPS_MARKETING_URL`, `TIBOX_WEBOPS_MARKETING_SECRET`, `TIBOX_WEBOPS_SOURCE_KEY`). Nunca se guardan en la base de datos. El módulo Leads es el único que envía a WebOps, así que un lead no se manda dos veces.
- **Correos:** en la pestaña Formularios y correos. El valor por defecto es Para contacto@tibox.cl, con copia a jmoyano@, pfarias@, flizana@ y wdiaz@. Si WP Mail SMTP fuerza el remitente, se usa el suyo.
- **GTM:** el script de medición sigue enviando los eventos al `dataLayer`. El contenedor GTM y la importación `GTM-tibox-medicion-interacciones.json` siguen siendo necesarios para que los datos lleguen a GA4 y Google Ads.

## Privacidad de la medición propia

Se guarda un conteo por día, evento, página y botón. **No** se guardan IP, cookies, correos ni teléfonos. Los bots se ignoran, se aceptan hasta 120 eventos por minuto por visitante y los datos se borran a los 400 días.

## Snippets que NO cubre esta versión (siguen en WPCode)

Ruta Gerencial (27, 28, 30, 48), Seguridad (31), menú, CSS y JS generales del sitio, y los snippets pequeños 2, 3, 7, 8, 9, 10, 12, 29, 36, 39 y 50. Se pueden sumar como módulos nuevos más adelante.

## Pendiente de seguridad

El snippet 44 («Tibox Connect – Endpoint subida de imágenes») tiene una clave escrita en el código, y esa clave quedó también en el archivo de exportación. Hay que moverla a `wp-config.php`, **rotarla** y no compartir ese export.

## Desinstalar

Al desactivar el plugin se quitan sus menús y tareas programadas, pero **no** se borran los leads, las landings ni la actividad. Si vuelves a activar los snippets, todo sigue funcionando.
