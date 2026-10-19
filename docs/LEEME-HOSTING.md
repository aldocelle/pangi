# Publicar la demo en el hosting

Direcciones de este paquete:

| Pieza | Dirección | Paquete |
|---|---|---|
| API | {API} | `1-api.zip` + `api.env` |
| Sitio web | {SITIO} | `2-sitio.zip` |
| Reservas y portal del cliente | {RESERVAS} | `3-reservas.zip` |
| Panel de la clínica | {PANEL} | `4-panel.zip` |
| Base de datos | — | `5-base-de-datos.sql` |

Requisitos del hosting: **PHP 8.3 o superior** (para la API), MySQL 8 o
MariaDB 10.6+, y certificado SSL en los cuatro dominios. Si alguna dirección
cambió, se vuelve a correr `./empaquetar-hosting.sh` con las nuevas: las
direcciones quedan grabadas dentro de los `.zip`.

## 1. Dominios

En el panel del hosting, crear los subdominios `api`, `reservas` y `panel`.

- El de **api** tiene que apuntar a la carpeta **`api/public`** (no a `api/`).
  Es lo que impide que el `.env` y el código queden visibles por la web.
- `reservas` y `panel`, cada uno a su carpeta.
- El sitio va en la carpeta del dominio principal (`public_html`).

Activar SSL (Let's Encrypt) en los cuatro.

Si la API o reservas van en una carpeta del sitio (`{SITIO}/api`,
`{SITIO}/reservas`), no necesitan subdominio ni certificado propio: basta con
descomprimirlas en las carpetas `api/` y `reservas/` de la carpeta del sitio. El
`.htaccess` de `api/` hace pasar todo por su `index.php` y no entrega ningún
archivo de esa carpeta.

## 2. Base de datos

1. Crear una base y un usuario con todos los permisos sobre ella. Conviene que
   el nombre de la base termine en `_demo`: así se pueden rehacer los datos en
   el servidor (punto 6).
2. En phpMyAdmin, entrar a esa base → Importar → `5-base-de-datos.sql`.

## 3. API

1. Subir `1-api.zip` a la carpeta `api/` y descomprimirlo ahí.
2. Subir `api.env` a la misma carpeta, renombrarlo a **`.env`** y completar
   `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` con los del paso 2.
   Permisos `600` si el panel lo permite.
3. Elegir **PHP 8.3+** para el subdominio `api`.
4. Comprobar: {API}/health tiene que responder `{"status":"ok"}`.

Si `storage/` o `bootstrap/cache/` dan error de permisos, dejarlos en `775`.

## 4. Frontends

Descomprimir cada `.zip` en la carpeta de su dominio:

- `2-sitio.zip` → carpeta del dominio principal
- `3-reservas.zip` → carpeta de `reservas`
- `4-panel.zip` → carpeta de `panel`

Cada uno trae su `.htaccess` (rutas del SPA y cabeceras de seguridad). Después
de descomprimir, borrar el `.zip` del servidor.

## 5. Probar

- {PANEL} → `admin@pangi.demo` / `demo1234`
- {RESERVAS} → reservar una hora; el abono se «paga» con el pago simulado.
- {SITIO} → servicios y equipo cargan desde la API.

Si el sitio o el panel dicen «No pudimos conectar con el servidor», casi
siempre es el `.env`: revisar `ORIGENES_PRODUCCION` (tiene que listar las tres
direcciones de los frontends, con `https://`) y los datos de la base.

## 6. Datos con la fecha de hoy (opcional)

Los datos de la demo tienen la fecha del día en que se generaron: con el paso
de los días la agenda de «hoy» se vacía. Para rehacerlos en el servidor (solo si
la base termina en `_demo`), por SSH o con un cron diario a las 6:00:

```
cd ~/public_html/api && php artisan demo:configurar --confirmar=NOMBRE_DE_LA_BASE && php artisan demo:sembrar --confirmar=NOMBRE_DE_LA_BASE --sin-red
```

Borra todo lo que se haya hecho en la demo y deja la clínica como nueva.
