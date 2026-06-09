<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->restrictOnDelete();
            $table->string('reception_number')->unique();                       // REC-2026-000001
            $table->foreignId('received_by')->constrained('users');             // Responsable magasin
            $table->date('reception_date');
            $table->time('reception_time')->nullable();
            $table->enum('status', ['en_attente', 'valide', 'litige'])->default('en_attente');
            $table->string('photo_path')->nullable();                           // Photo à l'arrivée
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['dispatch_id', 'status']);
        });

        Schema::create('reception_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reception_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispatch_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_received', 12, 2);                        // Ce qui ARRIVE
            $table->decimal('quantity_damaged', 12, 2)->default(0);             // Cassé/mort en route
            $table->decimal('quantity_missing', 12, 2)->default(0);             // Écart inexpliqué
            $table->enum('condition_at_reception', ['bon', 'endommage', 'suspect'])->default('bon');
            $table->text('notes')->nullable();                                  // OBLIGATOIRE si écart > 0
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_items');
        Schema::dropIfExists('receptions');
    }
};
