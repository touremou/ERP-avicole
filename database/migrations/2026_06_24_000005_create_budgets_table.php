<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets prévisionnels par poste de dépense et par mois.
 *
 * Un budget = un montant alloué à une catégorie (cf. Expense::CATEGORIES) pour
 * un mois donné d'une ferme. Le suivi « dépensé vs budget » rapproche ces
 * montants de la somme des dépenses VALIDÉES de la même catégorie sur la période.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 50);            // clé Expense::CATEGORIES
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');      // 1-12
            $table->decimal('amount', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['farm_id', 'category', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
