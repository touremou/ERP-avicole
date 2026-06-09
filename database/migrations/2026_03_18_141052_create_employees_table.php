<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
  public function up(): void {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('employee_id')->unique();
        $table->string('last_name');
        $table->string('first_name');
        $table->enum('gender', ['M', 'F'])->default('M');
        $table->date('birth_date')->nullable();
        $table->string('phone');
        $table->string('email')->nullable();
        $table->string('job_title');
        $table->string('department');
        $table->enum('contract_type', ['CDI', 'CDD', 'Journalier']);
        $table->date('hire_date');
        // Remplace la ligne du salaire par celle-ci :
        $table->decimal('salary', 12, 2)->default(0)->nullable();
        $table->string('emergency_contact_name')->nullable();
        $table->string('emergency_contact_phone')->nullable();
        
        // Colonnes pour les fichiers (Chemins)
        $table->string('photo_path')->nullable();
        $table->string('cv_path')->nullable();
        
        $table->enum('status', ['Actif', 'Suspendu', 'Parti'])->default('Actif');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
