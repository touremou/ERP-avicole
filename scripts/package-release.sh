#!/usr/bin/env bash
#
# package-release.sh — Construit une COPIE de distribution durcie de l'ERP
# (protection GRATUITE, sans encodeur payant). À exécuter CHEZ LE FOURNISSEUR.
#
# Étapes :
#   1. copie propre du projet (exclut .git, node_modules, tests, .env, logs…)
#   2. dépendances de production (composer --no-dev) + build des assets (npm)
#   3. caches d'optimisation Laravel
#   4. durcissement « light » : suppression des commentaires/mise en forme du
#      PHP applicatif (php artisan release:strip)
#
# La barrière commerciale reste la LICENCE SIGNÉE (l'app est inutilisable sans
# un code valide). Pour un encodage fort, passer à ionCube/SourceGuardian plus
# tard (voir DEPLOYMENT.md §8.3).
#
# Usage : scripts/package-release.sh /chemin/vers/dossier-de-sortie
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-}"

if [ -z "$OUT" ]; then
  echo "Usage : $0 /chemin/vers/dossier-de-sortie" >&2
  exit 1
fi
if [ -e "$OUT" ]; then
  echo "Refus : '$OUT' existe déjà. Choisissez un dossier de sortie neuf." >&2
  exit 1
fi

echo "→ 1/4 Copie propre vers $OUT"
mkdir -p "$OUT"
rsync -a \
  --exclude '.git' --exclude '.github' \
  --exclude 'node_modules' --exclude 'tests' \
  --exclude '.env' --exclude '.env.*' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  --exclude 'database/database.sqlite' \
  "$SRC/" "$OUT/"

echo "→ 2/4 Dépendances de production + assets"
( cd "$OUT" && composer install --no-dev --optimize-autoloader --no-interaction )
( cd "$OUT" && npm ci && npm run build )

echo "→ 3/4 Caches d'optimisation"
( cd "$OUT" && php artisan optimize )

echo "→ 4/4 Durcissement light du PHP applicatif"
( cd "$OUT" && php artisan release:strip "$OUT" )

echo "✓ Release durcie prête : $OUT"
echo "  Rappel : poser LICENSE_PUBLIC_KEY dans le .env du client et émettre un code (php artisan license:issue)."
