<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('dispatch_number')->unique();                        // EXP-2026-000001
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete(); // Lien vente (optionnel)
            $table->foreignId('dispatched_by')->constrained('users');           // Responsable ferme
            $table->string('vehicle_plate')->nullable();                        // Immatriculation
            $table->string('driver_name');
            $table->string('driver_phone')->nullable();
            $table->date('dispatch_date');
            $table->time('dispatch_time')->nullable();
            $table->string('destination');                                      // "Magasin Conakry", "Dépôt Kindia"
            $table->enum('status', ['prepare', 'expedie', 'en_route', 'receptionne', 'clos'])->default('prepare');
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();                           // Photo du chargement
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'dispatch_date']);
        });

        Schema::create('dispatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->enum('product_type', ['oeufs', 'volaille_vivante', 'volaille_abattue', 'fumier', 'aliment', 'materiel', 'autre']);
            $table->string('product_name');
            $table->unsignedBigInteger('product_id')->nullable();               // FK stocks.id
            $table->unsignedBigInteger('batch_id')->nullable();                 // FK batches.id
            $table->decimal('quantity_dispatched', 12, 2);                      // Quantité CHARGÉE
            $table->enum('unit', ['alveole', 'unite', 'kg', 'piece', 'sac', 'voyage']);
            $table->enum('condition_at_dispatch', ['bon', 'moyen', 'fragile'])->default('bon');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
    }
};
