#!/usr/bin/env bash
# Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
# Instalador sencillo para un VPS Ubuntu 22.04+ / Debian 12+: PHP-FPM + Caddy (HTTPS automático).
#
#   sudo ./install.sh --domain=configurador.miempresa.com --email=yo@miempresa.com --empresa="Mi empresa" [--sample]
#   La contraseña del administrador se pide por teclado, o llega por la variable UC_PASSWORD (no queda en `ps`).
#
# Volver a ejecutarlo sobre una instalación existente ACTUALIZA el código sin tocar los datos (carpeta data/).
set -euo pipefail

APP_DIR=/var/www/universal-configurator
DOMAIN=""; EMAIL=""; EMPRESA=""; SAMPLE=""; TIMEZONE="Europe/Madrid"; PASSWORD="${UC_PASSWORD:-}"
for arg in "$@"; do
  case "$arg" in
    --domain=*)   DOMAIN="${arg#*=}" ;;
    --email=*)    EMAIL="${arg#*=}" ;;
    --empresa=*)  EMPRESA="${arg#*=}" ;;
    --timezone=*) TIMEZONE="${arg#*=}" ;;
    --dir=*)      APP_DIR="${arg#*=}" ;;
    --sample)     SAMPLE="--sample" ;;
    -h|--help)    sed -n '2,9p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $arg" >&2; exit 1 ;;
  esac
done

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Ejecuta con sudo."
command -v apt-get >/dev/null || fail "Este instalador es para Ubuntu/Debian. En otros sistemas usa el instalador web (abre la dirección en el navegador)."
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "$SRC_DIR/app/bootstrap.php" ] || fail "Ejecuta el script desde la carpeta de Universal Configurator."

UPDATE=0
[ -f "$APP_DIR/data/config.php" ] && UPDATE=1
if [ $UPDATE -eq 0 ]; then
  [ -n "$DOMAIN" ]  || read -rp "Dominio (ej. configurador.miempresa.com): " DOMAIN
  [ -n "$EMAIL" ]   || read -rp "Correo del administrador: " EMAIL
  [ -n "$EMPRESA" ] || read -rp "Nombre de la empresa: " EMPRESA
  if [ -z "$PASSWORD" ]; then read -rsp "Contraseña (mín. 8 caracteres): " PASSWORD; echo; fi
  [ ${#PASSWORD} -ge 8 ] || fail "La contraseña debe tener al menos 8 caracteres."
  [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Dominio no válido: $DOMAIN"
fi

say "Instalando paquetes (PHP-FPM con SQLite, Caddy)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q php-fpm php-cli php-sqlite3 php-mbstring rsync curl caddy || fail "No se pudieron instalar los paquetes (¿falta el repositorio de Caddy? https://caddyserver.com/docs/install)."
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || fail "Hace falta PHP 8.2 o superior (hay $PHPV)."
FPM_SOCK="/run/php/php${PHPV}-fpm.sock"
cat > "/etc/php/${PHPV}/fpm/conf.d/90-universal-configurator.ini" <<INI
session.use_strict_mode = 1
expose_php = Off
INI
systemctl enable --now "php${PHPV}-fpm"
systemctl restart "php${PHPV}-fpm"

say "Copiando la aplicación a $APP_DIR…"
mkdir -p "$APP_DIR"
rsync -a --delete --exclude data/ --exclude .git/ "$SRC_DIR/" "$APP_DIR/"
mkdir -p "$APP_DIR/data"
chown -R www-data:www-data "$APP_DIR/data"
chmod 750 "$APP_DIR/data"

if [ $UPDATE -eq 0 ]; then
  say "Creando la base de datos y el administrador…"
  export UC_PASSWORD="$PASSWORD"
  sudo --preserve-env=UC_PASSWORD -u www-data php "$APP_DIR/bin/uc" install --empresa="$EMPRESA" --email="$EMAIL" \
    --url="https://$DOMAIN" --timezone="$TIMEZONE" $SAMPLE
  unset UC_PASSWORD
else
  say "Instalación existente: código actualizado, datos intactos."
fi

say "Configurando Caddy (HTTPS automático) para $DOMAIN…"
sed -e "s#configurador.example.com#${DOMAIN:-configurador.example.com}#g" -e "s#/var/www/universal-configurator#$APP_DIR#g" \
    -e "s#unix//run/php/php8.2-fpm.sock#unix/$FPM_SOCK#g" "$APP_DIR/deploy/caddy.example" > /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
systemctl enable caddy >/dev/null 2>&1 || true
systemctl reload caddy 2>/dev/null || systemctl restart caddy

echo
say "Listo."
if [ $UPDATE -eq 0 ]; then
  echo "   Configurador: https://$DOMAIN"
  echo "   Panel:        https://$DOMAIN/admin  ($EMAIL)"
  echo "   MCP:          https://$DOMAIN/mcp    (crea un token en Panel → Tokens MCP)"
  echo "   Apunta el DNS (registro A) de $DOMAIN a la IP de este servidor."
fi
