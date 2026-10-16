# Matriz de paridad de medición TIBOX Leads → TIBOX Admin

Base auditada: fuente original **TIBOX Leads 1.0.0** (tibox-leads.php + assets/tbx-leads.js), rama del plugin **TIBOX Admin 1.10.4 beta**, más assets/tibox-tracking.js y su módulo Actividad. Esta matriz describe emisiones de código, **no prueba que Google Tag Manager/GA4/Ads reciban o contabilicen cada evento**.

## Eventos conocidos

| Fuente / interacción | TIBOX Leads 1.0.0 | TIBOX Admin 1.10.4 | Condición de paridad / riesgo |
| --- | --- | --- | --- |
| Click WhatsApp | `tbx_whatsapp_click` `{ubicacion,texto_boton,tbx_ref}` | Compatibilidad opt-in del botón y enlaces `tbx_whatsapp_click`; registro en Contactos `type=whatsapp` | Evento indica clic; referencia solo si el texto llegó a URL; sin unificación obligatoria |
| Click teléfono | `tbx_phone_click` `{ubicacion,texto_boton}` | Mismo evento opt-in; Contactos `type=phone` | No es una llamada contestada |
| Click correo | `tbx_email_click` `{ubicacion,texto_boton}` | Mismo evento opt-in; Contactos `type=email` | No significa correo enviado |
| Formulario WPForms AJAX validado | `tbx_generate_lead` `{form_id,origen_formulario=wpforms}` | Compatibilidad de formularios opt-in, evento `tbx_generate_lead` con `form_id,form_name,origen_formulario` | Activar solo tras comprobar tags de GTM; TIBOX Admin ya emite `wpforms_submit` en Actividad |
| Formulario WPForms sin AJAX | Cookie temporal `tbx_lead` después de envío WordPress confirmado → `tbx_generate_lead` al cargar próxima página | Cookie temporal `tbx_admin_form_gtm` solo tras inserción correcta en Contactos; se consume una vez | Se evita cookie para AJAX/REST; comprobar thank-you, redirecciones y cachés |
| Elementor Pro (envío exitoso) | `tbx_generate_lead` por `submit_success` | Mismo evento, opt-in, escuchando `submit_success`; guardado nativo vía hook PHP | Depende del evento del navegador y el plugin Elementor |
| Contact Form 7 (correo enviado) | `tbx_generate_lead` por `wpcf7mailsent` | Mismo evento, opt-in, escuchando `wpcf7mailsent`; guardado nativo vía `wpcf7_mail_sent` | No contar eventos `wpcf7invalid` o envío fallido |
| Formularios HTML hechos a mano | Capturador automático `submit` y `tbx_generate_lead`, incluso sin comprobación de aceptación de servidor | Evento `tbx:form-success` de la landing (opt-in); no se captura un simple submit como éxito | **Paridad pendiente:** inventariar cada landing y añadir señal únicamente al aceptar backend; conectar guardado de lead si es necesaria captura |
| Eventos TIBOX Admin Actividad | No aplica | `page_view`, `phone_click`, `email_click`, `whatsapp_click`, `cta_click`, `form_start`, `wpforms_submit`, `form_error`, `form_submit` (landings), `portal_click`, `faq_open`, `section_view`, `scroll_depth`, otros | No cambiar estos nombres, son un sistema independiente; pueden solaparse con tags de conversión legado |
| Formularios de campaña Prodata y landings | GTM / GA4 previamente implementados en los sitios (fuera del repo del plugin) | TIBOX Admin no reescribe tags, activadores ni el contenedor GTM | **Verificación externa pendiente**: `generate_lead`, `form_submit`, `form_start`, `whatsapp_click`, `submit_lead_form`, conversiones de Google Ads y parámetros de landing |

## Protección implementada

1. Compatibilidad de clics **apagada por defecto** y gobernada por la opción independiente `tbx_admin_contact_whatsapp.gtm_legacy`.
2. Compatibilidad de formularios **apagada por defecto** en `tbx_contacts_form_gtm_compat_enabled`; exige captura nativa de formularios activada.
3. Ambos componentes nativos se bloquean automáticamente mientras se detecte TIBOX Leads activo, para que el antiguo siga siendo dueño de los eventos y contactos durante convivencia.
4. Se respetan `window.TBX_ANALYTICS_ALLOWED === false` y `navigator.globalPrivacyControl === true`; no se incluyen correos, teléfonos ni mensajes en el `dataLayer` de compatibilidad.
5. Dedupe breve de callbacks repetidos: WhatsApp 1500 ms por enlace; confirmaciones de formulario 4000 ms por formulario/origen; WPForms no AJAX usa cookie de ID sin datos personales.
6. Los tests automáticos prueban nombres y parámetros, consentimiento, bloqueo con legado, WPForms AJAX y sin AJAX, Elementor, CF7, HTML éxito explícito, contactos persistidos y ausencia de PII en el `dataLayer`.
7. Los eventos existentes `wpforms_submit` / `form_submit` de Actividad de TIBOX se mantienen sin alteraciones.

## Revisión GTM/GA4/Ads imprescindible antes del corte

