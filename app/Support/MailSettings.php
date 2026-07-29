<?php

namespace App\Support;

use App\Models\Setting;

/**
 * CONFIGURATION E-MAIL — réglable depuis l'application, plus seulement le .env.
 *
 * WhatsApp et SMS se configuraient déjà dans les Réglages ; l'e-mail, lui,
 * exigeait un accès SSH au serveur et l'édition d'un fichier caché. Le promoteur
 * vit à l'étranger : un réglage qui demande un terminal est un réglage qu'il ne
 * peut pas corriger le jour où il échoue.
 *
 * PRIORITÉ : ce qui est saisi dans les Réglages l'emporte sur le .env. Le .env
 * reste le repli, pour ne pas casser un serveur déjà configuré — et pour que
 * l'installation initiale continue de fonctionner avant que quiconque n'ouvre
 * l'écran.
 *
 * LE CHIFFREMENT N'EST PAS DEMANDÉ : il se DÉDUIT du port. C'est une conséquence,
 * pas une décision indépendante — le redemander offrirait deux façons de se
 * contredire, et c'est exactement ce qui a produit l'échec signalé depuis le
 * terrain (« port 465, scheme=auto » → authentification refusée en annonçant un
 * problème d'identifiants).
 */
class MailSettings
{
    /**
     * Chiffrement imposé par le port.
     *
     * 465 = TLS implicite (SMTPS) : le serveur attend une poignée de main
     * chiffrée AVANT toute commande. 587 (et le reste) = STARTTLS.
     *
     * DÉCLARATION UNIQUE : config/mail.php (chemin .env) et la surcharge par les
     * Réglages passent tous deux par ici. Deux copies divergeraient, et l'écran
     * afficherait un réglage que l'envoi n'applique pas.
     */
    public static function schemeForPort(?string $explicit, int $port): ?string
    {
        if (filled($explicit)) {
            return $explicit;   // serveur non standard : la déduction n'est pas une contrainte
        }

        return $port === 465 ? 'smtps' : null;
    }

    /** Un réglage e-mail a-t-il été saisi dans l'application ? */
    public static function configured(): bool
    {
        return filled(Setting::get('mail.host'));
    }

    /**
     * Applique les Réglages à la configuration de Laravel.
     *
     * Appelé au démarrage. Sans hôte saisi, on ne touche à RIEN : le .env
     * continue de faire foi, et un site en service ne change pas de comportement
     * du seul fait de la mise à jour.
     */
    public static function apply(): void
    {
        if (! static::configured()) {
            return;
        }

        $port = (int) (Setting::get('mail.port') ?: 465);

        $smtp = array_merge(config('mail.mailers.smtp') ?? [], [
            'transport' => 'smtp',
            'host'      => (string) Setting::get('mail.host'),
            'port'      => $port,
            'scheme'    => static::schemeForPort(null, $port),
            'username'  => (string) Setting::get('mail.username'),
            'password'  => (string) Setting::get('mail.password'),
        ]);

        config(['mail.mailers.smtp' => $smtp]);

        // Le canal : « log » écrit dans le journal sans rien envoyer — utile pour
        // vérifier un contenu, trompeur si on l'oublie. L'écran le dit.
        $mailer = (string) (Setting::get('mail.mailer') ?: 'smtp');
        config(['mail.default' => $mailer]);

        // L'EXPÉDITEUR doit être la boîte authentifiée : la plupart des
        // hébergements mutualisés refusent autre chose. On ne l'impose pas
        // (certains serveurs l'autorisent), mais à défaut de saisie on retient
        // l'identifiant plutôt qu'une adresse générique qui ferait échouer
        // l'envoi sans dire pourquoi.
        $from = Setting::get('mail.from_address') ?: Setting::get('mail.username');

        if (filled($from)) {
            config(['mail.from.address' => (string) $from]);
        }

        config(['mail.from.name' => Setting::companyName()]);
    }
}
