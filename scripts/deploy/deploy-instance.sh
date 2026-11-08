#!/usr/bin/env bash
#
# deploy-instance.sh — Déploie le code sur UNE instance client hébergée en
# mutualisé (PlanetHoster / N0C), sans jamais toucher à ses données ni à sa
# configuration. Utilisé tel quel par le workflow « Deploy client instance »
# et, à la main, depuis un poste (même script, mêmes garde-fous).
#
# CE QUI N'EST JAMAIS ENVOYÉ NI MODIFIÉ SUR LE SERVEUR
#   • .env (APP_KEY, base, LICENSE_PUBLIC_KEY = le « certificat » du client) ;
#   • storage/ (fichiers, photos, journaux, marqueur d'installation, sauvegardes) ;
#   • public/storage, public/.well-known (SSL), .user.ini (réglages PHP N0C) ;
#   • la base : seules les migrations s'y appliquent — le « token » (code de
#     licence activé, table `licenses`) et toutes les données restent.
#   Le script PROUVE que le .env est intact (empreinte avant / après).
#
# PRÉREQUIS LOCAUX (le serveur mutualisé ne compile rien)
#   composer install --no-dev --optimize-autoloader
#   npm ci && npm run build                                   → public/build
#   (cd mobile && npm ci && VITE_API_BASE_URL=… npm run build) → mobile/dist
#
# VARIABLES
#   INSTANCE        nom de l'instance (ex. client-ferme-kindia) — obligatoire
#   SSH_HOST        hôte SSH N0C (ex. node42.n0c.com)
#   SSH_PORT        port SSH (5022 chez PlanetHoster)
#   SSH_USER        utilisateur SSH du compte
#   SSH_KEY         chemin de la clé privée de déploiement
#   WEB_PATH        dossier de l'application (ex. /home/USER/clients/kindia/erp)
#   PWA_PATH        dossier de la PWA terrain (vide = PWA non déployée)
#   PUBLIC_URL      URL publique de l'ERP (ex. https://erp.kindia-ferme.com)
#   MODE            update (défaut) | first-install
#   SKIP_BACKUP     1 = pas de sauvegarde de base avant migration (déconseillé)
#   DRY_RUN         1 = n'écrit rien : liste ce qui serait transféré
#   PROTECTED_PATHS chemins à NE JAMAIS viser, séparés par des virgules
#                   (le workflow y passe ceux du site actuel)
#
# Usage : INSTANCE=… SSH_HOST=… … scripts/deploy/deploy-instance.sh
set -euo pipefail

: "${INSTANCE:?INSTANCE requis (ex. client-ferme-kindia)}"
: "${SSH_HOST:?SSH_HOST requis}"
: "${SSH_USER:?SSH_USER requis}"
: "${SSH_KEY:?SSH_KEY requis (chemin de la clé privée)}"
: "${WEB_PATH:?WEB_PATH requis}"
SSH_PORT="${SSH_PORT:-5022}"
PWA_PATH="${PWA_PATH:-}"
PUBLIC_URL="${PUBLIC_URL:-}"
MODE="${MODE:-update}"
SKIP_BACKUP="${SKIP_BACKUP:-0}"
DRY_RUN="${DRY_RUN:-0}"
PROTECTED_PATHS="${PROTECTED_PATHS:-}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

say()  { printf '\n\033[1m→ %s\033[0m\n' "$*"; }
fail() { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ── 0. Garde-fous locaux ─────────────────────────────────────────────────
case "$MODE" in update|first-install) ;; *) fail "MODE inconnu : $MODE (update | first-install)";; esac

