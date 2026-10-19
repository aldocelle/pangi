<?php

/*
 * Corpus del chat sin IA: cómo escribe la gente y qué DEBERÍA pasar.
 *
 * `esperado` es el prefijo del motivo (lo de antes de «:») o `deriva` (que no
 * conteste nada: va a WhatsApp, y mañana a la IA). Una respuesta donde se
 * esperaba `deriva` es una RESPUESTA EQUIVOCADA — lo peor que puede hacer esta
 * capa, porque sale con cara de dato. Un `deriva` donde se esperaba respuesta
 * es solo trabajo que queda para una persona.
 *
 * Escrito a mano, en chileno de chat (sin tildes, con faltas), a partir de las
 * preguntas reales de `chatbot_preguntas` y de lo que atiende la recepción.
 * Agregar casos es barato: cada uno que falla es un defecto medido.
 */
return [
    // ── Las opciones del saludo de ChatAsistente.vue ──────────────────────
    // Tal cual las manda cada botón. Un botón que termina en «escríbenos por
    // WhatsApp» promete algo que no cumple: si se agrega uno allá, va acá.
    ['Quiero reservar una hora', 'reservar'],
    ['¿Qué valores tienen?', 'precio'],
    ['¿Qué servicios tienen?', 'catalogo'],
    ['¿Dónde están ubicados?', 'lugar'],
    ['Quiero cambiar o cancelar mi hora', 'mis_citas'],

    // ── Reservar y la propia hora ─────────────────────────────────────────
    ['quiero una hora', 'reservar'],
    ['reservar', 'reservar'],
    ['hola quiero agendar una hora para mi perrita', 'reservar'],
    ['necesito hora con dermatologo', 'reservar'],
    ['tienen hora para mañana?', 'reservar'],
    ['hay hora hoy para corte de uñas', 'reservar'],
    ['quiero reservar peluqueria para el sabado', 'reservar'],
    ['agendar ecografia', 'reservar'],
    ['quiero cancelar mi hora', 'mis_citas'],
    ['necesito cambiar la hora de mañana', 'mis_citas'],
    ['a que hora es mi cita', 'mis_citas'],
    ['se puede reagendar?', 'mis_citas'],
    ['como pago la reserva', 'deriva'],

    // ── Precios ───────────────────────────────────────────────────────────
    ['cuanto cuesta la consulta general', 'precio'],
    ['cuanto sale la consulta dermatologica', 'precio'],
    ['que valores tienen?', 'precio'],
    ['valores de consultas', 'precio'],
    ['precios', 'precio'],
    ['cuanto cobran por una radiografia', 'precio'],
    ['valor ecografia', 'precio'],
    ['cuanto sale el corte de uñas', 'precio'],
    ['cuanto vale la peluqueria', 'precio'],
    ['precio consulta a domicilio', 'precio'],
    ['cuanto cuesta la telemedicina', 'precio'],
    ['cuanto cobra el dermatologo', 'precio'],
    ['cuanto cuesta la consulta para mi perro', 'precio'],
    ['cuanto sale el sabado la consulta general', 'precio'],
    ['cuanto cuesta un examen de sangre', 'precio'],
    ['cuanto cuesta esterilizar a mi gata', 'deriva'],
    ['cuanto cuesta la vacuna antirrabica', 'deriva'],

    // ── Qué se atiende ────────────────────────────────────────────────────
    ['que servicios tienen', 'catalogo'],
    ['que atienden?', 'catalogo'],
    ['hacen ecografias?', 'servicio'],
    ['tienen peluqueria?', 'servicio'],
    ['hacen radiografias', 'servicio'],
    ['atienden gatos?', 'deriva'],
    ['atienden exoticos', 'deriva'],
    ['tienen hospitalizacion', 'deriva'],

    // ── Dónde, cuándo, contacto ───────────────────────────────────────────
    ['donde quedan', 'lugar'],
    ['direccion?', 'lugar'],
    ['como llego a la clinica', 'lugar'],
    ['a que hora abren', 'deriva'],          // horario vacío hasta que la oficina lo escriba
    ['atienden los domingos?', 'deriva'],
    ['cual es su telefono', 'telefono'],
    ['tienen whatsapp?', 'telefono'],
    ['numero de contacto', 'telefono'],
    ['mi fono es 912345678 y quiero saber si atienden conejos', 'deriva'],
    ['mi numero es +56 9 1234 5678', 'deriva'],

    // ── Saludo y cortesía ─────────────────────────────────────────────────
    ['hola', 'saludo'],
    ['buenas tardes', 'saludo'],
    ['ayudame', 'saludo'],
    ['ayuda', 'saludo'],
    ['gracias', 'cortesia'],
    ['muchas gracias!', 'cortesia'],
    ['hola buenas, mi perro esta vomitando', 'deriva'],

    // ── Siempre una persona ───────────────────────────────────────────────
    ['mi perro esta vomitando sangre', 'deriva'],
    ['mi gato no come hace dos dias que hago', 'deriva'],
    ['es urgente, lo atropellaron', 'deriva'],
    ['que le doy para las pulgas', 'deriva'],
    ['puedo darle paracetamol a mi perro', 'deriva'],
    ['cuanto cuesta la consulta general, mi perro esta vomitando', 'deriva'],
    ['tienen disponible apoquel?', 'deriva'],
    ['venden royal canin?', 'deriva'],
    ['quiero hablar con una persona', 'deriva'],
    ['me llego un cobro doble', 'deriva'],
    ['ya pague el abono y no me confirma', 'deriva'],
    ['cancelo en efectivo?', 'deriva'],
    ['puedo pagar con tarjeta?', 'deriva'],
    ['resultados de examenes de mi perro', 'deriva'],
    ['la doctora carla atiende los sabados?', 'deriva'],
];
