<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModulePermission extends Model
{
    
    protected $fillable = [
        'role_id', 'module_id',
        'can_read', 'can_create', 'can_modify', 'can_delete',
    ];

    protected $casts = [
        'can_read'   => 'boolean',
        'can_create' => 'boolean',
        'can_modify' => 'boolean',
        'can_delete' => 'boolean',
    ];

    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
    public function module(): BelongsTo { return $this->belongsTo(Module::class); }

    /**
     * Vérifie un niveau de permission (L, C, M, S).
     */
    public function hasLevel(string $level): bool
    {
        return match (strtoupper($level)) {
            'L' => $this->can_read,
            'C' => $this->can_create,
            'M' => $this->can_modify,
            'S' => $this->can_delete,
            default => false,
        };
    }
}
