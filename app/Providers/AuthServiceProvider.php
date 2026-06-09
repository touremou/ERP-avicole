<?php
namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Extensions\OfflineUserProvider; // L'extension créée précédemment
use Illuminate\Support\Facades\Auth;


class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    public function boot(): void
    {
        $this->registerPolicies(); // ✅ La méthode est bien ici par défaut

        // Enregistrement de notre fournisseur d'utilisateurs robuste
        Auth::provider('offline_eloquent', function ($app, array $config) {
            return new OfflineUserProvider($app['hash'], $config['model']);
        });
    }
}