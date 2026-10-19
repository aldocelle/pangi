# CRM / ERP Veterinario — demo PANGI

Sistema completo para una clínica veterinaria, listo para presentar. Viene con
una clínica ficticia, **PANGI**, que parece en uso: 100 clientes, unas 140
mascotas, fichas clínicas, un año de ventas, caja, inventario, agenda con citas
de hoy y de las próximas semanas.

Todo es inventado: nombres, RUT (rango 33 millones), teléfonos `+56 9 0000 …`
y correos `@correo.test` / `@pangi.demo`, que no llegan a nadie.

## Qué incluye

| Carpeta | Qué es | Local |
|---|---|---|
| `api-laravel/` | API (Laravel 13, PHP 8.3+, MySQL/MariaDB): toda la lógica y los datos | http://localhost:8100 |
| `website/` | Sitio público de la clínica | http://localhost:3100 |
| `portal-reservas/` | Reserva de horas sin cuenta + portal del cliente (mascotas, ficha, citas, pagos) | http://localhost:3101 |
| `portal-admin-clinica/` | Panel del equipo: agenda, fichas, clientes, caja, ventas, inventario, compras, recordatorios, estadísticas, asistencia | http://localhost:3102 |
| `base/esquema.sql` | Estructura de la base (39 tablas), compatible con MySQL 8 y MariaDB | — |

## Arrancar en este Mac

Requisitos: Docker Desktop abierto, PHP 8.3+, Composer y Node 20+.

```bash
./iniciar-demo.sh
```

La primera vez instala las dependencias, crea la base y genera los datos de
demo (alrededor de un minuto). Las veces siguientes arranca en segundos.
`Ctrl+C` lo detiene todo.

Los datos se generan con la fecha del día. Para mostrar la agenda de «hoy»
otro día, o para volver la demo a cero después de una presentación:

```bash
./iniciar-demo.sh --refrescar
```

### Cuentas del panel (clave `demo1234`)

| Correo | Rol |
|---|---|
| `admin@pangi.demo` | Administración: ve todo, incluida la auditoría |
| `marta.soto@pangi.demo` | Directora médica: todo lo operativo |
| `tomas.fuentes@pangi.demo` | Veterinario: agenda, reservas, fichas, tareas |
| `recepcion@pangi.demo` | Recepción: agenda, reservas, clientes, caja, ventas, recordatorios |

Portal del cliente: `cliente@pangi.demo` (clave `demo1234`), una tutora con
dos mascotas, atenciones anteriores y horas próximas. Las demás cuentas de
clientes tienen claves que nadie conoce.

## Publicar en un hosting

Para un hosting compartido con PHP 8.3+ y MySQL (cPanel, hPanel o similar):

```bash
./iniciar-demo.sh --refrescar        # datos con fecha de hoy (en otra terminal)
./empaquetar-hosting.sh midominio.cl
```

Deja en `paquetes/` la API, los tres frontends, la base y el `.env` de la API,
más `LEEME-HOSTING.md` con los pasos. Supone `midominio.cl` para el sitio y los
subdominios `reservas.`, `panel.` y `api.`. En el hosting el pago del abono
queda en modo simulado (`PAGO_SIMULADO=true`): se recorre el flujo entero sin
pasarela.

## Adaptarlo a otra clínica

- **Nombre**: `CLINICA_NOMBRE` en `api-laravel/.env` (correos, WhatsApp,
  recibos, asistente) y `VITE_CLINICA_NOMBRE` en el `.env` de cada frontend.
- **Contacto del sitio y del portal**: `website/src/config.js` y
  `portal-reservas/src/config.js` (dirección, teléfono, correo, horario, redes).
- **Logo**: `logo.svg` (completo) e `isotipo.svg` (solo símbolo) en `public/`
  de cada frontend.
- **Servicios, equipo, horarios, precios y textos**: desde el panel, en
  Ajustes. Los de la demo los crea `php artisan demo:configurar`.
- **Imágenes**: qué archivo va en cada pantalla y cómo cambiar los retratos, en
  [`docs/IMAGENES.md`](docs/IMAGENES.md).

## Integraciones

Vienen apagadas y el sistema funciona completo sin ellas. Cada una se enciende
con sus credenciales en `api-laravel/.env`:

| Integración | Para qué | Variables |
|---|---|---|
| Getnet (Santander) | Cobro del abono al reservar | `GETNET_LOGIN`, `GETNET_SECRET_KEY`, `GETNET_BASE_URL` |
| Google | Calendar por especialista e «Ingresar con Google» | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| Correo | Confirmaciones, recordatorios, recuperación de clave | `SMTP_*` o `RESEND_API_KEY` |
| WhatsApp Cloud API | Asistente por WhatsApp | `WHATSAPP_*` |
| Anthropic | Respuestas con IA del asistente | `ANTHROPIC_API_KEY`, `ASISTENTE_IA_ACTIVO=true` |

Los correos que salen solos (recordatorios y avisos preventivos) están
apagados con `CORREOS_AUTOMATICOS=false`, para que una demo nunca le escriba a
nadie.

## Tests

```bash
cd api-laravel && php vendor/bin/phpunit
```

598 tests, en SQLite en memoria: no tocan la base de la demo.
