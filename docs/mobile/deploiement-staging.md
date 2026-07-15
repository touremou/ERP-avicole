# Déploiement staging — PWA terrain « AviTerrain »

> Objet : mettre la PWA (`mobile/`) entre les mains d'un **utilisateur pilote**
> sur un sous-domaine `app.*`, avant la Phase 3. Le backend Laravel (API v1)
> est déjà en production/staging ; on ne déploie ici que le **build statique**
> de la PWA et on **ouvre CORS** pour l'origine `app.*`.
>
> Prérequis backend déjà en place (cf. `phase-0-spec.md`) : API v1 Sanctum,
> `sync/pull` + `sync/push`, `farm.api`, endpoints devices/notifications/photos.

---

## 0. Deux topologies possibles (choisir AVANT le build)

| | **A. Reverse-proxy (recommandé pilote)** | **B. Cross-origine** |
|---|---|---|
| PWA | `https://app.ferme.example.com` | `https://app.ferme.example.com` |
| API | `https://app.ferme.example.com/api` → proxifié vers Laravel | `https://ferme.example.com/api` (hôte distinct) |
| `VITE_API_BASE_URL` | **vide** (relatif `/api/v1`) | `https://ferme.example.com/api/v1` |
| CORS | **inutile** (même origine) | **requis** (`CORS_ALLOWED_ORIGINS`) |
| Cookies/CSRF | sans objet (token Bearer) | sans objet (token Bearer) |

**La topologie A est la plus simple et la plus robuste pour un pilote** : le
vhost de la PWA sert les fichiers statiques ET relaie `/api` (+ `/storage`)
vers le Laravel existant. Pas de CORS, pas de préflight, un seul certificat.
La topologie B convient si la PWA doit vivre sur un hébergement statique séparé
(CDN, Netlify…) — elle exige d'ouvrir CORS.

> ⚠️ **HTTPS obligatoire.** Service worker, installation PWA, caméra (photo /
> scan QR) et géolocalisation sont **désactivés hors HTTPS** par les
> navigateurs (sauf `localhost`). Provisionner le certificat AVANT le test
> pilote (Let's Encrypt / certbot).

---

## 1. Build de la PWA

Depuis `mobile/` :

```bash
# Topologie A (reverse-proxy) — base relative :
./scripts/build-staging.sh

# Topologie B (cross-origine) — base absolue :
VITE_API_BASE_URL=https://ferme.example.com/api/v1 ./scripts/build-staging.sh
```

Produit `mobile/dist/` (à copier dans la racine web) et une archive
`aviterrain-pwa.tar.gz`. Le build échoue si le TypeScript ne compile pas
(`tsc --noEmit` en amont de Vite) — un build vert = un bundle sain.

---

## 2. Vhost nginx

> Hébergement **mutualisé** (Apache/LiteSpeed, ex. PlanetHoster) : pas de
> nginx — voir le parcours complet **`DEPLOYMENT.md` §11.C** (sous-domaine via
> le panneau + `.htaccess` fourni : no-cache `sw.js`, cache long assets,
> fallback SPA).

### Topologie A — reverse-proxy (PWA + /api sur le même hôte)

```nginx
server {
    listen 443 ssl http2;
    server_name app.ferme.example.com;

    ssl_certificate     /etc/letsencrypt/live/app.ferme.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.ferme.example.com/privkey.pem;

    # ── PWA statique ──
    root /var/www/aviterrain;   # contenu de mobile/dist/
    index index.html;

    # Le service worker ne doit JAMAIS être mis en cache par le navigateur/proxy.
    location = /sw.js {
        add_header Cache-Control "no-cache, no-store, must-revalidate";
        try_files $uri =404;
    }
    # Assets fingerprintés (hash dans le nom) : cache long.
    location /assets/ {
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    # ── API + fichiers publics relayés vers Laravel ──
    # (la PWA appelle /api/v1/... en relatif → aucune config CORS nécessaire)
    location /api/     { proxy_pass http://127.0.0.1:8000; include proxy_params; }
    location /storage/ { proxy_pass http://127.0.0.1:8000; include proxy_params; }
    location /up       { proxy_pass http://127.0.0.1:8000; include proxy_params; }

    # SPA fallback : la PWA route en hash (#/…), mais on sert index.html pour
    # toute route inconnue par sécurité (deep-link, refresh).
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

> `proxy_pass http://127.0.0.1:8000` = l'upstream de votre Laravel (php-fpm
> derrière un autre vhost, ou `php artisan serve` en staging léger). Adapter au
> déploiement réel (souvent un `fastcgi_pass` vers php-fpm sur le vhost
> principal ; ici on reste sur un reverse-proxy HTTP pour ne rien présumer).

### Topologie B — PWA statique seule (API sur un autre hôte)

