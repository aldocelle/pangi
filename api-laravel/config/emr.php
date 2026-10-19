<?php

/**
 * Rutas de los archivos subidos.
 *
 * **Salen de configuración, NUNCA de `__DIR__`** (§9.16). En Python estuvieron
 * recalculadas a mano en tres archivos con tres expresiones distintas de
 * `os.path.dirname`, y por eso ninguna podía seguir el montaje de un volumen.
 *
 * Los dos directorios son **hermanos y SEPARADOS**, y eso no es organización:
 * `uploads/` se sirve estático y sin autenticación (fotos de especialistas),
 * mientras que `documentos_clinicos/` no lo monta nadie. Juntarlos vuelve
 * descargable una radiografía sin token — ya pasó, comprobado el 2026-08-20.
 */

$datos = env('DATOS_DIR') ?: base_path();

return [
    'datos_dir' => $datos,
    'uploads_dir' => $datos . '/uploads',
    'documentos_dir' => $datos . '/documentos_clinicos',

    /*
     * Los DEFAULTS viven acá, igual que en `app/config.py` de Python, y no
     * solo en el `.env`: `CLINICA_TELEFONO` está comentada en el entorno real
     * y su valor sale del código. Sin replicarlo, el teléfono y el enlace de
     * reagendar salían VACÍOS en la descripción del evento de Google —lo
     * encontró el modo C del arnés, comparando el texto que se envía.
     *
     * `rtrim` de la barra final por lo mismo que Python: el enlace se arma
     * concatenando "/pago/..." y una barra doble rompe la URL.
     */
    'portal_reservas_url' => rtrim(
        (string) (env('PORTAL_RESERVAS_URL') ?: 'http://localhost:3101'), '/'),

    /*
     * Identidad de la clínica en los textos que salen (correos, WhatsApp,
     * respaldos, asistente). `App\Services\Marca` lee el nombre; la dirección
     * vacía cae en `CitasEmail::DIRECCION_CLINICA`.
     */
    'clinica_nombre' => env('CLINICA_NOMBRE') ?: 'PANGI',

    'clinica_direccion' => env('CLINICA_DIRECCION') ?: null,

    'clinica_telefono' => env('CLINICA_TELEFONO') ?: '+569 0000 0000',

    /*
     * ── Los correos que se mandan solos ──────────────────────────────────
     *
     * Apaga el ENVÍO de los dos cron que escriben a clientes sin que nadie
     * haga nada: `recordatorios:citas` y `avisos:preventivos`. Espejo de
     * `CORREOS_AUTOMATICOS` en `app/config.py` de Python.
     *
     * NO cubre los transaccionales —confirmación de reserva, reagendar,
     * cancelar, aviso al especialista, confirmación de cuenta, reset—: a esos
     * los dispara una persona con un acto explícito y salen siempre.
     *
     * **Apaga el envío, NO el comando.** `recordatorios:citas` arrastra el
     * barrido de reservas sin pagar, que no manda ningún correo y es lo único
     * que libera el cupo de una hora que nadie pagó; saltarse el comando
     * entero la dejaría tomada para siempre. En Python se cometió ese error y
     * hubo que corregirlo — acá va derecho desde el principio.
     *
     * Por defecto APAGADO, por asimetría del error: olvidarlo encendido manda
     * correo real y un correo enviado no se recupera; olvidarlo apagado solo
     * atrasa un recordatorio. Mismo criterio que el `NOT NULL DEFAULT FALSE`
     * de `migrate_avisos_especialista.py`.
     *
     * ⚠️ **Al registrar los tres cron en el corte (Fase 5) hay que decidir
     * esto a conciencia**: sin la variable en `true` no sale ningún
     * recordatorio, y con ella salen todos desde el primer minuto.
     *
     * `filter_var` y NO `(string) env(...) === 'true'`: **`env()` de Laravel
     * castea solo**, así que con `CORREOS_AUTOMATICOS=true` en el `.env`
     * devuelve el booleano `true`, y `(string) true` es `"1"`, que nunca es
     * igual a `"true"`. Ese idiom deja el interruptor apagado para siempre,
     * pase lo que pase en el entorno. Es un modo de falla silencioso —parece
     * configurado y no lo está— y ya estaba en el port (§ el mismo arreglo en
     * `AvisosPreventivos`).
     */
    'correos_automaticos' => filter_var(env('CORREOS_AUTOMATICOS', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Correos de quienes son sysop, separados por comas (`App\Services\Sysop`).
     * Vacío = nadie: falla cerrado. Se normaliza a minúsculas y sin vacíos, para
     * que un espacio o una coma de más no deje a alguien fuera ni meta a otro.
     */
    'sysop_emails' => array_values(array_filter(array_map(
        fn ($c) => mb_strtolower(trim($c)),
        explode(',', (string) env('SYSOP_EMAILS', ''))
    ))),

    /*
     * ── La IA del chat (AgenteIA) ────────────────────────────────────────
     *
     * Hacen falta LAS DOS: `ASISTENTE_IA_ACTIVO=true` y la clave. En Python la
     * clave sola la encendía, y esa era la trampa del §4 — cargar una clave
     * para probar algo encendía el asistente para todos.
     *
     * Default APAGADO, al revés que `app/config.py`, y eso sí es un cambio con
     * hecho nuevo: el port nunca tuvo IA, nadie en producción depende de que
     * nazca encendida, y `migracion/env_produccion.py` no escribe la variable
     * — con default `true`, el primer `.env` que trajera una clave encendería
     * el asistente sin que nadie lo decidiera.
     *
     * `asistente_tokens_dia`: tope DIARIO (entrada + salida), 0 = sin tope.
     * `/chatbot/mensaje` es público; el límite por IP no acota a varias IP.
     * Es solo el valor de PARTIDA: lo guardado en Ajustes → Asistente IA le
     * gana (ver UsoAsistente::tope). 1.000.000 y no los 300.000 de Python: el
     * agente de WhatsApp gasta varias vueltas por mensaje y 300.000 se acabó a
     * las 18:36 de un día de pruebas (29-sep-2026). Con Haiku son ~US$1–2.
     */
    'asistente_ia_activo' => filter_var(env('ASISTENTE_IA_ACTIVO', false), FILTER_VALIDATE_BOOLEAN),
    'anthropic_api_key' => (string) (env('ANTHROPIC_API_KEY') ?: ''),
    // Solo para claves que no están atadas a un workspace: sin él, Anthropic
    // responde 400 «must include the anthropic-workspace-id header».
    'anthropic_workspace_id' => env('ANTHROPIC_WORKSPACE_ID') ?: null,
    // Haiku 4.5 por costo ($1/$5 por millón de tokens, contra $5/$25 de Opus 5):
    // el chat es de alto volumen y el trabajo real lo validan las herramientas.
    'asistente_ia_modelo' => (string) (env('ASISTENTE_IA_MODELO') ?: 'claude-haiku-4-5'),
    'asistente_tokens_dia' => (int) (env('ASISTENTE_TOKENS_DIA') ?? 1000000),

    /*
     * ── WhatsApp Cloud API (WhatsappController + AtenderWhatsapp) ─────────
     *
     * Leídas desde config y no con `env()` en el controlador: con
     * `php artisan config:cache`, `env()` fuera de config/ devuelve null y el
     * webhook rechazaría TODO con 403 sin que nada pareciera mal configurado.
     *
     * Sin `whatsapp_access_token` o `whatsapp_phone_number_id` no se envía nada
     * (WhatsappEnvio::habilitado): el webhook sigue validando firmas y
     * descartando, igual que antes de existir el envío.
     *
     * `whatsapp_graph_version`: Meta retira cada versión unos dos años después
     * de publicarla. Es variable para subirla sin tocar código el día que avise.
     */
    'whatsapp_verify_token' => (string) (env('WHATSAPP_VERIFY_TOKEN') ?: ''),
    'whatsapp_app_secret' => (string) (env('WHATSAPP_APP_SECRET') ?: ''),
    'whatsapp_access_token' => (string) (env('WHATSAPP_ACCESS_TOKEN') ?: ''),
    'whatsapp_phone_number_id' => (string) (env('WHATSAPP_PHONE_NUMBER_ID') ?: ''),
    'whatsapp_graph_version' => (string) (env('WHATSAPP_GRAPH_VERSION') ?: 'v23.0'),

    /*
     * Plantilla aprobada por Meta para preguntarle a un especialista por sus
     * horarios cuando un tutor pide hora con él (IntermediarioEspecialista::abrir).
     * Cinco parámetros de cuerpo, en orden: especialista, consulta, mascota,
     * motivo, N° de solicitud. Nunca el número del tutor: el asistente hace de
     * intermediario. Vacía, se manda texto libre, que Meta solo entrega si el
     * especialista le escribió al número en las últimas 24 h.
     */
    'whatsapp_plantilla_especialista' => (string) (env('WHATSAPP_PLANTILLA_ESPECIALISTA') ?: ''),
    'whatsapp_plantilla_idioma' => (string) (env('WHATSAPP_PLANTILLA_IDIOMA') ?: 'es'),
];
