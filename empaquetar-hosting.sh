#!/usr/bin/env bash
# Arma los paquetes para publicar la demo en un hosting compartido con PHP 8.3+
# y MySQL 8 / MariaDB (cPanel, hPanel o similar).
#
#   ./empaquetar-hosting.sh midominio.cl
#
# Supone cuatro direcciones (se pueden cambiar con variables de entorno):
#   sitio      https://midominio.cl               SITIO_URL
#   reservas   https://reservas.midominio.cl      RESERVAS_URL
#   panel      https://panel.midominio.cl         PANEL_URL
#   API        https://api.midominio.cl           API_URL
#
# Deja en paquetes/ los cuatro .zip, la base (.sql), el .env de la API y un
# LEEME con los pasos. Toma los datos de la base local de la demo, así que
# conviene correr antes ./iniciar-demo.sh --refrescar (datos con fecha de hoy).
set -euo pipefail
cd "$(dirname "$0")"
RAIZ="$PWD"
DOM="${1:-}"
[ -n "$DOM" ] || { echo "Uso: ./empaquetar-hosting.sh midominio.cl"; exit 1; }
SITIO_URL="${SITIO_URL:-https://$DOM}"
RESERVAS_URL="${RESERVAS_URL:-https://reservas.$DOM}"
PANEL_URL="${PANEL_URL:-https://panel.$DOM}"
API_URL="${API_URL:-https://api.$DOM}"
NOMBRE="${CLINICA_NOMBRE:-PANGI}"
SALIDA="$RAIZ/paquetes"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

