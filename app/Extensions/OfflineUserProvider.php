<?php
namespace App\Extensions;

use Illuminate\Auth\EloquentUserProvider;
use App\Models\Role;

class OfflineUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        try {
            // Tentative standard (MySQL ON)
            return parent::retrieveById($identifier);
        } catch (\Throwable $e) {
            // SI WAMP/MYSQL OFF : On crée un utilisateur temporaire en mémoire
            $user = $this->createModel();
            
            // 1. On utilise le bon format RBAC (role_id au lieu de role string)
            $user->forceFill([
                'id' => $identifier,
                'name' => 'Session Hors-Ligne',
                'role_id' => 1, // Assure-toi que 1 correspond bien à l'ID de l'admin
                'email' => 'offline@avismart.gn'
            ]);

            // 2. STUB DES RELATIONS CRITIQUES
            // On simule des relations vides ou par défaut pour empêcher Laravel
            // d'essayer de faire des requêtes SQL (qui planteraient).
            
            // Évite le crash dans le layout (la cloche de notifications)
            $user->setRelation('unreadNotifications', collect());
            $user->setRelation('notifications', collect());

            // Évite le crash si tes Gates font : $user->role->name === 'admin'
            $fakeRole = new Role();
            $fakeRole->forceFill(['id' => 1, 'name' => 'admin']);
            $user->setRelation('role', $fakeRole);

            return $user;
        }
    }
}