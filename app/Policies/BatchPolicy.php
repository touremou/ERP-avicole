<?php

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BatchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Batch $batch): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Batch $batch): bool
    {
        return false;
    }
    public function view(User $user) { return true; } // Tout le monde voit

    public function create(User $user) {
        return $user->role === 'admin'; // Seul l'admin crée
    }

    public function update(User $user) {
        return $user->role === 'admin'; // Seul l'admin modifie
    }

    public function delete(User $user) {
        return $user->role === 'admin'; // Seul l'admin supprime
    }
}
