#!/bin/bash
# Déploiement o2switch (lancé par cPanel > Git Version Control > Deploy HEAD Commit).
# Copie l'appli dans public_html et garde les données hors du dossier web.
set -e

APP_DIR="$HOME/public_html/budget"     # dossier web de l'appli (modifiable)
DATA_DIR="$HOME/budget-data"           # base SQLite + justificatifs, hors web

SRC="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$APP_DIR" "$DATA_DIR/justificatifs"
chmod 750 "$DATA_DIR"

# Code (remplacé à chaque déploiement, les données ne sont jamais touchées)
rm -rf "$APP_DIR/lib" "$APP_DIR/assets"
cp -R "$SRC/lib" "$SRC/assets" "$APP_DIR/"
cp "$SRC/index.php" "$SRC/.htaccess" "$APP_DIR/"

# Config créée une seule fois
if [ ! -f "$APP_DIR/config.local.php" ]; then
  printf "<?php\nreturn ['data_dir' => '%s'];\n" "$DATA_DIR" > "$APP_DIR/config.local.php"
  chmod 640 "$APP_DIR/config.local.php"
fi

echo "Déployé dans $APP_DIR (données : $DATA_DIR)"
