<?php

use App\Models\NotificationPreference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DES COMPTES SANS AUCUNE PRÉFÉRENCE D'ALERTE.
 *
 * La ligne de `notification_preferences` n'était créée qu'en ouvrant l'écran
 * Paramètres › Notifications (`firstOrCreate` dans le contrôleur). Or la
 * résolution des destinataires in-app exigeait une ligne ACTIVE : un compte qui
 * n'avait jamais visité cet écran ne recevait donc AUCUNE alerte — ni cloche web,
 * ni centre d'alertes mobile — et rien ne le signalait.
 *
 * Concrètement : le promoteur, qui avait ouvert l'écran, recevait tout ; ses
 * techniciens, rien. Le silence était indistinguable de « tout va bien ».
 *
 * Le code ne dépend plus de l'existence de la ligne (une préférence absente vaut
 * les valeurs livrées), mais on les crée quand même : ainsi la ferme les VOIT et
 * peut les régler par personne, au lieu de subir un comportement implicite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('users')
            ->leftJoin('notification_preferences', 'notification_preferences.user_id', '=', 'users.id')
            ->whereNull('notification_preferences.id')
            ->pluck('users.id');

        if ($orphans->isEmpty()) {
            return;
        }

        $now = now();

        DB::table('notification_preferences')->insert(
            $orphans->map(fn ($userId) => array_merge(
                NotificationPreference::DEFAULTS,
                ['user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]
            ))->all()
        );
    }

    public function down(): void
    {
        // On ne supprime rien : impossible de distinguer les lignes créées ici de
        // celles que la ferme a réglées depuis.
    }
};
