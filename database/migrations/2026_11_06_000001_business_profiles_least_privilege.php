<?php

use App\Support\ProfilsMetier;
use Illuminate\Database\Migrations\Migration;

/**
 * PROFILS MÉTIER — révision au moindre privilège (audit de l'exploitation).
 *
 * Un technicien d'élevage voyait les clients, les fournisseurs, les budgets et
 * des rapports financiers, et n'avait pas les tâches. Les profils déjà
 * installés (2026_11_05_000006) ne sont jamais réécrits par l'installateur :
 * on réapplique ici les SEULES cellules révisées, d'après la définition
 * courante (ProfilsMetier::PROFILS) — le reste de la matrice, qu'un
 * administrateur a pu ajuster, n'est pas touché.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProfilsMetier::reappliquer([
            // Tiers : l'Annuaire (fournisseurs + clients) retiré à qui ne traite pas avec eux.
            'profil-operateur-abattoir' => ['annuaire'],
            'profil-agent-provenderie'  => ['annuaire'],
            'profil-vendeur'            => ['annuaire'],
            'profil-veterinaire'        => ['annuaire'],
            'profil-responsable-rh'     => ['annuaire'],
            // Argent et tiers retirés ; le planning de l'équipe (tâches) accordé.
            'profil-technicien-elevage' => ['annuaire', 'depenses', 'planning'],
            'profil-magasinier'         => ['annuaire', 'depenses'],
            'profil-technicien-cultures' => ['planning'],
        ]);
    }

    public function down(): void
    {
        // Révision de droits : pas de retour automatique vers des droits plus larges.
    }
};
