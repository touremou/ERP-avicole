# Notifications push — mise en service

Le push fait sonner le téléphone **même application fermée**. C'est le seul canal
qui atteigne le terrain sans que quelqu'un ouvre l'application : la cloche web et
le centre d'alertes mobile sont des écrans, ils ne se remplissent qu'à
l'ouverture.

Il vient **en plus** de la cloche, qui reste la source consultable. Un téléphone
éteint ou un abonnement expiré ne doit jamais faire perdre une alerte.

---

## 1. Générer la paire de clefs (une seule fois)

Sur le serveur, dans le dossier de l'application :

```bash
cd ~/public_html
php artisan push:generate-keys
```

La commande enregistre les deux clefs dans les réglages :

- **publique** — distribuée aux appareils, non secrète ;
- **privée** — signe chaque envoi, ne quitte pas le serveur.

> ⚠️ **Ne la relancez pas.** Remplacer la clef publique **invalide tous les
> abonnements** : chaque téléphone devrait réaccepter les notifications. La
> commande refuse donc d'écraser une paire existante sans `--force`, et dit
> combien d'appareils y perdraient leur abonnement.

Sauvegardez la clef privée si vous prévoyez de changer de serveur sans demander à
tout le monde de se réabonner. Elle se lit en base :

```sql
SELECT value FROM settings WHERE `group` = 'push' AND `key` = 'vapid_private_key';
```

---

## 2. Activer sur chaque appareil

L'autorisation ne peut pas être accordée à distance : c'est le navigateur qui la
demande, et il exige un geste de l'utilisateur.

Sur le téléphone, dans l'application terrain : **Mon espace → Alertes sur cet
appareil → Activer les alertes**, puis accepter la demande du navigateur.

Le bouton **Envoyer un test** vérifie immédiatement que ça fonctionne. Sans lui,
« ai-je bien accepté ? » ne se vérifie qu'en attendant une vraie alerte.

### Sur iPhone

Safari **ne délivre pas de push à un simple onglet**. L'application doit avoir été
ajoutée à l'écran d'accueil (iOS 16.4 ou plus récent) :

**Partager → Sur l'écran d'accueil**, puis ouvrir depuis l'icône.

L'écran l'explique de lui-même quand il détecte ce cas — sans ce message,
l'utilisateur croit l'application défaillante.

### Sur Android

Chrome délivre le push à un onglet comme à une application installée. Rien de
particulier à faire.

---

## 3. Ce qui est envoyé

Titre, message court, et l'écran à ouvrir. **Rien de sensible** : le contenu
traverse le serveur de push du fabricant (Google, Apple, Mozilla) — chiffré de
bout en bout, mais un identifiant de lot suffit à dire ce qu'il faut sans exposer
les chiffres de l'exploitation.

Les alertes **critiques** restent affichées jusqu'à ce qu'on les touche ; les
autres se referment d'elles-mêmes. Les alertes d'un même type se regroupent : trois
alertes de mortalité ne laissent qu'une bannière, la plus récente — un écran
verrouillé saturé n'est plus lu.

Les **heures silencieuses** sont respectées comme pour l'e-mail, sauf alerte
critique. Une bannière à 3 h du matin pour un registre incomplet ferait couper les
notifications, et on perdrait alors aussi celles qui comptent.

---

## 4. Couper le push pour quelqu'un

Deux niveaux :

- **l'appareil** — Mon espace → *Ne plus recevoir* ;
- **le compte** — Paramètres → Notifications, décocher le canal push. Utile pour
  quelqu'un qui a accepté puis regretté, sans qu'il ait à fouiller les réglages de
  son navigateur.

Couper le push **n'ampute jamais la cloche** : l'historique reste consultable.

---

## 5. Diagnostic

| Symptôme | Cause probable |
|---|---|
| « Le serveur n'a pas encore de clef » | `php artisan push:generate-keys` n'a pas été lancé |
| « Ce navigateur ne gère pas les notifications » | navigateur trop ancien, ou iPhone hors écran d'accueil |
| « Les notifications sont bloquées » | l'utilisateur a refusé — à rétablir dans les réglages du site, côté navigateur |
| Le test dit « aucun appareil joint » | l'abonnement a expiré : réactiver depuis Mon espace |
| Un appareil ne reçoit plus rien | il a peut-être été purgé (voir ci-dessous) |

Un abonnement que le fournisseur déclare mort (404 / 410 : application
désinstallée, notifications révoquées) est **supprimé** — il ne redeviendra jamais
valide, et le garder ferait échouer chaque envoi suivant. Cinq échecs consécutifs
d'un autre type produisent la même purge.

Pour voir l'état des appareils :

```sql
SELECT u.name, p.device_label, p.last_success_at, p.failure_count
FROM push_subscriptions p JOIN users u ON u.id = p.user_id
ORDER BY p.last_success_at DESC;
```
