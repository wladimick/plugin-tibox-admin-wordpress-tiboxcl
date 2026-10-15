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


## Contactos: captura nativa de WPForms, Elementor y Contact Form 7 — 1.5.0 beta

El módulo opcional **Contactos** incluye ahora la sección **Captura directa de formularios**. Su uso es gradual para Prodata:

1. Respaldar el WordPress y la tabla original {prefix}tbx_leads.
2. **Mantener TIBOX Leads activo** y utilizar **Importar datos anteriores** para copiar el histórico a {prefix}tbx_admin_contacts; verificar por cantidad, fecha y referencias de WhatsApp.
3. Mientras el plugin TIBOX Leads esté activo, **TIBOX Admin no captura formularios por su cuenta**, incluso si quedó marcada accidentalmente la opción. Así no duplica el historial ni notificaciones.
4. En staging, comprobar que el historial copiado y las entradas nuevas están completas; entonces **desactivar (no desinstalar ni borrar)** el plugin antiguo, conservando su tabla como respaldo.
5. En **TIBOX Admin > Contactos > Captura directa de formularios** habilitar **Registrar formularios directamente desde TIBOX Admin**.
6. Enviar pruebas reales desde **WPForms**, **Elementor Forms** y **Contact Form 7**, comprobar que aparezcan una sola vez y que se identifiquen navegador y dispositivo, sin datos sensibles.
7. Solo cuando todo lo anterior funcione validar cambios en producción, con rollback por reactivación del plugin original **y desactivación de captura nativa**.

### Comportamiento y límites

- Solo se almacena el formulario después del hook servidor confirmado de WPForms o Elementor o del evento **wpcf7_mail_sent** de CF7. Un simple clic sobre "Enviar" no basta.
- La implementación no registra contraseñas, nonces, captchas, tarjetas, archivos, IDs de visitante ni User-Agent completo en Contactos. Guarda respuestas de campos de texto no sensibles y los datos de contacto que se hayan recogido voluntariamente.
- Los envíos nativos se distinguen por source_plugin = tibox-admin-native y origin = wpforms/elementor/cf7. Un contador interno atómico les asigna IDs separados de los registros legados, para respetar el índice único y evitar conflictos.
- No se disparan nuevos correos ni etiquetas de conversión de GTM por la inserción en Contactos. Si se requiere conservar notificaciones del plugin antiguo, validar que los formularios sigan enviándolas por sus propias configuraciones.
- El HTML genérico y clics individuales de WhatsApp/teléfono/correo continúan dependiendo del plugin original. Aún se requiere portar estos eventos antes de declarar equivalencia funcional plena. El módulo Actividad cuenta sus clics de forma agregada, no preserva las referencias individuales que asignaba TIBOX Leads.
- La atribución UTM de formularios propios se extrae de campos publicados UTM o de la URL de origen cuando los contiene; para igualdad total con la persistencia de campaña del plugin anterior falta implementar un puente de atribución explícito.
- Antes de usar en producción se requiere prueba de integración en WordPress con cada plugin de formularios, revisión de consentimiento y comparación GTM/GA4. No implica ninguna modificación de la base de datos original al importar.


## Contactos 1.6.0 beta — Clics de contacto y referencias WhatsApp

La captura nativa opcional de **Contactos** ahora incluye eventos individuales de WhatsApp, teléfono y correo. La opción sigue desactivada por defecto y se bloquea automáticamente mientras TIBOX Leads 1.0.0 continúe activo para evitar duplicaciones.

- Al clicar en un enlace WhatsApp con número reconocible (wa.me o api.whatsapp.com, por ejemplo), se añade una **referencia aleatoria de seis caracteres** a su texto: Ref: ABC123. La misma referencia se copia a Contactos junto a la fecha, página, posición del enlace y destino.
- Los enlaces WhatsApp de servicios acortados (ej. wa.link), widgets sin ancla editable o sin número no siempre permiten agregar referencia. En tales casos el evento puede guardarse sin referencia; se mantiene el enlace original.
- Los clics en teléfonos (tel:) y correos (mailto:) se almacenan como **intenciones de contacto**; no como ventas ni conversaciones confirmadas.
- Los clics individuales de Contactos son independientes del resumen agregado de Actividad y **no emiten nuevos eventos GTM**, evitando duplicar conversiones de la medición anterior.
- La solución no crea cookies ni IDs de visitante. Solo registra la página (sin query), datos del botón, destino, referencia cuando procede y tres UTMs limitadas de la página o sessionStorage de analítica.
- Hay protección de origen, deduplicación temporal de clics y límite de frecuencia en el endpoint /wp-json/tibox/v1/contact-event; el servidor no almacena IP ni User-Agent completo.

### Faltante de paridad en Prodata

La implementación del plugin original incluía un **botón flotante configurable**, normalización de enlaces WhatsApp y tracking de algunos formularios HTML propios. Estos elementos no están completamente portados. Para desactivar definitivamente TIBOX Leads, documentar en staging qué botones y formularios tiene Prodata, probar que no cambian sus enlaces y validar todas las referencias en WhatsApp, antes de instalar una versión nueva en producción.

Las pruebas automatizadas verifican generación de referencias, registro de teléfono/correo, atribución simple, consentimiento y bloqueo de doble clic. **No reemplazan pruebas de integración en navegador real ni de WordPress**.


## Botón flotante WhatsApp — 1.7.0 beta

En **TIBOX Admin > Contactos**, se agregó la configuración opcional para recrear el botón WhatsApp del plugin independiente, con campos **Activar**, número (8–15 dígitos incluyendo país), texto de botón, mensaje y posición izquierda/derecha.

- El botón está desactivado por defecto y **solo aparece si la captura nativa de Contactos está activa y TIBOX Leads no está activo**. Evita superponer dos botones.
- El botón usa estilos CSS propios responsivos y compatibles con foco de teclado, reducida animación y móviles.
- Permite las variables {pagina} y {sitio} en el mensaje, sin ejecutar HTML. Utiliza el registro nativo de clics del módulo Contactos para agregar la referencia en enlaces wa.me con número válido.
- No añade seguimiento de usuario, cookie ni eventos de conversión GTM.
- Probar en staging que la posición no tape otros widgets, banners de cookies o contenido móvil y que la referencia aparezca efectivamente en el mensaje abierto.
- Antes de retirar el plugin antiguo también debe confirmarse si Prodata utiliza formularios HTML hechos a medida u otros widgets WhatsApp distintos del flotante. La equivalencia todavía requiere QA funcional.
