#!/usr/bin/env bash
# Genera dist/dsmk-legal-<versión>.zip listo para subir a WordPress.
# Excluye todo lo listado en .distignore. Uso: bash bin/build.sh
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 -oP 'Version:\s*\K[\d.]+' dsmk-legal.php)
php tests/php/run-tests.php | tail -1
php bin/make-pot.php
rm -rf dist && mkdir -p dist/dsmk-legal
EXCL=()
while IFS= read -r line; do
  [[ -z "$line" || "$line" == \#* ]] && continue
  EXCL+=(--exclude="${line#/}")
done < .distignore
tar -cf - "${EXCL[@]}" . | tar -xf - -C dist/dsmk-legal
(cd dist && zip -qr "dsmk-legal-${VERSION}.zip" dsmk-legal && rm -rf dsmk-legal)
echo "dist/dsmk-legal-${VERSION}.zip"
