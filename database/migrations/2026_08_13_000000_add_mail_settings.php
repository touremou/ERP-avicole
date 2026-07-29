<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RÉGLAGES E-MAIL — sortir la configuration SMTP du .env.
 *
 * WhatsApp et SMS se configuraient déjà dans les Réglages ; l'e-mail exigeait un
 * accès SSH et l'édition d'un fichier caché. Le promoteur vit à l'étranger : un
 * réglage qui demande un terminal est un réglage qu'il ne peut pas corriger le
 * jour où il échoue — et c'est précisément ce qui vient d'arriver.
 *
 * PAS de réglage « chiffrement » : il se déduit du port (cf. MailSettings). En
 * offrir un serait rouvrir la porte au défaut signalé — port 465 avec un schéma
 * incohérent, authentification refusée, et un message d'erreur qui accuse le mot
 * de passe.
 *
 * Les valeurs restent VIDES à l'installation : tant que l'hôte n'est pas saisi,
 * le .env continue de faire foi. Une mise à jour ne doit pas changer le
 * comportement d'un serveur déjà configuré.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * `unit` est un string(20) — « kg », « % », « jours ». Les explications
         * vont dans `label`, qui est un string(255). SQLite les aurait acceptées
         * dans `unit` en tronquant en silence ; MySQL refuse l'insertion, et le
         * job de parité l'a attrapé avant la production.
         */
        $rows = [
            ['key' => 'mailer', 'value' => 'smtp', 'type' => 'select',
             'label' => 'Canal e-mail (log = écrit sans envoyer)', 'options' => 'smtp,log',
             'display_order' => 1],

            ['key' => 'host', 'value' => '', 'type' => 'string',
             'label' => 'Serveur SMTP (ex. mail.mondomaine.fr)', 'display_order' => 2],

            ['key' => 'port', 'value' => '465', 'type' => 'number',
             'label' => 'Port — le chiffrement en découle', 'unit' => '465 ou 587',
             'display_order' => 3],

            ['key' => 'username', 'value' => '', 'type' => 'string',
             'label' => 'Identifiant — adresse complète de la boîte', 'display_order' => 4],

            // Même traitement que la clef API WhatsApp : jamais réaffiché à
            // l'écran, et un champ laissé vide conserve la valeur existante.
            ['key' => 'password', 'value' => '', 'type' => 'password',
             'label' => 'Mot de passe de la boîte', 'is_sensitive' => true, 'display_order' => 5],

            ['key' => 'from_address', 'value' => '', 'type' => 'string',
             'label' => "Adresse expéditeur — à défaut l'identifiant (la plupart des hébergeurs exigent qu'ils soient identiques)",
             'display_order' => 6],
        ];

        foreach ($rows as $row) {
            // updateOrInsert : la migration doit pouvoir repasser sans écraser
            // une valeur déjà saisie par l'utilisateur.
            $exists = DB::table('settings')
                ->where('group', 'mail')->where('key', $row['key'])
                ->whereNull('farm_id')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('settings')->insert(array_merge([
                'group'      => 'mail',
                'farm_id'    => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $row));
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'mail')->whereNull('farm_id')->delete();

        Setting::clearCache();
    }
};
