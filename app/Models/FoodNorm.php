<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\BelongsToFarm;

class FoodNorm extends Model
{
    use HasFactory, BelongsToFarm;
    // Indispensable pour l'importation
    protected $fillable = [
        'farm_id',
        'name', 
        'animal_type', 
        'phase', 
        'target_em', 
        'target_pb', 
        'target_lys', 
        'target_meth', 
        'target_ca', 
        'target_p', 
        'target_price_kg', 
        'is_active'
    ];

}