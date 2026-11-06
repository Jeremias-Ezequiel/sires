#!/bin/bash
# build-css.sh - Genera los CSS derivados desde los archivos fuente.
#   - public/css/login.bundle.css   (tokens + temas + componentes + login, una sola petición)
#   - public/css/pages/dashboard.min.css (dashboard.css minificado)
# Uso: bash build-css.sh   (correrlo después de tocar cualquier CSS fuente)

set -euo pipefail
cd "$(dirname "$0")"

CSS_DIR="public/css"
BUNDLE="$CSS_DIR/login.bundle.css"
DASH_SRC="$CSS_DIR/pages/dashboard.css"
DASH_MIN="$CSS_DIR/pages/dashboard.min.css"

cat "$CSS_DIR/settings/variables.css" \
    "$CSS_DIR/settings/themes.css" \
    "$CSS_DIR/base/base.css" \
    "$CSS_DIR/components/alerts.css" \
    "$CSS_DIR/components/buttons.css" \
    "$CSS_DIR/components/forms.css" \
    "$CSS_DIR/components/badges.css" \
    "$CSS_DIR/components/modal.css" \
    "$CSS_DIR/components/utilities.css" \
    "$CSS_DIR/pages/login.css" \
    > "$BUNDLE"

echo "Bundle generado: $BUNDLE ($(wc -c < "$BUNDLE") bytes)"

# Minificado simple: quita comentarios y espacios sobrantes (sin dependencias externas).
php -r '
    $css = file_get_contents($argv[1]);
    $css = preg_replace("#/\*.*?\*/#s", "", $css);
    $css = preg_replace("/\s+/", " ", $css);
    $css = preg_replace("/\s*([{};,>])\s*/", "$1", $css);
    $css = preg_replace("/:\s+/", ":", $css);
    $css = str_replace(";}", "}", $css);
    file_put_contents($argv[2], trim($css) . "\n");
' "$DASH_SRC" "$DASH_MIN"

echo "Minificado generado: $DASH_MIN ($(wc -c < "$DASH_MIN") bytes)"

# Control: ningún CSS fuente debe tener colores literales fuera de settings/
LITERALES=$(grep -rnE '#[0-9a-fA-F]{3,8}\b|rgba?\(' "$CSS_DIR/base" "$CSS_DIR/components" "$DASH_SRC" \
    $(ls "$CSS_DIR"/pages/*.css | grep -v '\.min\.css$') || true)
if [ -n "$LITERALES" ]; then
    echo "AVISO: colores literales fuera de settings/ (usar var(--...)):"
    echo "$LITERALES"
fi
