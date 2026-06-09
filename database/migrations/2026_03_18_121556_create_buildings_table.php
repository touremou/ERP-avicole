<?php



use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('buildings', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // Nom du bâtiment (ex: Hangar A)
        $table->string('type'); // Ex: Poussinère, Grandissement, Ponte
        $table->integer('capacity'); // Capacité maximale d'oiseaux
        $table->text('description')->nullable(); // Détails optionnels
        $table->boolean('is_active')->default(true); // État du bâtiment
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
