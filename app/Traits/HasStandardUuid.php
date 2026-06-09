<?php
namespace App\Traits;

use Illuminate\Support\Str;

trait HasStandardUuid
{
    protected static function bootHasStandardUuid()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeUnsynced($query)
    {
        return $query->where('is_synced', false);
    }
}