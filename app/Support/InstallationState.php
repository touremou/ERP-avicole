<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\File;

/**
 * CETTE INSTALLATION EST-ELLE DÉJÀ FAITE ? — déclaration UNIQUE.
 *
 * La question n'avait qu'une réponse : `file_exists(storage/installed)`. Or ce
 * fichier ne voyage PAS avec l'application, et c'est voulu : le guide de
 * déploiement exclut `storage/` du rsync, et consigne même l'incident inverse
 * (« le marqueur avait été embarqué dans l'archive… exclure `storage/` »).
 * Il est donc posé une seule fois, sur l'hôte, à la fin du premier assistant.
 *
 * Tout ce qui reprovisionne `storage/` — nouvel hôte, conteneur reconstruit,
 * application restaurée sans son storage — efface donc le marqueur pendant que
 * la base, elle, configurée à part dans `.env`, reste pleine.
 *
 * ─── CE QUE CELA COÛTAIT, MESURÉ ───
 *
 * L'assistant se rouvre alors au premier visiteur venu, sans authentification :
 *
 *   • `POST /install/database` RÉÉCRIT le `.env` avec les identifiants de base
 *     de son choix ;
 *   • `POST /install/migrate` lance `migrate --force` ET `db:seed --force` sur
 *     la base de production ;
 *   • `POST /install/admin` ne crée pas un second compte : il REPREND le
 *     premier administrateur trouvé — nom, e-mail et mot de passe réécrits.
 *     L'exploitant ne peut plus se connecter, le visiteur le peut.
 *
 * ─── POURQUOI « UN ADMIN EXISTE » NE POUVAIT PAS SERVIR DE GARDE ───
 *
 * L'étape 3 de l'assistant lance `db:seed`, qui crée les comptes de démarrage,
 * dont un administrateur. À l'étape 4 d'une installation LÉGITIME, un
 * administrateur existe donc déjà — c'est même pour cela que `storeAdmin` est
 * écrit pour reprendre un compte plutôt que d'en créer un. Refuser sur ce seul
 * critère aurait verrouillé toute installation neuve à son avant-dernière
 * étape.
 *
 * On distingue donc le compte SEMÉ du compte RÉEL, et la liste des comptes
 * semés est lue chez celui qui les sème.
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * Trois témoins, et un seul suffit :
 *
 *   1. le marqueur sur disque — celui d'avant, conservé ;
 *   2. le marqueur EN BASE, posé à la finalisation. Il voyage avec les données
 *      qu'il décrit, donc il survit à tout reprovisionnement de `storage/` ;
 *   3. un administrateur RÉEL — filet pour les hôtes qui avaient déjà perdu le
 *      fichier avant cette correction, et qu'aucune migration ne pouvait
 *      rattraper.
 */
class InstallationState
{
    /** Le réglage qui porte la date de finalisation. */
    public const CLEF = 'general.installed_at';

    /** Fichier témoin historique, conservé pour ne rien casser d'existant. */
    public static function fichierMarqueur(): string
    {
        return storage_path('installed');
    }

    /**
     * L'application est-elle déjà installée ?
     *
     * Toute panne de base signifie « pas installée » : sur une instance neuve,
     * `.env` ne porte pas encore de connexion valable et la requête lève. C'est
     * la règle que `InstallController::adminAccountExists` appliquait déjà, et
     * elle est la bonne — sans elle, l'assistant refuserait de s'ouvrir là où
     * il est précisément nécessaire.
     */
    public static function estInstallee(): bool
    {
        if (self::marqueurPose()) {
            return true;
        }

        try {
            return self::administrateurReelExiste();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * LE MARQUEUR A-T-IL ÉTÉ POSÉ ? — question DIFFÉRENTE de la précédente.
     *
     * `estInstallee()` répond « cet assistant a-t-il encore le droit de
     * tourner ? » et compte l'administrateur réel parmi ses témoins.
     * Celle-ci répond « la finalisation a-t-elle déjà eu lieu ? ».
     *
     * Les confondre coûte cher, et je l'ai fait avant que la suite ne me
     * l'apprenne : `finish()` teste ce témoin pour savoir s'il doit encore
     * POSER le marqueur. Branché sur `estInstallee()`, il trouvait « déjà
     * installé » dès que l'étape précédente venait de créer l'administrateur
     * RÉEL — et sautait donc son propre travail. Une installation neuve
     * s'achevait sans marqueur, et surtout sans la bascule du `.env` en
     * production : `APP_DEBUG` serait resté à `true`.
     */
    public static function marqueurPose(): bool
    {
        if (File::exists(self::fichierMarqueur())) {
            return true;
        }

        try {
            return (bool) Setting::get(self::CLEF);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Un administrateur qui n'est PAS un compte de démonstration.
     *
     * La liste des comptes semés se lit chez le semeur : la recopier ici en
     * ferait une seconde déclaration, qui finirait par diverger de celle qui
     * fait foi — le défaut que tout cet audit poursuit. `storeAdmin` en porte
     * d'ailleurs une troisième, périmée : il cherche « admin@admin.com » et
     * supprime « user@users.com », deux adresses que le semeur ne crée plus.
     */
    public static function administrateurReelExiste(): bool
    {
        $roleAdmin = Role::where('name', 'admin')->value('id');

        if ($roleAdmin === null) {
            return false;
        }

        return User::where('role_id', $roleAdmin)
            ->whereNotIn('email', UserSeeder::emailsDeDemonstration())
            ->exists();
    }

    /** Pose les deux marqueurs : sur disque, et avec les données. */
    public static function marquerInstallee(): void
    {
        $horodatage = now()->toDateTimeString();

        File::put(self::fichierMarqueur(), $horodatage);

        try {
            Setting::set(self::CLEF, $horodatage);
        } catch (\Throwable) {
            // Une base indisponible ne doit pas faire échouer la finalisation :
            // le marqueur disque, lui, est posé.
        }
    }
}
