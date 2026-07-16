#!/usr/bin/env bash
# Genera lo ZIP di distribuzione per WordPress.org.
# Usage: ./bin/build-zip.sh
set -euo pipefail

PLUGIN_SLUG="cetus-media-optimizer"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_BASE="${ROOT_DIR}/../_build"
BUILD_DIR="${BUILD_BASE}/${PLUGIN_SLUG}"
ZIP_PATH="${ROOT_DIR}/../${PLUGIN_SLUG}.zip"

echo "==> Pulizia build precedente..."
rm -rf "${BUILD_DIR}" "${ZIP_PATH}"
mkdir -p "${BUILD_DIR}"

echo "==> Installazione dipendenze production (--no-dev)..."
cd "${ROOT_DIR}"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

echo "==> Pulizia vendor (test/docs/esempi)..."
composer run remove-vendor-extras --quiet 2>/dev/null || true

echo "==> Copia file nel build dir..."
rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='.distignore' \
  --exclude='.editorconfig' \
  --exclude='.claude/' \
  --exclude='.DS_Store' \
  --exclude='Thumbs.db' \
  --exclude='bin/' \
  --exclude='node_modules/' \
  --exclude='phpcs.xml.dist' \
  --exclude='composer.lock' \
  --exclude='*.sh' \
  --exclude='*.swp' \
  --exclude='*.swo' \
  --exclude='_build/' \
  --exclude='memory/' \
  --exclude='assets/' \
  "${ROOT_DIR}/" "${BUILD_DIR}/"

echo "==> Pulizia residui nascosti in vendor (cartelle e file Git di configurazione)..."
find "${BUILD_DIR}/vendor" -type d -name ".git" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}/vendor" -type d -name ".github" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}/vendor" -type f \( \
  -name ".gitignore" -o \
  -name ".gitattributes" -o \
  -name ".git-blame-ignore-revs" -o \
  -name ".editorconfig" -o \
  -name ".php-cs-fixer.dist.php" -o \
  -name ".scrutinizer.yml" -o \
  -name ".travis.yml" -o \
  -name "phpstan.neon*" -o \
  -name "psalm.xml*" -o \
  -name "mago.toml" -o \
  -name "analysis-baseline.toml" \
  \) -delete 2>/dev/null || true
find "${BUILD_DIR}" -name ".DS_Store" -delete 2>/dev/null || true

echo "==> Creazione ZIP..."
cd "${BUILD_BASE}"
zip -r "${ZIP_PATH}" "${PLUGIN_SLUG}" -x "*/.DS_Store" > /dev/null

echo ""
echo "✓ ZIP creato: $(du -sh "${ZIP_PATH}" | cut -f1)  →  ${ZIP_PATH}"
echo "✓ Contenuto vendor: $(du -sh "${BUILD_DIR}/vendor" | cut -f1)"
