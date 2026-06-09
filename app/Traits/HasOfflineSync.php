<?php
namespace App\Traits;

use Illuminate\Support\Str;

trait HasOfflineSync {
    /**
     * Boot du trait : génère un UUID à la création si absent
     */
    protected static function bootHasOfflineSync() {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            // Par défaut, une donnée créée sur le serveur est considérée comme synchronisée
            $model->is_synced = $model->is_synced ?? true;
            $model->last_sync_at = now();
        });
    }

    /**
     * Scope pour récupérer les données en attente de synchro (depuis le serveur vers clients)
     */
    public function scopeUnsynced($query) {
        return $query->where('is_synced', false);
    }
}