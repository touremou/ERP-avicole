<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chick_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incubation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();

            // Destination
            $table->enum('destination_type', ['elevage', 'vente', 'stock', 'perte']);
            $table->integer('quantity');

            // Si élevage → lot créé
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            // Si vente → client
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_price', 12, 2)->default(0);    // Prix/poussin
            $table->decimal('total_amount', 15, 2)->default(0);

            // Qualité
            $table->enum('quality_grade', ['A', 'B', 'C'])->default('A');
            $table->string('notes', 500)->nullable();

            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('dispatch_date');
            $table->timestamps();
        });

        // Ajouter le suivi sur la table incubations
        if (Schema::hasTable('incubations') && ! Schema::hasColumn('incubations', 'chicks_dispatched')) {
            Schema::table('incubations', function (Blueprint $table) {
                $table->integer('chicks_dispatched')->default(0)->after('hatched_chicks');
                $table->integer('chicks_remaining')->default(0)->after('chicks_dispatched');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chick_dispatches');

        if (Schema::hasTable('incubations')) {
            Schema::table('incubations', function (Blueprint $table) {
                if (Schema::hasColumn('incubations', 'chicks_dispatched')) $table->dropColumn('chicks_dispatched');
                if (Schema::hasColumn('incubations', 'chicks_remaining')) $table->dropColumn('chicks_remaining');
            });
        }
    }
};
