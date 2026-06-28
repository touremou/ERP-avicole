# Serveur de licence fournisseur — AviSmart ERP

Application **autonome** (sans dépendance, PHP + SQLite + sodium) qui vit **chez
le fournisseur**, séparément des instances clientes de l'ERP. Elle détient la
**clé privée** Ed25519 et :

- émet / renouvelle / révoque les codes de licence (CLI) ;
- tient un registre des licences vendues (SQLite) ;
- expose l'endpoint `POST /check` consommé par la vérification en ligne hybride
  de l'ERP (`App\Services\LicenseService::syncOnline()`).

> ⚠️ Ne **jamais** déployer ce dossier chez un client : il contient la clé
> privée qui sert à signer toutes les licences. Seule la clé **publique** est
> posée dans l'ERP (`LICENSE_PUBLIC_KEY`).

## Prérequis
PHP 8.1+ avec l'extension `sodium` (intégrée par défaut). Aucun composer requis.

## Démarrage

```bash
cd license-server

# 1. Générer la paire de clés (une seule fois)
bin/license keygen
#   → écrit storage/private.key (secret) et affiche LICENSE_PUBLIC_KEY=...
#   → coller cette clé publique dans le .env de CHAQUE instance ERP cliente

# 2. Émettre une licence à la vente
bin/license issue --id=BIOCREST --client="BioCrest" --plan=pro --days=366
#   → affiche le CODE DE VALIDITÉ à transmettre au client

# 3. Renouveler / révoquer plus tard
bin/license renew  --id=BIOCREST --days=366
bin/license revoke --id=BIOCREST

# 4. Consulter le registre
bin/license list

# 5. Lancer le service /check (vérification en ligne hybride)
php -S 0.0.0.0:8989 -t public
#   → côté ERP client : LICENSE_SERVER_URL=https://votre-domaine/check
```

## Contrat de l'endpoint `POST /check`

Requête (envoyée par l'ERP) :
```json
{ "identifiant": "BIOCREST", "token": "<code actuel>", "expires_at": "2027-05-22T..." }
```

Réponse :
```json
{ "status": "ok" }                         // valide
{ "status": "revoked" }                     // bloque l'instance
{ "status": "renewed", "token": "<code>" }  // nouveau code à appliquer
```

`renewed` est renvoyé automatiquement lorsque le client présente un code plus
ancien qu'un renouvellement enregistré : son instance se met à jour seule.

## Cohérence des plans
Le catalogue de plans (`config.php`) doit refléter celui de l'ERP
(`config/license.php`) — mêmes slugs de modules et limites — pour que les
jetons émis correspondent à ce que l'ERP déverrouille.
