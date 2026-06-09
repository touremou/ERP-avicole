<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discrepancy_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->restrictOnDelete();
            $table->foreignId('reception_id')->constrained()->restrictOnDelete();
            $table->foreignId('reported_by')->constrained('users');

            // Totaux consolidés
            $table->decimal('total_dispatched', 12, 2);
            $table->decimal('total_received', 12, 2);
            $table->decimal('total_damaged', 12, 2)->default(0);
            $table->decimal('total_missing', 12, 2)->default(0);
            $table->decimal('discrepancy_rate', 5, 2);                          // % d'écart

            // Classification
            $table->enum('severity', ['normal', 'attention', 'critique'])->default('normal');

            // Résolution
            $table->enum('resolution', ['en_cours', 'justifie', 'injustifie', 'enquete'])->default('en_cours');
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['severity', 'resolution']);
            $table->index('dispatch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discrepancy_reports');
    }
};