azul() { printf '\033[1;34m%s\033[0m\n' "$*"; }
ok() { printf '  ✓ %s\n' "$*"; }
mal() { printf '\033[1;31m  ✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(docker inspect -f '{{.State.Health.Status}}' pangi-demo-db 2>/dev/null)" = healthy ] \
  || mal "La base local no está corriendo: levanta la demo (./iniciar-demo.sh) y vuelve a intentar."
rm -rf "$SALIDA"; mkdir -p "$SALIDA"

# ── Frontends ────────────────────────────────────────────────────────────────
# Las VITE_* se hornean en el build: las de la línea de comandos le ganan al
# .env de cada frontend (que apunta a localhost).
construir() {
  local carpeta="$1" zip="$2"
  azul "Compilando $carpeta…"
  (cd "$RAIZ/$carpeta" && \
    VITE_API_URL="$API_URL" VITE_SITIO_URL="$SITIO_URL" VITE_RESERVA_URL="$RESERVAS_URL" \
    VITE_CLINICA_NOMBRE="$NOMBRE" npx vite build --outDir "$TMP/$carpeta" --emptyOutDir --logLevel error)
  if grep -rlq "localhost:" "$TMP/$carpeta/assets"; then mal "$carpeta quedó apuntando a localhost"; fi
  ok "sin direcciones locales en el bundle"
  (cd "$TMP/$carpeta" && zip -rq "$SALIDA/$zip" . -x '*.DS_Store')
  ok "$zip ($(du -h "$SALIDA/$zip" | cut -f1))"
}
construir website 2-sitio.zip
construir portal-reservas 3-reservas.zip
construir portal-admin-clinica 4-panel.zip

# ── API ──────────────────────────────────────────────────────────────────────
# Por lista blanca y no con exclusiones: lo que se olvida no se sube, y lo que
# más importa que no se suba es el .env.
azul "Empaquetando la API…"
API="$TMP/api"; mkdir -p "$API"
for x in app bootstrap config database public resources routes storage artisan composer.json composer.lock; do
  cp -R "$RAIZ/api-laravel/$x" "$API/"
done
rm -f "$API"/bootstrap/cache/*.php "$API"/.env "$API"/.env.*
find "$API/storage" -type f ! -name .gitignore -delete
mkdir -p "$API/uploads" "$API/documentos_clinicos"
cp "$RAIZ"/api-laravel/documentos_clinicos/*.pdf "$API/documentos_clinicos/" 2>/dev/null || true
# Si la carpeta de la app queda alcanzable desde el dominio principal, que no
# entregue nada: el subdominio de la API apunta a public/, que está más abajo.
cat > "$API/.htaccess" <<'HT'
# La API se sirve desde public/. Esta carpeta no se entrega por la web.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HT
(cd "$API" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
[ -f "$API/vendor/autoload.php" ] || mal "falta vendor/autoload.php"
[ ! -e "$API/.env" ] || mal ".env se coló en el paquete"
ok "vendor de producción, sin .env, con $(ls "$API/documentos_clinicos" | wc -l | tr -d ' ') adjuntos de demo"
(cd "$API" && zip -rq "$SALIDA/1-api.zip" . -x '*.DS_Store')
ok "1-api.zip ($(du -h "$SALIDA/1-api.zip" | cut -f1))"

# ── .env de la API ───────────────────────────────────────────────────────────
azul "Generando el .env de la API…"
php -r '
  [$_, $api, $sitio, $reservas, $panel, $nombre, $salida] = $argv;
  $r = fn ($n) => bin2hex(random_bytes($n));
  $env = <<<ENV
APP_NAME=$nombre
APP_ENV=production
APP_KEY=base64:%s
APP_DEBUG=false
APP_URL=$api
APP_TIMEZONE=America/Santiago
APP_LOCALE=es

CLINICA_NOMBRE=$nombre
CLINICA_TELEFONO="+569 0000 0000"
CLINICA_DIRECCION="Av. Principal 1234, local 5, Puente Alto"

# Completar con los datos de la base que crees en el panel del hosting.
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=COMPLETAR
DB_USERNAME=COMPLETAR
DB_PASSWORD=COMPLETAR

JWT_SECRET=%s
TOKEN_ENCRYPTION_KEY=%s
SERVICE_API_KEY=%s
FICHAJE_DEVICE_TOKEN=%s

PORTAL_RESERVAS_URL=$reservas
ORIGENES_PRODUCCION=$sitio,$reservas,$panel

# Demo: nada sale solo, sin IA ni reCAPTCHA, y el abono se paga simulado.
CORREOS_AUTOMATICOS=false
RECORDATORIOS_AVISO_AUTOMATICO=false
ASISTENTE_IA_ACTIVO=false
RECAPTCHA_DESACTIVADO=true
PAGO_SIMULADO=true
SYSOP_EMAILS=admin@pangi.demo
CONTACTO_NOTIFICAR_A=

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

ENV;
  file_put_contents($salida, sprintf($env, base64_encode(random_bytes(32)), $r(32),
    strtr(base64_encode(random_bytes(32)), "+/", "-_"), $r(24), $r(16)));
' "$API_URL" "$SITIO_URL" "$RESERVAS_URL" "$PANEL_URL" "$NOMBRE" "$SALIDA/api.env"
chmod 600 "$SALIDA/api.env"
ok "api.env (con claves nuevas; faltan solo los datos de la base)"

# ── Base de datos ────────────────────────────────────────────────────────────
# Volcado de la base local, ajustado para que cargue igual en MySQL 8 y MariaDB.
azul "Volcando la base de la demo…"
docker exec pangi-demo-db mariadb-dump -upangi -ppangi_local --single-transaction --skip-comments \
  --skip-dump-date --default-character-set=utf8mb4 pangi_demo 2>/dev/null \
  | grep -v '^/\*M!999999' \
  | sed -e "s/DEFAULT '{}' CHECK/DEFAULT ('{}') CHECK/" -e "s/DEFAULT '\[\]' CHECK/DEFAULT ('[]') CHECK/" \
  > "$SALIDA/5-base-de-datos.sql"
grep -q "INSERT INTO \`usuarios\`" "$SALIDA/5-base-de-datos.sql" || mal "el volcado salió sin datos"
ok "5-base-de-datos.sql ($(du -h "$SALIDA/5-base-de-datos.sql" | cut -f1))"

# ── Instrucciones ────────────────────────────────────────────────────────────
sed -e "s#{SITIO}#$SITIO_URL#g" -e "s#{RESERVAS}#$RESERVAS_URL#g" -e "s#{PANEL}#$PANEL_URL#g" \
    -e "s#{API}#$API_URL#g" "$RAIZ/docs/LEEME-HOSTING.md" > "$SALIDA/LEEME-HOSTING.md"
echo
azul "Listo: $SALIDA"
ls -1 "$SALIDA"
echo
echo "Sigue los pasos de paquetes/LEEME-HOSTING.md. El api.env tiene claves: no lo compartas."
