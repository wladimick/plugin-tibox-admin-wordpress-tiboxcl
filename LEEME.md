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


## Cambios 1.1.0 — Leads y compatibilidad con Prodata

- **Leads:** tabla compacta, información principal visible, origen/campaña y detalles desplegables (ID, RUT, UTM, GCLID y consentimiento).
- **Borrar:** acción individual desde la tabla principal. Usa POST, permiso manage_options, nonce específico por lead y confirmación. No se habilita desde el metabox de la landing. La eliminación es permanente **solo en la tabla local**: no elimina copias enviadas a WebOps o por correo.
- **Filtros:** landing, mes y búsqueda (nombre, correo, teléfono, empresa o RUT), paginación y exportación CSV consistentes.
- **Opciones:** los ajustes del módulo Formularios pasan de tbx_leads_settings a tbx_admin_lead_form_settings para evitar colisiones con la configuración de **Tibox Leads** de Prodata. TIBOX Admin lee el esquema antiguo únicamente si contiene la clave to y aún no se guardó la configuración nueva. Tras actualizar, abrir **Formularios y correos** y guardar para migrar los valores.

### Instalación en Prodata (sin eliminar datos)

1. **Respaldar** los archivos y la base de datos de Prodata; exportar contactos desde Tibox Leads y conservar la tabla {prefix}tbx_leads.
2. Instalar Tibox Admin 1.1.0 y activar **solo** el módulo **Actividad del sitio**. El resto permanece apagado.
3. Mantener activo Tibox Leads 1.0.0 para conservar la captación de WPForms/Elementor/CF7/HTML, el historial individual, UTM y referencias de WhatsApp. Tibox Admin registra estadísticas agregadas en {prefix}tibox_activity.
4. Revisar GTM/GA4: ambos scripts emiten eventos de clic bajo **nombres distintos**, y una configuración de GTM que escuche ambos podría duplicar conversiones. Validar con Tag Assistant.
5. **No activar el módulo Leads de TIBOX Admin como sustituto de Tibox Leads.** El primero utiliza {prefix}tibox_landing_leads y el endpoint /wp-json/tibox/v1/lead; el segundo, {prefix}tbx_leads, /wp-json/tbx-leads/v1/event y hooks de formularios. No existe migración automática.
6. Retirar Tibox Leads solo después de portar su captación, conservar los registros y verificar el flujo completo en staging.

> El botón «Borrar» de esta versión afecta a **Leads de landings TIBOX**, no a la tabla de contactos de Prodata.


## Módulo Contactos 1.2.0 — Importar historial anterior (beta)

La pestaña **Contactos** se activa opcionalmente en **TIBOX Admin → Módulos**. No es el mismo módulo que **Leads** de landings y puede convivir con el plugin independiente **Tibox Leads 1.0.0**.

### Flujo seguro en Prodata

1. Hacer **respaldo de base de datos y archivos** del WordPress de Prodata antes de instalar la actualización.
2. Mantener **Tibox Leads** activado, con su tabla {prefix}tbx_leads, y activar el módulo **Contactos** de TIBOX Admin.
3. En **TIBOX Admin → Contactos**, el panel detecta automáticamente la tabla y muestra el **total histórico**, **copiados**, **pendientes** y la **distribución por tipo**. Si no existe tabla, no se muestra ninguna acción de importar.
4. Tras verificar los números, pulsar **«Importar hasta 100 registros»**. La confirmación y el nonce evitan envíos accidentales. Para históricos más grandes, repetir el botón por lotes hasta dejar pendientes = 0.
5. Repetir la importación es **idempotente** por el índice único (source_plugin, legacy_id). Se copian datos, **nunca se borra ni se sobrescribe** el historial antiguo. Las fechas se almacenan como GMT y se muestran en el horario local del sitio; se conservan las referencias de WhatsApp, respuestas de formularios, UTM, campañas y el origen. **No se copian** IP, hash de IP, ID de visitante ni user agent.
6. Mientras **ambos plugins** estén activos, los registros nuevos de Tibox Leads se copian a Contactos automáticamente mediante el hook tbx_leads_inserted, sin duplicar el registro original. El módulo Contactos no envía correos ni eventos extra a GTM.
7. Verificar en staging que los conteos coincidan y que los formularios Elementor, WPForms y Contact Form 7, y los clics WhatsApp, se sigan registrando. Tras pasar las pruebas, repetir en producción.

**Límites de esta entrega:** el módulo Contactos 1.2.0 todavía **no sustituye** al capturador original. Su valor inicial es importar, visualizar y sincronizar el historial en el *mismo WordPress*. La captura nativa de formularios (sin depender de Tibox Leads) queda pendiente en la fase posterior. Tampoco se trasladan registros de un WordPress a otro ni se agregan los clics históricos a las métricas diarias del módulo Actividad. No desinstalar Tibox Leads hasta terminar esa fase.


## Analítica 1.4.0 — Mediciones por dispositivos y comportamiento (beta)

Esta rama depende del módulo Contactos en PR #3 y del rediseño de leads en PR #1.

### Datos nuevos en Actividad

