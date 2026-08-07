#!/usr/bin/env bash
#
# package-release.sh — Construit une COPIE de distribution durcie de l'ERP
# (protection GRATUITE, sans encodeur payant). À exécuter CHEZ LE FOURNISSEUR.
#
# Étapes :
#   1. copie propre du projet (exclut .git, node_modules, tests, .env, logs…)
#   2. dépendances de production (composer --no-dev) + build des assets (npm)
#   3. caches d'optimisation Laravel — SAUF celui de la configuration, qui
#      ferait ignorer le .env du client (cf. étape 3)
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
  --exclude 'bootstrap/cache/*.php' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  --exclude 'database/database.sqlite' \
  "$SRC/" "$OUT/"

echo "→ 2/4 Dépendances de production + assets"
( cd "$OUT" && composer install --no-dev --optimize-autoloader --no-interaction )
( cd "$OUT" && npm ci && npm run build )

echo "→ 3/4 Caches d'optimisation (SANS le cache de configuration)"
# `php artisan optimize` inclut config:cache. Or cette copie ne contient PAS de
# .env (exclu ci-dessus, et c'est voulu : les secrets du fournisseur n'ont rien
# à faire chez le client). Le cache de configuration produit ici figerait donc
# app.key à NULL, app.debug à false et database.default à « sqlite » — les
# valeurs par défaut du code, pas celles du client.
#
# Et un cache de configuration présent fait que Laravel NE LIT PLUS le .env du
# tout (Bootstrap\LoadEnvironmentVariables sort immédiatement si la config est
# en cache). Le .env que l'exploitant remplit consciencieusement resterait donc
# sans le moindre effet, sans message ni trace : l'installeur tenterait d'ouvrir
# une base sqlite au chemin de la machine du fournisseur, et échouerait derrière
# une page d'erreur muette (app.debug figé à false).
#
# Le runbook demande bien à l'exploitant de lancer config:cache après avoir posé
# son .env — ce qui écrase le cache fautif. Mais faire dépendre le démarrage
# d'une étape manuelle bien ordonnée, c'est poser une mine : on ne livre donc
# aucun cache de configuration, et l'application lit le .env normalement.
( cd "$OUT" && php artisan route:cache && php artisan view:cache && php artisan event:cache )

echo "→ 4/4 Durcissement light du PHP applicatif"
( cd "$OUT" && php artisan release:strip "$OUT" )

# Garde-fou : aucune release ne doit partir avec un cache de configuration.
if [ -f "$OUT/bootstrap/cache/config.php" ]; then
  echo "ERREUR : un cache de configuration a été produit dans la release." >&2
  echo "         Il ferait ignorer le .env du client. Livraison interrompue." >&2
  exit 1
fi

echo "✓ Release durcie prête : $OUT"
echo "  Rappel : poser LICENSE_PUBLIC_KEY dans le .env du client et émettre un code (php artisan license:issue)."
