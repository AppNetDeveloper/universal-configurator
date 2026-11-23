#!/usr/bin/env bash
# Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
# Construye y firma el paquete de una versión (lo hace bin/release). Deja en dist/ el .zip, su .sha256 y el
# .update.json firmado: súbelos a la release v<versión> de GitHub. No sube nada.
# Uso:  deploy/hacer_release.sh [--key=ruta-de-la-clave] [--changelog=archivo]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
exec php bin/release "$@"