- **Dispositivos:** clasificación aproximada a partir del User-Agent recibido (móvil, escritorio, tablet), navegador (Chrome, Safari, Firefox, Edge, Opera, Samsung, Other) y SO (Android, iOS, Windows, macOS, Linux, ChromeOS, Other). La nueva tabla **no guarda User-Agent completo, IP, cookie de visitante ni fingerprint**.
- **Histórico:** no modifica la tabla anterior {prefix}tibox_activity. Guarda datos nuevos en {prefix}tbx_activity_insights, agregados por día, evento, página, dispositivo, navegador, SO y campañas saneadas.
- **Filtros:** dispositivo, período 7/30/90 días y página. Los resultados muestran *eventos*, no visitantes únicos.
- **Embudo:** por página y dispositivo: vistas, CTA, formulario iniciado, envío confirmado observado (solo AJAX o hooks existentes), WhatsApp, errores y ratio orientativo.
- **Comportamiento:** clic en descarga, clic externo, transiciones anónimas entre rutas, clics repetidos (3 en 1,7 segundos) y minutos de interacción aproximados en bloques de 15 segundos.
- **Campañas:** fuente, medio y campaña UTM; se conservan en sessionStorage en la pestaña, sin identificador personal. Si corresponde, utiliza el dominio referente externo. No guarda URLs de referencia completas ni otros parámetros.
- **Exclusión:** WordPress no encola el rastreador a visitantes conectados con una cuenta WordPress. El script respeta Global Privacy Control y window.TBX_ANALYTICS_ALLOWED === false si este valor se configura **antes** de cargar el JS.
- **Retención:** 400 días, igual que la tabla histórica.
- **Privacidad:** no se guardan IDs de sesión, IP ni User-Agent sin procesar. Las estimaciones por navegador pueden ser incompletas o imprecisas.
- **GTM:** los nuevos eventos solo se envían al sistema local. Los eventos legacy a GTM se mantienen sin cambios, incluido wpforms_submit. Validar convivencia con el plugin anterior y Tag Assistant antes de producir.

### Formularios y contactos

- Detección del primer input en WPForms, Elementor, Contact Form 7 y formularios HTML con el atributo data-tbx-track-form. El éxito de formulario solo se cuenta cuando existe confirmación de WPForms/Elementor por AJAX o evento wpcf7mailsent. El envío HTML normal no se declara exitoso sin callback.
- El ratio envíos/inicios **no es tasa de usuarios únicos** y no prueba abandono individual; puede superar el 100 %.
- En **Contactos**, los registros provenientes de Tibox Leads guardan dispositivo/navegador/SO derivados de su User-Agent histórico. No se copia el User-Agent completo. Si no se puede inferir el dispositivo, queda como desconocido. Se agregó filtro por dispositivo.

### Despliegue seguro

1. Validar CI, revisar PR #4 y realizar respaldo completo de archivos y BD.
2. En Prodata staging, activar **solo** Actividad y Contactos en TIBOX Admin, conservando TIBOX Leads 1.0.0 como capturador individual.
3. Probar clics, páginas, campañas, formularios y referencias reales. Comprobar que las estadísticas admin no se registren.
4. Comprobar que las tablas históricas sigan sin cambios y que las conversiones GTM/GA4 no se dupliquen.
5. Fusionar e instalar en producción solo cuando se terminen pruebas funcionales.

**Aún pendiente:** pruebas con WordPress real, captura nativa de todos los formularios en Contactos sin depender del plugin antiguo, Web Vitals (LCP/CLS/INP) y configuración del consentimiento según el sitio.


## Core Web Vitals — Fase opt-in (rama de desarrollo)

- Activación desde **TIBOX Admin > Actividad del sitio > Medición de rendimiento**. Desactivado de forma predeterminada.
- Usa web-vitals 5.3.0 de GoogleChrome mediante CDN externo versionado. La descarga de esa dependencia solo ocurre cuando se habilita la función y el cliente no ha declarado Global Privacy Control ni TBX_ANALYTICS_ALLOWED === false al momento de cargar el script. El operador debe validar disponibilidad, privacidad y política de consentimiento antes del despliegue; idealmente se debe servir una copia local del bundle en una versión futura.
- Se envían muestras LCP (ms), INP (ms) y CLS (puntos) al endpoint local. El servidor **descarta sus valores después de clasificarlos**: solo persiste totales diarios por página sin query, dispositivo y categoría (bueno/mejorable/deficiente).
- Umbrales: LCP <= 2.5 s / <= 4 s, INP <= 200 ms / <= 500 ms, CLS <= 0.1 / <= 0.25. Se aplican con criterios inclusivos.
- No se guardan IP, UA, cookies, identificador de visita o valores individuales. Retención: 400 días.
- El tablero presenta % de muestras buenas por métrica, pero **no calcula percentil 75**; por tanto no debe compararse directamente con la calificación oficial de Core Web Vitals de Google.
- Como INP se registra solamente cuando el navegador tiene interacciones válidas, puede tener menos muestras que LCP/CLS.
- Controles: permiso manage_options y nonce para cambios; rechazo de fuentes cross-origin, navegadores automáticos y abuso de frecuencia en la API.
- Estado: pruebas automatizadas de parser, umbrales, consentimiento y envío por beacon. Aún debe validarse end-to-end con WordPress, CMP y Tag Assistant antes de activarlo en Prodata.
