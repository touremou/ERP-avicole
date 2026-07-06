#!/usr/bin/env bash
# Build de la PWA terrain pour le staging + archive prête à déployer.
#
# Usage :
#   VITE_API_BASE_URL=https://ferme.example.com/api/v1 ./scripts/build-staging.sh
#   (laisser VITE_API_BASE_URL vide si /api est reverse-proxifié par le vhost PWA)
#
# Produit : mobile/dist/ (à copier tel quel dans la racine web du vhost app.*)
#           + mobile/aviterrain-pwa.tar.gz (archive du même dist/).
set -euo pipefail

cd "$(dirname "$0")/.."

echo "▶ Base API : ${VITE_API_BASE_URL:-<relatif /api/v1>}"

if [ ! -d node_modules ]; then
  echo "▶ Installation des dépendances…"
  npm ci
fi

echo "▶ Build (tsc + vite)…"
npm run build

echo "▶ Archive…"
tar -czf aviterrain-pwa.tar.gz -C dist .

echo "✓ Terminé."
echo "  Racine web  : $(pwd)/dist"
echo "  Archive     : $(pwd)/aviterrain-pwa.tar.gz"
echo "  → décompresser dans la racine du vhost app.* puis recharger nginx."