```nginx
server {
    listen 443 ssl http2;
    server_name app.ferme.example.com;

    ssl_certificate     /etc/letsencrypt/live/app.ferme.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.ferme.example.com/privkey.pem;

    root /var/www/aviterrain;
    index index.html;

    location = /sw.js { add_header Cache-Control "no-cache, no-store, must-revalidate"; try_files $uri =404; }
    location /assets/ { add_header Cache-Control "public, max-age=31536000, immutable"; try_files $uri =404; }
    location /        { try_files $uri $uri/ /index.html; }
}
```

Ici l'API reste sur `ferme.example.com` → **ouvrir CORS** (étape 3).

---

## 3. CORS côté Laravel (topologie B uniquement)

`config/cors.php` est fourni et lit l'env. Dans le `.env` du Laravel :

```dotenv
CORS_ALLOWED_ORIGINS=https://app.ferme.example.com
# plusieurs origines possibles (CSV) : ...,https://app-staging.ferme.example.com
```

Puis vider le cache de config :

```bash
php artisan config:clear   # ou config:cache en prod
```

L'auth mobile est par **token Bearer** (pas de cookie) : `supports_credentials`
reste `false`, ce qui autorise le wildcard en dev. En production, **lister
explicitement** l'origine `app.*` plutôt que `*`.

---

## 4. Déploiement des fichiers

```bash
# sur le serveur, racine du vhost PWA :
sudo mkdir -p /var/www/aviterrain
sudo tar -xzf aviterrain-pwa.tar.gz -C /var/www/aviterrain
sudo nginx -t && sudo systemctl reload nginx
```

Vérifier que Laravel a bien son lien de stockage (photos d'incident/reçus) :

```bash
php artisan storage:link   # idempotent
```

---

## 5. Vérifications post-déploiement (2 minutes)

```bash
# 1. Sonde de santé (doit renvoyer {"status":"ok",...})
curl -s https://app.ferme.example.com/api/v1/health        # topologie A
curl -s https://ferme.example.com/api/v1/health            # topologie B

# 2. CORS (topologie B) : le préflight doit renvoyer l'origine autorisée
curl -sI -X OPTIONS https://ferme.example.com/api/v1/health \
  -H 'Origin: https://app.ferme.example.com' \
  -H 'Access-Control-Request-Method: GET' | grep -i access-control-allow-origin

# 3. PWA installable : ouvrir l'URL sur Android/Chrome → menu « Installer
#    l'application ». Vérifier l'icône d'accueil + le lancement plein écran.
```

Check-list navigateur (DevTools → Application) : **Manifest** détecté,
**Service Worker** « activated », **IndexedDB `erp-mobile`** créée après login.

---

## 6. Préparer le compte pilote

Créer un utilisateur affecté à la ferme pilote, avec un rôle terrain
(gardien/manager) — via l'admin web, ou en tinker :

```php
php artisan tinker
>>> $farm = App\Models\Farm::firstWhere('code', 'FT-001');
>>> $role = App\Models\Role::firstWhere('name', 'manager');
>>> $u = App\Models\User::factory()->create([
...   'name' => 'Amadou (pilote)', 'email' => 'pilote@ferme.example.com',
...   'password' => bcrypt('à-communiquer-en-personne'), 'role_id' => $role->id,
... ]);
>>> DB::table('farm_user')->insert(['farm_id'=>$farm->id,'user_id'=>$u->id,'is_default'=>true,'is_owner'=>false,'created_at'=>now(),'updated_at'=>now()]);
```

Le pilote se connecte, l'app **bootstrap** ses lots/clients/produits via
`sync/pull`, puis fonctionne hors-ligne. En cas de perte du téléphone :
révoquer son appareil depuis « Mon espace » d'un autre appareil, ou en base
(`personal_access_tokens`).

---

## 7. Ce qu'on observe pendant le pilote (retour terrain)

- Le **badge de sync** reflète-t-il fidèlement l'état (au chaud / en attente / à
  corriger) pour l'utilisateur ?
- Les **saisies hors-ligne** (poulailler sans réseau) remontent-elles bien au
  retour de couverture, sans perte ni doublon ?
- **Photos** : la compression tient-elle sur les téléphones réels (quota,
  lenteur) ? Les reçus/autopsies arrivent-ils lisibles ?
- **Scan QR** : `BarcodeDetector` est-il présent sur le parc réel, ou le repli
  saisie manuelle est-il souvent nécessaire ?
- **Batterie / data** : consommation sur une journée de travail complète.

Ces observations alimentent l'**Atelier 1** (personas & parcours) de la RFC et
la priorisation de la Phase 3.

---

## 8. Rollback

La PWA est un simple dossier statique : conserver l'archive précédente et la
redéployer. Le service worker se met à jour tout seul (`autoUpdate`) au
prochain lancement — aucun store, aucune validation à attendre. Côté données,
rien à annuler : l'API et la base restent celles de la production/staging
existante.