norm() { printf '%s' "${1%/}"; }
for chemin in "$WEB_PATH" "$PWA_PATH"; do
  [ -z "$chemin" ] && continue
  case "$chemin" in /home/*/*) ;; *) fail "Chemin refusé : '$chemin' (attendu : absolu, sous /home/UTILISATEUR/…)";; esac
  IFS=',' read -r -a proteges <<< "$PROTECTED_PATHS"
  for p in "${proteges[@]}"; do
    [ -z "$p" ] && continue
    if [ "$(norm "$chemin")" = "$(norm "$p")" ]; then
      fail "Chemin PROTÉGÉ (site actuel) : '$chemin'. Ce script ne déploie que des instances clients."
    fi
  done
done
[ -n "$PWA_PATH" ] && [ "$(norm "$PWA_PATH")" = "$(norm "$WEB_PATH")" ] && fail "PWA_PATH et WEB_PATH doivent être deux dossiers distincts."

[ -f vendor/autoload.php ] || fail "vendor/ absent : composer install --no-dev --optimize-autoloader"
[ -f public/build/manifest.json ] || fail "public/build absent : npm ci && npm run build"
if [ -n "$PWA_PATH" ]; then
  [ -f mobile/dist/index.html ] || fail "mobile/dist absent : (cd mobile && npm ci && VITE_API_BASE_URL=… npm run build)"
fi

SSH=(ssh -p "$SSH_PORT" -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new -o BatchMode=yes "$SSH_USER@$SSH_HOST")
RSYNC_SSH="ssh -p $SSH_PORT -i $SSH_KEY -o StrictHostKeyChecking=accept-new -o BatchMode=yes"
RSYNC_DRY=(); [ "$DRY_RUN" = "1" ] && RSYNC_DRY=(--dry-run --itemize-changes)

echo "Instance : $INSTANCE  ·  mode : $MODE  ·  web : $WEB_PATH  ·  pwa : ${PWA_PATH:-—}  ·  essai à blanc : $DRY_RUN"

# ── 1. Contrôle du serveur AVANT tout transfert ──────────────────────────
say "1/5 Contrôle de l'instance distante"
"${SSH[@]}" "MODE='$MODE' WEB_PATH='$WEB_PATH' SKIP_BACKUP='$SKIP_BACKUP' DRY_RUN='$DRY_RUN' bash -s" <<'DISTANT'
set -euo pipefail
if [ "$MODE" = "first-install" ]; then
  if [ -f "$WEB_PATH/.env" ] && [ -f "$WEB_PATH/storage/installed" ]; then
    echo "✗ $WEB_PATH contient déjà une instance INSTALLÉE : utilisez MODE=update." >&2; exit 1
  fi
  [ "$DRY_RUN" = "1" ] || mkdir -p "$WEB_PATH"
  echo "✓ Dossier prêt pour une première installation."
  exit 0
fi

cd "$WEB_PATH" 2>/dev/null || { echo "✗ $WEB_PATH introuvable : première installation ? (MODE=first-install)" >&2; exit 1; }
[ -f .env ] || { echo "✗ .env absent : l'instance n'est pas configurée — rien n'est déployé." >&2; exit 1; }
grep -Eq '^APP_KEY=base64:.+' .env || { echo "✗ APP_KEY vide dans .env — rien n'est déployé." >&2; exit 1; }
sha256sum .env | cut -d' ' -f1 > storage/framework/.env.avant-deploiement
php artisan migrate:status --no-ansi >/dev/null || { echo "✗ Base injoignable avec ce .env — rien n'est déployé." >&2; exit 1; }
echo "✓ .env présent, APP_KEY posée, base joignable."
php artisan license:status --no-ansi 2>/dev/null || echo "  (contrôle licence indisponible sur cette version : il le sera après la mise à jour)"

if [ "$SKIP_BACKUP" = "1" ] || [ "$DRY_RUN" = "1" ]; then
  echo "⚠ Sauvegarde de la base NON effectuée (SKIP_BACKUP / DRY_RUN)."
else
  php artisan backup:run --only-db --disable-notifications --no-ansi \
    || { echo "✗ Sauvegarde de la base en échec — rien n'est déployé. (DB_DUMP_BINARY_PATH ?)" >&2; exit 1; }
  echo "✓ Sauvegarde de la base effectuée (storage/app/…, module Sauvegardes)."
fi
DISTANT

# ── 2. Code de l'application web ─────────────────────────────────────────
# Pas de --delete : on n'efface jamais rien sur le serveur.
say "2/5 Transfert de l'application web"
rsync -az --no-perms --no-owner --no-group "${RSYNC_DRY[@]}" \
  --exclude='.git/' --exclude='.github/' --exclude='node_modules/' \
  --exclude='mobile/' --exclude='tests/' --exclude='scripts/deploy/' \
  --exclude='.env' --exclude='.env.backup*' \
  --exclude='storage/' --exclude='public/storage' \
  --exclude='public/.well-known/' --exclude='.well-known/' \
  --exclude='.user.ini' --exclude='public/.user.ini' \
  --exclude='bootstrap/cache/*.php' --exclude='*.tar.gz' \
  -e "$RSYNC_SSH" ./ "$SSH_USER@$SSH_HOST:$WEB_PATH/"

# ── 3. PWA terrain ───────────────────────────────────────────────────────
# Dossier propre (--delete), SAUF .well-known (validation du certificat SSL).
if [ -n "$PWA_PATH" ]; then
  say "3/5 Transfert de la PWA terrain"
  [ "$DRY_RUN" = "1" ] || "${SSH[@]}" "mkdir -p '$PWA_PATH'"
  rsync -az --delete --no-perms --no-owner --no-group "${RSYNC_DRY[@]}" \
    --exclude='.well-known/' \
    -e "$RSYNC_SSH" mobile/dist/ "$SSH_USER@$SSH_HOST:$PWA_PATH/"
else
  say "3/5 PWA : non déployée (PWA_PATH vide)"
fi

if [ "$DRY_RUN" = "1" ]; then
  say "Essai à blanc terminé : rien n'a été écrit sur le serveur."
  exit 0
fi

# ── 4. Base et caches (à distance) ───────────────────────────────────────
say "4/5 Finalisation distante"
"${SSH[@]}" "MODE='$MODE' WEB_PATH='$WEB_PATH' bash -s" <<'DISTANT'
set -euo pipefail
cd "$WEB_PATH"
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/public bootstrap/cache
chmod -R u+rwX,go-w storage bootstrap/cache

if [ "$MODE" = "first-install" ]; then
  if [ ! -f .env ]; then
    cp .env.client.example .env
    chmod 600 .env
    echo "✓ .env créé depuis .env.client.example — À COMPLÉTER avant /install (base, APP_URL, certificat)."
  else
    echo "✓ .env existant conservé tel quel."
  fi
  grep -Eq '^APP_KEY=base64:.+' .env || php artisan key:generate --force --no-ansi
  php artisan storage:link --no-ansi || true
  echo "→ Étape suivante : compléter le .env, puis ouvrir https://…/install (cf. docs/ops/instance-client-planethoster.md)."
  exit 0
fi

trap 'php artisan up --no-ansi >/dev/null 2>&1 || true' EXIT
php artisan down --retry=15 --no-ansi || true
php artisan storage:link --no-ansi || true
php artisan migrate --force --no-ansi
php artisan optimize --no-ansi

# Le .env n'a pas bougé : preuve par empreinte.
avant="$(cat storage/framework/.env.avant-deploiement 2>/dev/null || true)"
apres="$(sha256sum .env | cut -d' ' -f1)"
if [ -n "$avant" ] && [ "$avant" != "$apres" ]; then
  echo "✗ Le .env a changé pendant le déploiement — vérifier immédiatement." >&2; exit 1
fi
rm -f storage/framework/.env.avant-deploiement
echo "✓ .env intact (empreinte identique avant / après)."
php artisan license:status --no-ansi || echo "⚠ La licence bloque l'accès : activer le code du client (Administration › Licence)."
DISTANT

# ── 5. Vérifications publiques ───────────────────────────────────────────
say "5/5 Vérifications"
if [ -n "$PUBLIC_URL" ] && [ "$MODE" = "update" ]; then
  code="$(curl -s -o /dev/null -w '%{http_code}' "${PUBLIC_URL%/}/up" || true)"
  [ "$code" = "200" ] || fail "${PUBLIC_URL%/}/up répond $code (attendu 200) — voir storage/logs/laravel.log"
  echo "✓ ${PUBLIC_URL%/}/up → 200"
  code="$(curl -s -o /dev/null -w '%{http_code}' "${PUBLIC_URL%/}/api/v1/health" || true)"
  [ "$code" = "200" ] || fail "${PUBLIC_URL%/}/api/v1/health répond $code (attendu 200)"
  echo "✓ ${PUBLIC_URL%/}/api/v1/health → 200"
fi
if [ "$MODE" = "update" ]; then
  "${SSH[@]}" "cd '$WEB_PATH' && php artisan avismart:diagnostic --no-ansi" || echo "⚠ Le diagnostic signale des points à traiter (voir ci-dessus) — le déploiement, lui, est terminé."
fi

say "Déploiement de $INSTANCE terminé."
