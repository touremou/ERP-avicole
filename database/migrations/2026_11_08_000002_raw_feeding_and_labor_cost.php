<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALIMENTATION BRUTE ET MAIN-D'ŒUVRE IMPUTÉE AU LOT.
 *
 * Demande de l'exploitation : le nourrissage imposait un aliment du stock
 * (issu d'une formule). Impossible d'enregistrer l'herbe coupée, les fanes,
 * les épluchures — ni le temps qu'y passe l'ouvrier, alors que c'est le vrai
 * coût d'un mouton local nourri à l'herbe.
 *
 *   • raw_feedings : la distribution d'une ressource NON formulée (texte
 *     libre), quantité estimée, temps passé, parcelle d'origine éventuelle.
 *     Aucun lien au stock : c'est une PREUVE DE TRAVAIL, pas un mouvement.
 *   • employees.hourly_cost_gnf : taux horaire FORCÉ (facultatif). Vide, il se
 *     déduit du salaire (Employee::coutHoraire).
 *   • batch_cost_allocations : imputations ANALYTIQUES au lot. Pas des
 *     dépenses (`expenses` = sorties d'argent, en trésorerie) : le salaire est
 *     déjà payé par la paie ; l'imputer en dépense le compterait deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'hourly_cost_gnf')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->decimal('hourly_cost_gnf', 12, 2)->nullable()->after('salary');
            });
        }

        if (! Schema::hasTable('raw_feedings')) {
            Schema::create('raw_feedings', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
                $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('plot_id')->nullable()->constrained('plots')->nullOnDelete();
                $table->dateTime('fed_at');
                $table->string('resource', 120);
                $table->decimal('quantity', 10, 2)->nullable();
                $table->string('quantity_unit', 20)->nullable();
                $table->unsignedSmallInteger('minutes_spent');
                $table->decimal('hourly_cost_gnf', 12, 2)->default(0);
                $table->decimal('labor_cost_gnf', 14, 2)->default(0);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->index(['batch_id', 'fed_at']);
            });
        }

        if (! Schema::hasTable('batch_cost_allocations')) {
            Schema::create('batch_cost_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
                $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
                $table->string('category', 40);
                $table->string('label', 255);
                $table->decimal('amount', 14, 2);
                $table->date('allocated_on');
                $table->nullableMorphs('source');
                $table->timestamps();
                $table->index(['batch_id', 'category']);
                $table->unique(['source_type', 'source_id', 'category']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_cost_allocations');
        Schema::dropIfExists('raw_feedings');
        if (Schema::hasColumn('employees', 'hourly_cost_gnf')) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('hourly_cost_gnf'));
        }
    }
};
