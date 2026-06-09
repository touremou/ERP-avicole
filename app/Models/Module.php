<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    protected $fillable = [
        'name', 'slug', 'icon', 'color', 'description', 'display_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'module_permissions')
            ->withPivot(['can_read', 'can_create', 'can_modify', 'can_delete'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('display_order');
    }

    /**
     * Mapping slug → routes pour la détection du module actif dans la nav.
     */
    public static function getRouteMap(): array
    {
        return [
            'dashboard'     => ['dashboard'],
            'elevage'       => ['buildings.*', 'batches.*', 'daily-checks.*', 'health.*', 'protocols.*'],
            'production'    => ['egg-productions.*', 'egg-movements.*', 'incubations.*', 'reports.*'],
            'provenderie'   => ['provenderie.*', 'raw-materials.*', 'formulas.*', 'production.*', 'machines.*'],
            'planning'      => ['planning.*'],
            'abattoir'      => ['slaughter.*'],
            'commerce'      => ['clients.*', 'sales.*', 'payments.*'],
            'logistique'    => ['dispatches.*', 'stocks.*'],
            'ressources'    => ['utilities.*'],
            'notifications' => ['notifications.*'],
            'annuaire'      => ['employees.*', 'providers.*'],
            'admin'         => ['users.*', 'trash.*'],
        ];
    }
}
