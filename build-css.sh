#!/bin/bash
# build-css.sh - Genera login.bundle.css desde los archivos fuente
# Uso: bash build-css.sh

CSS_DIR="public/css"
OUTPUT="$CSS_DIR/login.bundle.css"

cat "$CSS_DIR/settings/variables.css" \
    "$CSS_DIR/base/base.css" \
    "$CSS_DIR/components/alerts.css" \
    "$CSS_DIR/components/buttons.css" \
    "$CSS_DIR/components/forms.css" \
    "$CSS_DIR/components/badges.css" \
    "$CSS_DIR/components/modal.css" \
    "$CSS_DIR/pages/login.css" \
    > "$OUTPUT"

echo "Bundle generado: $OUTPUT ($(wc -c < "$OUTPUT") bytes)"
