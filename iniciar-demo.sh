#!/usr/bin/env bash
# Arranca la demo completa en este equipo: base de datos (Docker), API
# (Laravel) y los tres frontends (Vite). Ctrl+C lo detiene todo.
#
#   ./iniciar-demo.sh              arranca (la primera vez instala y carga los datos)
#   ./iniciar-demo.sh --refrescar  rehace los datos de demo con la fecha de hoy
#
# Requisitos: Docker Desktop abierto, PHP 8.2+, Composer, Node 20+.
set -euo pipefail
cd "$(dirname "$0")"
RAIZ="$PWD"
LOGS="$RAIZ/.demo-logs"
MARCA_FECHA="$RAIZ/.datos-demo-fecha"
BASE=pangi_demo

azul() { printf '\033[1;34m%s\033[0m\n' "$*"; }
rojo() { printf '\033[1;31m%s\033[0m\n' "$*" >&2; }
sql() { docker exec -i pangi-demo-db mariadb -upangi -ppangi_local "$@"; }

REFRESCAR=0
[ "${1:-}" = "--refrescar" ] && REFRESCAR=1

# ── Requisitos ───────────────────────────────────────────────────────────────
for c in docker php composer node npm; do
  command -v "$c" >/dev/null || { rojo "Falta «$c». Instálalo y vuelve a intentar."; exit 1; }
done
docker info >/dev/null 2>&1 || { rojo "Docker no está corriendo: abre Docker Desktop y vuelve a intentar."; exit 1; }
for p in 8100 3100 3101 3102; do
  if lsof -iTCP:"$p" -sTCP:LISTEN >/dev/null 2>&1; then
    rojo "El puerto $p ya está en uso (¿quedó otra demo abierta?). Ciérrala y vuelve a intentar."; exit 1
  fi
done

# ── Base de datos ────────────────────────────────────────────────────────────
azul "Base de datos…"
docker compose up -d db >/dev/null
for _ in $(seq 1 60); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' pangi-demo-db 2>/dev/null)" = healthy ] && break
  sleep 2
done
[ "$(docker inspect -f '{{.State.Health.Status}}' pangi-demo-db)" = healthy ] || { rojo "La base no arrancó (docker compose logs db)."; exit 1; }

# ── API: dependencias y .env ─────────────────────────────────────────────────
cd "$RAIZ/api-laravel"
if [ ! -d vendor ]; then azul "Instalando dependencias de la API…"; composer install --no-interaction --quiet; fi
if [ ! -f .env ]; then
  azul "Creando la configuración local de la API…"
  cp .env.example .env
  php artisan key:generate --force --quiet
  php -r '
    $f = ".env"; $s = file_get_contents($f);
    $v = ["JWT_SECRET" => bin2hex(random_bytes(32)),
          "TOKEN_ENCRYPTION_KEY" => strtr(base64_encode(random_bytes(32)), "+/", "-_"),
          "SERVICE_API_KEY" => bin2hex(random_bytes(24)),
          "FICHAJE_DEVICE_TOKEN" => bin2hex(random_bytes(16))];
    foreach ($v as $k => $x) { $s = preg_replace("/^$k=$/m", "$k=$x", $s); }
    file_put_contents($f, $s);'
fi
php artisan config:clear --quiet

# ── Datos de demo ────────────────────────────────────────────────────────────
TABLAS=$(sql -N -e "select count(*) from information_schema.tables where table_schema='$BASE'" 2>/dev/null || echo 0)
USUARIOS=0
[ "$TABLAS" -gt 0 ] && USUARIOS=$(sql -N "$BASE" -e "select count(*) from usuarios" 2>/dev/null || echo 0)
if [ "$TABLAS" -eq 0 ] || [ "$USUARIOS" -eq 0 ] || [ $REFRESCAR -eq 1 ]; then
  azul "Cargando la clínica de demo (tarda alrededor de un minuto)…"
  sql "$BASE" < "$RAIZ/base/esquema.sql"
  php artisan demo:configurar --confirmar="$BASE"
  php artisan demo:sembrar --confirmar="$BASE" --sin-red | tail -n +2
  date +%Y-%m-%d > "$MARCA_FECHA"
fi

# ── Frontends: dependencias ──────────────────────────────────────────────────
for d in website portal-reservas portal-admin-clinica; do
  if [ ! -d "$RAIZ/$d/node_modules" ]; then
    azul "Instalando dependencias de $d…"; (cd "$RAIZ/$d" && npm ci --no-audit --no-fund --silent)
  fi
done

# ── Arranque ─────────────────────────────────────────────────────────────────
mkdir -p "$LOGS"
PIDS=()
detener() {
  echo; azul "Deteniendo la demo…"
  for p in ${PIDS[@]+"${PIDS[@]}"}; do pkill -TERM -P "$p" 2>/dev/null || true; kill "$p" 2>/dev/null || true; done
  # Respaldo: lo que siga escuchando en los puertos de la demo es de la demo
  # (al arrancar se comprobó que estaban libres).
  for p in 8100 3100 3101 3102; do lsof -ti tcp:"$p" -sTCP:LISTEN 2>/dev/null | xargs kill 2>/dev/null || true; done
  (cd "$RAIZ" && docker compose stop db >/dev/null 2>&1) || true
}
trap detener EXIT INT TERM

# `exec`: el PID guardado es el del servidor y no el de una subshell intermedia.
(cd "$RAIZ/api-laravel" && PHP_CLI_SERVER_WORKERS=4 exec php artisan serve --host=127.0.0.1 --port=8100 >"$LOGS/api.log" 2>&1) &
PIDS+=($!)
(cd "$RAIZ/website" && exec node_modules/.bin/vite --port 3100 --strictPort >"$LOGS/website.log" 2>&1) &
PIDS+=($!)
(cd "$RAIZ/portal-reservas" && exec node_modules/.bin/vite --port 3101 --strictPort >"$LOGS/portal.log" 2>&1) &
PIDS+=($!)
(cd "$RAIZ/portal-admin-clinica" && exec node_modules/.bin/vite --port 3102 --strictPort >"$LOGS/panel.log" 2>&1) &
PIDS+=($!)

for p in 8100 3100 3101 3102; do
  for _ in $(seq 1 60); do lsof -iTCP:"$p" -sTCP:LISTEN >/dev/null 2>&1 && break; sleep 1; done
done

FECHA=$(cat "$MARCA_FECHA" 2>/dev/null || echo "?")
echo
azul "La demo está corriendo"
cat <<TXT
  Sitio web             http://localhost:3100
  Reservas y portal     http://localhost:3101
  Panel de la clínica   http://localhost:3102
  API                   http://localhost:8100/health

  Panel (clave demo1234):
    admin@pangi.demo          administración, ve todo
    marta.soto@pangi.demo     directora médica
    recepcion@pangi.demo      recepción (agenda, caja, ventas, clientes)

  Portal del cliente (clave demo1234):
    cliente@pangi.demo        tutora con dos mascotas y horas próximas

  Datos generados el $FECHA. Si hoy es otro día, la agenda de «hoy» puede
  verse vacía: ./iniciar-demo.sh --refrescar los rehace con la fecha actual.
  Registros en .demo-logs/. Ctrl+C para detener.
TXT
[ "$FECHA" != "$(date +%Y-%m-%d)" ] && rojo "Ojo: los datos son del $FECHA, no de hoy."
wait
