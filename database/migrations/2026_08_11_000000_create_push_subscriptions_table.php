<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ABONNEMENTS AU PUSH NAVIGATEUR (Web Push).
 *
 * Jusqu'ici, une alerte n'atteignait le terrain que si le technicien OUVRAIT
 * l'application : la cloche web et le centre d'alertes mobile sont des écrans, pas
 * des sonneries. Une mortalité critique à Kérouané attendait donc la prochaine
 * ouverture de l'app — ce qui, pour un promoteur à l'étranger, revient à ne pas
 * être alerté.
 *
 * Un abonnement est propre à un APPAREIL, pas à un compte : le même technicien
 * peut avoir le téléphone de service et le sien. On garde donc plusieurs lignes
 * par utilisateur, identifiées par leur `endpoint` (l'URL que le navigateur
 * fournit et que le serveur de push du fabricant reconnaît).
 *
 * `endpoint` est unique : un même appareil qui se réabonne (réinstallation,
 * changement de clef) doit mettre à jour sa ligne, pas en créer une seconde — on
 * enverrait sinon deux fois la même notification au même téléphone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // L'endpoint fait jusqu'à ~500 caractères chez certains fournisseurs :
            // on l'indexe sur un préfixe pour rester sous la limite de clef MySQL.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();

            // Clefs de chiffrement du message (le contenu voyage chiffré de bout
            // en bout : le serveur de push du fabricant ne peut pas le lire).
            $table->string('p256dh');
            $table->string('auth');

            // Aide au diagnostic terrain : quel appareil, et depuis quand muet.
            $table->string('device_label')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
