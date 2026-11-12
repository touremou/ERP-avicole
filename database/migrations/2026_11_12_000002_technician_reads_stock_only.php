<?php

use App\Support\ProfilsMetier;
use Illuminate\Database\Migrations\Migration;

/**
 * Le technicien d'élevage CONSULTE le stock, il ne le saisit plus.
 *
 * Audit de l'exploitation : il pouvait faire les saisies du magasinier
 * (inventaire, réception d'aliment, contrôle des lots stockés) et afficher son
 * poste. Seule la cellule révisée est réappliquée au profil déjà installé.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProfilsMetier::reappliquer(['profil-technicien-elevage' => ['logistique']]);
    }

    public function down(): void
    {
        // Révision de droits : pas de retour automatique vers des droits plus larges.
    }
};
