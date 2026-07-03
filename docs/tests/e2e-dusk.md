# Parcours E2E navigateur — Laravel Dusk (audit 360° P2-⑫)

> Tests « vrai navigateur » : Chrome headless piloté par ChromeDriver, contre
> une **vraie base MySQL** (`erp_dusk`) et un vrai serveur HTTP. Ils exercent
> ce que Pest/HTTP ne voit pas : rendu complet, JS des formulaires, ancres de
> retour, soumissions et redirections réelles.

## Prérequis (une fois)

1. Base dédiée : `CREATE DATABASE erp_dusk` (InnoDB — cf. runbook déploiement §0).
2. `.env.dusk.local` à la racine (gitignoré) : `APP_ENV=dusk`,
   `APP_URL=http://127.0.0.1:8010`, `DB_DATABASE=erp_dusk`,
   `MAIL_MAILER=array`, `ERROR_ALERTS_ENABLED=false`.
3. ChromeDriver aligné sur le Chrome installé : `php artisan dusk:chrome-driver --detect`.

## Lancer

```powershell
# Terminal 1 — serveur applicatif sur le port 8010 (base erp_dusk)
php artisan serve --port=8010

# Terminal 2 — les parcours
php artisan dusk tests/Browser/CriticalJourneysTest.php
```

`php artisan dusk` **remplace `.env` par `.env.dusk.local` pendant le run**
(et le restaure à la fin) ; `artisan serve` détecte le changement et redémarre
tout seul. Conséquence : **ne rien lancer d'autre** (queue, tinker, autre
serveur) sur ce dépôt pendant un run Dusk.

## Parcours couverts (verts en groupe, 2 runs consécutifs — 2026-07-03)

| # | Parcours | Ce qui est prouvé |
|---|----------|-------------------|
| 1 | Connexion réelle → tableau de bord | Formulaire login, session, redirection |
| 2 | Retour hiérarchique depuis un rapport | La sous-page P&L ramène à `reports.index` (pas au hub) — invariant navigation |
| 3 | Création de lot de bout en bout | Formulaire JS complet (auto-code, filtres espèce/type/souche), POST réel, lot en base, fiche affichée |

Périmètre : l'audit visait 5 parcours ; les 2 parcours vente (création,
validation) sont couverts côté logique par les feature tests HTTP et les
drills de concurrence C1/C3 — leur version navigateur est au backlog.

## Pièges connus (payés une fois, documentés pour ne pas les repayer)

- **Service worker PWA** : jamais enregistré en env `dusk` (garde
  `@unless (app()->environment('dusk'))` dans `layouts/app.blade.php` et
  `layouts/guest.blade.php`). Un SW actif intercepte navigations et POST
  (cache/file offline) → parcours non déterministes. Preuve : réponses
  serveur ~0,1 ms (cache SW) et POST jamais reçus par le serveur.
- **Clic natif perdu en `--headless=new`** : quand un test a déjà tourné dans
  la même fenêtre, le clic WebDriver « réussit » côté driver mais AUCUN
  événement n'atteint le document (prouvé par listener capture). Les clics de
  navigation passent donc par `element.click()` / `form.requestSubmit()` via
  `$browser->script(...)` — mêmes sémantiques (validation HTML5, event
  submit, requête serveur réelle).
- **JS d'init du formulaire lot** : auto-génère le code et remet qty/prix à
  zéro → attendre la fin de l'init (`waitUsing` sur la valeur du code) avant
  de saisir ; injecter les valeurs via `->value()` (les handlers `oninput`
  mangent les frappes simulées) ; re-sceller `type`/`model_name` après les
  re-rendus de `runFilters()`.
- **Sélecteurs insensibles à la locale** : cibler par `href`/`name`, jamais
  par texte (l'UI de test peut se rendre en anglais).
- **Vérité en base** : après soumission, asserter d'abord l'existence en base
  (`waitUsing` + modèle `withoutGlobalScopes()`), puis le rendu (`waitForText`)
  — le code affiché peut être re-généré par le JS entre-temps.
- **`artisan serve` est mono-thread** : premières visites lentes (compilation
  Blade à froid) → waits généreux (25 s) sur la première page de chaque
  parcours.

## En cas d'échec

Dusk dépose les preuves dans `tests/Browser/screenshots/` (PNG de l'état au
moment de l'échec) et `tests/Browser/console/` (console Chrome). Les lire
AVANT de toucher au test : le screenshot dit presque toujours si c'est la
page, le formulaire ou l'attente qui a menti.