- Identificar en **cada sitio** el contenedor GTM publicado, etiquetas activas y variables, incluidos disparadores que dependan de `tbx_generate_lead`, `wpforms_submit`, `form_submit`, `generate_lead`, `submit_lead_form`, `tbx_whatsapp_click`, `whatsapp_click` y clics nativos `tel:` o `mailto:`.
- En **Prodata**, comprobar cuál contenedor está publicado (referencias de proyecto: **GTM-WD438JB3** nuevo TIBOX y **GTM-TXJ2D85G** legacy Carlos); comprobar si ambos o algún snippet externo siguen enviando eventos. No asumir que los IDs de documentación son los activos sin acceso real.
- En **TIBOX**, comprobar GTM-WQVDMTC, GA4 G-MSX22P7KJ1 y la configuración actual de las etiquetas. No alterar ninguna medición ya existente en Actividad ni conversiones de Ads sin comparación.
- Hacer copia/export de contenedores antes de tocar etiquetas. Capturar línea base de un clic WA y un lead por formulario: *evento dataLayer → activador → etiqueta → DebugView GA4 / diagnóstico Google Ads*, con ID del formulario y ruta.
- Para cada formulario de Prodata, garantizar que **una acción real produce como máximo una conversión de GA4/Ads**, aunque se produzcan múltiples eventos auxiliares. Preferir un único disparador comercial como fuente de verdad.
- En DebugView comprobar que `form_start` no esté marcado como conversión; las conversiones deben depender de éxito confirmado. Las conversiones Ads pueden atribuirse más tarde y deben verificarse por diagnóstico, no exigir aparición instantánea en interfaz de Ads.
- Separar eventos de clic de mensajes realmente enviados. Nunca contar `tbx_whatsapp_click` automáticamente como venta o lead confirmado sin el criterio comercial acordado.
- Conservar consentimiento vigente (CMP/Consent Mode, GPC). Las pruebas deben incluir un visitante sin sesión y las condiciones de consentimiento reales.
- Registrar para ambos sitios evidencia de captura del evento, tags disparadas y ausencia de duplicados; tras ello habilitar el toggle de compatibilidad **solo si el GTM auditado lo necesita**.

## Integración de formularios HTML a medida

La señal de éxito opcional es:

```js
// Ejecutar SOLO cuando el backend haya confirmado que aceptó la consulta.
const form = document.querySelector('form[data-tbx-track-form]');
if (form) {
  document.dispatchEvent(new CustomEvent('tbx:form-success', {
    detail: {
      form,
      formId: form.id || form.dataset.tbxTrackForm,
      formName: form.dataset.tbxName || ''
    }
  }));
}
```

Esto emite el evento GTM de compatibilidad si la opción está activa, pero **NO guarda el cuerpo del formulario** por sí solo. Si la landing necesita también conservar sus leads en Contactos, integrar una ruta de ingreso segura y auditar los campos y su consentimiento. No prometer equivalencia de formularios HTML genéricos hasta inventariarlos.

## Criterios para aceptar desactivar TIBOX Leads en Prodata

- Histórico comparado y respaldado; botón único; clic de WhatsApp con referencia visible, sin referencias falsas en acortadores; un contacto por clic.
- Envió correcto de WPForms, Elementor, CF7 y cada landing real; correo de notificación sigue funcionando; sin cambio de origen, campañas o rutas relevantes.
- Eventos anteriores y etiquetas GTM activan correctamente su equivalente o se remapean; conversiones de GA4/Ads únicas.
- Cambio primero en staging, luego producción con rollback inmediato (activar plugin anterior y apagar capturas nativas). **No eliminar datos ni tablas del plugin anterior.**

**Estado:** matriz técnica y código preparados; falta inspección de GTM real, aceptación funcional y prueba de producción. No se garantiza paridad real solo con GitHub Actions.


### Prearmado y continuidad de medición (1.10.4 beta)

La opción `tbx_contacts_native_enabled` puede guardarse como `1` mientras TIBOX Leads sigue activo. Aun así `can_capture()` retorna false cuando detecta `tbx_leads_insert`, y no se registran formularios ni se encola el JS de compatibilidad GTM. Al desactivar el antiguo, la nueva captura ya preparada comienza a operar sin esperar una configuración posterior. Es un cambio **explícito y reversible** para staging, no una autorización a cambiar la instalación real.


## Validaciones adicionales — 1.10.4 beta

- **Dependencia de jQuery:** declarada expresamente en los dos módulos que oyen los eventos AJAX de WPForms/Elementor; no dependemos de que otro tema/plugin lo cargue antes.
- **Evento nativo `wpforms_submit`:** conserva nombre y parámetros habituales de TIBOX Admin, pero evita un segundo `wpforms_submit` por el mismo callback exitoso duplicado. Resuelve referencias al formulario desde event.target o argumentos adicionales, sin atribuir el envío a un formulario inventado.
- **Evento heredado `tbx_generate_lead`:** reconoce un elemento de formulario jQuery cuando WPForms lo entrega en argumentos extras y un `event.target` asociado al documento. La deduplicación es de dos segundos por elemento para no perder una segunda consulta legítima, incluso cuando dos formularios comparten etiquetas.
- **WPForms sin AJAX:** el hook oficial `wpforms_process_complete` se ejecuta tras un procesamiento correcto de WPForms; la compatibilidad de conversión no se pierde si falla la inserción secundaria en Contactos. El fallo de almacenamiento se registra como incidencia separada.
- **Persistencia de Contactos:** una inserción fallida puede reintentarse en la misma solicitud porque no se marca como duplicado antes del guardado exitoso.
- **QA automatizada:** pruebas de callback documento/formulario, evento sin formulario identificable, segundo envío después de dos segundos, duplicados, fallo temporal de BD, WPForms Lite/no AJAX y exclusión mutua con TIBOX Leads.
- **No equivale a GTM validado:** siguen pendientes los contenedores publicados, DebugView y la comprobación de acciones de Ads en cada sitio.
