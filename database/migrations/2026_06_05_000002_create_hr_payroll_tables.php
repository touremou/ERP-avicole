<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ═══ 1. CONGÉS & ABSENCES ═══
        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['conge_annuel', 'maladie', 'maternite', 'sans_solde', 'absence', 'formation', 'autre']);
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days_count');
            $table->enum('status', ['demande', 'approuve', 'refuse', 'en_cours', 'termine'])->default('demande');
            $table->text('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ═══ 2. PÉRIODES DE PAIE ═══
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');                              // "Juin 2026"
            $table->integer('year');
            $table->integer('month');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['brouillon', 'calcule', 'valide', 'paye'])->default('brouillon');
            $table->decimal('total_brut', 15, 0)->default(0);
            $table->decimal('total_net', 15, 0)->default(0);
            $table->decimal('total_primes', 15, 0)->default(0);
            $table->decimal('total_deductions', 15, 0)->default(0);
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month', 'farm_id']);
        });

        // ═══ 3. FICHES DE PAIE ═══
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();

            // Montants calculés
            $table->decimal('base_salary', 12, 0)->default(0);
            $table->decimal('total_primes', 12, 0)->default(0);
            $table->decimal('total_deductions', 12, 0)->default(0);
            $table->decimal('net_salary', 12, 0)->default(0);

            // Présence
            $table->integer('days_worked')->default(0);
            $table->integer('days_absent')->default(0);
            $table->integer('days_leave')->default(0);
            $table->integer('overtime_hours')->default(0);

            // Paiement
            $table->enum('payment_method', ['especes', 'orange_money', 'virement'])->default('especes');
            $table->string('payment_reference')->nullable();      // Réf Orange Money
            $table->enum('payment_status', ['en_attente', 'paye', 'partiel'])->default('en_attente');
            $table->timestamp('paid_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id']);
        });

        // ═══ 4. LIGNES DE DÉTAIL (primes / déductions) ═══
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['prime', 'deduction']);
            $table->string('label');                              // "Prime nuit", "Avance sur salaire"
            $table->decimal('amount', 12, 0)->default(0);
            $table->string('category')->nullable();               // performance, nuit, ferie, transport, avance, absence
            $table->timestamps();
        });

        // ═══ 5. AJOUTER COLONNES RH SUR EMPLOYEES ═══
        if (Schema::hasTable('employees')) {
            $cols = ['hire_date', 'contract_type', 'departure_date', 'departure_reason',
                     'annual_leave_balance', 'orange_money_number', 'assigned_building_id'];

            foreach ($cols as $col) {
                if (! Schema::hasColumn('employees', $col)) {
                    Schema::table('employees', function (Blueprint $table) use ($col) {
                        match($col) {
                            'hire_date'             => $table->date('hire_date')->nullable()->after('status'),
                            'contract_type'         => $table->enum('contract_type', ['cdi', 'cdd', 'journalier', 'stagiaire'])->default('cdi')->after('hire_date'),
                            'departure_date'        => $table->date('departure_date')->nullable()->after('contract_type'),
                            'departure_reason'      => $table->string('departure_reason')->nullable()->after('departure_date'),
                            'annual_leave_balance'  => $table->integer('annual_leave_balance')->default(30)->after('departure_reason'),
                            'orange_money_number'   => $table->string('orange_money_number', 30)->nullable()->after('phone'),
                            'assigned_building_id'  => $table->foreignId('assigned_building_id')->nullable()->after('department'),
                        };
                    });
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('employee_leaves');
    }
};
