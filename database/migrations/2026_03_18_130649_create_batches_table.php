<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::dropIfExists('batches');

    Schema::create('batches', function (Blueprint $table) {
        $table->id();
        $table->foreignId('building_id')->constrained()->onDelete('cascade');
        $table->foreignId('provider_id')->constrained()->onDelete('cascade');
        $table->foreignId('employee_id')->constrained()->onDelete('cascade');
        
        $table->string('code')->unique(); 
        $table->string('responsible'); 
        $table->enum('breeding_type', ['Chair', 'Pondeuse', 'Reproducteur']);
        
        // unsigned() empêche les valeurs négatives au niveau SQL
        $table->integer('age_at_arrival')->unsigned()->default(1);
        $table->decimal('avg_weight_start', 8, 3)->unsigned(); 
        
        $table->integer('qty_alive')->unsigned(); 
        $table->integer('qty_dead')->unsigned();  
        $table->integer('initial_quantity')->unsigned(); 
        $table->integer('current_quantity')->unsigned(); 
        
        $table->string('chick_state'); 
        $table->text('behavior')->nullable();
        
        $table->boolean('vaccination_received')->default(false);
        $table->text('vaccination_details')->nullable();
        
        $table->date('arrival_date');
        $table->date('expected_end_date');
        
        // 10 chiffres au total, 2 après la virgule, pas de négatif
        $table->decimal('buy_price_per_unit', 10, 2)->unsigned();
        $table->decimal('total_acquisition_cost', 12, 2)->unsigned();
        
        $table->decimal('planned_density', 8, 2)->unsigned(); 
        $table->decimal('arrival_mortality_rate', 5, 2)->unsigned(); 
        
        $table->string('status')->default('Actif'); 
        $table->text('observations')->nullable();
        $table->string('photo_path')->nullable(); 
        
        $table->timestamps();
    });
}
    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};