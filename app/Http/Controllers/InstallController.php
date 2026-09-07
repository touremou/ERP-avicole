<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * InstallController — Assistant d'installation (premier démarrage).
 *
 * Étapes :
 *  1. welcome  — vérification des prérequis serveur
 *  2. database — configuration de la connexion base de données (.env)
 *  3. migrate  — exécution des migrations + seed de référence
 *  4. admin    — compte administrateur + informations entreprise
 *  5. finish   — pose du marqueur storage/installed
 */
class InstallController extends Controller
{
    /**
     * Étape 1 — Vérification des prérequis.
     */
    public function welcome()
    {
        $checks = $this->requirementChecks();
        $canProceed = collect($checks)->where('required', true)->every(fn($c) => $c['status']);

        return view('install.welcome', compact('checks', 'canProceed'));
    }

    /**
     * Étape 2 — Formulaire de configuration de la base de données.
     */
    public function database()
    {
        $current = [
            'connection' => env('DB_CONNECTION', 'mysql'),
            'host'       => env('DB_HOST', '127.0.0.1'),
            'port'       => env('DB_PORT', '3306'),
            'database'   => env('DB_DATABASE', 'avismart'),
            'username'   => env('DB_USERNAME', ''),
        ];

        return view('install.database', compact('current'));
    }

    /**
     * Étape 2 — Test + écriture de la configuration base de données.
     */
    public function storeDatabase(Request $request)
    {
        $data = $request->validate([
            'connection' => ['required', 'in:mysql,sqlite'],
            'host'       => ['required_if:connection,mysql', 'nullable', 'string'],
            'port'       => ['required_if:connection,mysql', 'nullable', 'string'],
            'database'   => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
            'username'   => ['required_if:connection,mysql', 'nullable', 'string'],
            'password'   => ['nullable', 'string'],
        ]);

        $env = ['DB_CONNECTION' => $data['connection']];

        if ($data['connection'] === 'sqlite') {
            $path = database_path('database.sqlite');

            if (! File::exists($path)) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, '');
            }

            $error = $this->testSqliteConnection($path);

            $env['DB_DATABASE'] = $path;
        } else {
            $error = $this->testMysqlConnection(
                $data['host'], $data['port'], $data['database'], $data['username'], $data['password'] ?? ''
            );

            $env['DB_HOST'] = $data['host'];
            $env['DB_PORT'] = $data['port'];
            $env['DB_DATABASE'] = $data['database'];
            $env['DB_USERNAME'] = $data['username'];
            $env['DB_PASSWORD'] = $data['password'] ?? '';
        }

        if ($error) {
            return back()->withInput()->withErrors(['database' => "Connexion impossible : {$error}"]);
        }

        $this->updateEnv($env);

        if (! env('APP_KEY')) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        Artisan::call('config:clear');

        return redirect()->route('install.migrate');
    }

    /**
     * Étape 3 — Page de lancement des migrations.
     */
    public function migrate()
    {
        return view('install.migrate');
    }

    /**
     * Étape 3 — Exécute les migrations et le seed de référence.
     */
    public function runMigrate()
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
            $migrateOutput = Artisan::output();

            Artisan::call('db:seed', ['--force' => true]);
            $seedOutput = Artisan::output();
        } catch (\Throwable $e) {
            return view('install.migrate', [
                'error'  => $e->getMessage(),
                'output' => ($migrateOutput ?? '') . "\n" . ($seedOutput ?? ''),
            ]);
        }

        return view('install.migrate', [
            'success' => true,
            'output'  => $migrateOutput . "\n" . $seedOutput,
        ]);
    }

    /**
     * Étape 4 — Formulaire compte administrateur + entreprise.
     */
    public function admin()
    {
        if (! Schema::hasTable('users')) {
            return redirect()->route('install.migrate');
        }

        $companyName = Setting::get('general.company_name', 'AviSmart');

        return view('install.admin', compact('companyName'));
    }

    /**
     * Étape 4 — Met à jour le compte admin + infos entreprise, retire le
     * compte de démonstration optionnel.
     */
    public function storeAdmin(Request $request)
    {
        $data = $request->validate([
            'company_name'          => ['required', 'string', 'max:255'],
            'admin_name'            => ['required', 'string', 'max:255'],
            'admin_email'           => ['required', 'email', 'max:255'],
            'admin_password'        => ['required', 'string', 'min:8', 'confirmed'],
            'remove_demo_account'   => ['nullable', 'boolean'],
        ]);

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Administrateur', 'label' => 'Administrateur', 'icon' => '👑', 'permissions' => ['L', 'C', 'M', 'S']]
        );

        $admin = User::where('email', 'admin@admin.com')->first()
            ?? User::where('role_id', $adminRole->id)->first();

        if ($admin) {
            $admin->update([
                'name'     => $data['admin_name'],
                'email'    => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
            ]);
        } else {
            User::create([
                'name'     => $data['admin_name'],
                'email'    => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role_id'  => $adminRole->id,
            ]);
        }

        if ($request->boolean('remove_demo_account')) {
            User::where('email', 'user@users.com')->delete();
        }

        Setting::set('general.company_name', $data['company_name']);

        return redirect()->route('install.finish');
    }

    /**
     * Étape 5 — Pose le marqueur d'installation et affiche la confirmation.
     */
    public function finish()
    {
        $alreadyInstalled = File::exists(storage_path('installed'));

        /*
         * ─── ON NE DÉCLARE PAS INSTALLÉ CE QUI NE L'EST PAS ───
         *
         * Cette étape est la SEULE du groupe à être hors de
         * `redirect.if.installed` — délibérément, pour que la page de
         * confirmation reste consultable une fois l'application installée. Mais
         * elle ne fait pas que montrer une page : elle bascule le `.env` en
         * production et POSE LE MARQUEUR d'installation.
         *
         * Sur une instance fraîchement déployée — donc publiquement joignable,
         * c'est tout l'objet d'un assistant web — n'importe quel visiteur
         * pouvait donc appeler `/install/finish` directement et marquer
         * l'application « installée » : sans base configurée, sans migrations,
         * sans compte administrateur.
         *
         * Et le marqueur referme la porte derrière lui : `redirect.if.installed`
         * renvoie dès lors tout l'assistant vers `/login`, où aucun compte
         * n'existe. L'installateur légitime est verrouillé dehors, et il faut
         * aller supprimer `storage/installed` sur le serveur pour s'en sortir.
         *
         * La finalisation exige donc que l'installation soit RÉELLEMENT allée
         * jusqu'au bout. Le témoin est le compte administrateur créé par l'étape
         * précédente (`storeAdmin`, qui redirige ici) : il n'existe que si la
         * base est configurée, migrée et peuplée. Une installation sans personne
         * pour se connecter n'est pas une installation terminée.
         */
        if (! $alreadyInstalled && ! $this->adminAccountExists()) {
            return redirect()->route('install.welcome')->with(
                'error',
                "L'installation n'est pas terminée : aucun compte administrateur n'existe encore. "
                . 'Reprenez l’assistant depuis le début.'
            );
        }

        if (! $alreadyInstalled) {
            // Bascule en mode PRODUCTION au terme de l'installation : sans cela,
            // une instance déployée depuis .env.example tournerait avec
            // APP_DEBUG=true (fuite de stack-traces = divulgation d'informations)
            // et APP_ENV=local (perfs et garde-fous dégradés).
            $this->updateEnv([
                'APP_ENV'   => 'production',
                'APP_DEBUG' => 'false',
            ]);
            Artisan::call('config:clear');

            File::put(storage_path('installed'), now()->toDateTimeString());
        }

        return view('install.finish', compact('alreadyInstalled'));
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    /**
     * Un administrateur peut-il se connecter ? Témoin d'une installation menée
     * à son terme : le compte n'existe que si la base est configurée, migrée et
     * peuplée par l'étape précédente.
     *
     * Toute panne de base signifie « pas installé » : sur une instance neuve,
     * `.env` ne porte pas encore de connexion valable et la requête lève.
     */
    private function adminAccountExists(): bool
    {
        try {
            $adminRoleId = Role::where('name', 'admin')->value('id');

            return $adminRoleId !== null
                && User::where('role_id', $adminRoleId)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function requirementChecks(): array
    {
        // Extensions INDISPENSABLES au fonctionnement de l'ERP (bloquantes).
        //  - sodium : vérification des licences signées (abonnement hors-ligne).
        //  - intl/zip : i18n, exports Excel et sauvegardes (spatie/laravel-backup).
        //  - gd : images + QR de traçabilité (endroid/qr-code).
        $extensions = [
            'pdo', 'mbstring', 'openssl', 'sodium', 'tokenizer',
            'xml', 'ctype', 'json', 'fileinfo', 'curl', 'gd', 'intl', 'zip',
        ];

        // Extensions RECOMMANDÉES (non bloquantes) : performance et robustesse.
        $recommended = [
            'opcache' => 'Cache d\'opcode — fortement conseillé en production',
            'bcmath'  => 'Calculs monétaires de précision (totaux, TVA)',
            'pcntl'   => 'Exécution des files d\'attente (queue:work)',
            'exif'    => 'Orientation correcte des photos importées',
        ];

        $checks = [
            [
                'label'    => 'PHP >= 8.3',
                'status'   => version_compare(PHP_VERSION, '8.3.0', '>='),
                'detail'   => 'Version actuelle : ' . PHP_VERSION,
                'required' => true,
            ],
        ];

        foreach ($extensions as $ext) {
            $checks[] = [
                'label'    => "Extension PHP : {$ext}",
                'status'   => extension_loaded($ext),
                'detail'   => extension_loaded($ext) ? 'Chargée' : 'Manquante',
                'required' => true,
            ];
        }

        $checks[] = [
            'label'    => 'Pilote PDO MySQL ou SQLite',
            'status'   => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'detail'   => 'Au moins un des deux pilotes est requis',
            'required' => true,
        ];

        foreach ($recommended as $ext => $why) {
            $checks[] = [
                'label'    => "Extension PHP : {$ext}",
                'status'   => extension_loaded($ext),
                'detail'   => extension_loaded($ext) ? 'Chargée' : $why,
                'required' => false,
            ];
        }

        // Marqueur de build protégé : si l'instance livrée au client est encodée
        // (ionCube/SourceGuardian), le loader doit être présent. Purement informatif
        // sur une instance en source clair (cas par défaut).
        $encoded = extension_loaded('ionCube Loader') || extension_loaded('SourceGuardian');
        $checks[] = [
            'label'    => 'Chargeur de code protégé (ionCube / SourceGuardian)',
            'status'   => $encoded,
            'detail'   => $encoded ? 'Présent — build protégé pris en charge' : 'Absent — requis uniquement pour un déploiement à code protégé',
            'required' => false,
        ];

        $writablePaths = [
            'storage/'              => storage_path(),
            'storage/framework/'    => storage_path('framework'),
            'storage/logs/'         => storage_path('logs'),
            'bootstrap/cache/'      => base_path('bootstrap/cache'),
        ];

        foreach ($writablePaths as $label => $path) {
            $checks[] = [
                'label'    => "Écriture : {$label}",
                'status'   => is_dir($path) && is_writable($path),
                'detail'   => is_dir($path) ? (is_writable($path) ? 'Accessible en écriture' : 'Non accessible en écriture') : 'Dossier introuvable',
                'required' => true,
            ];
        }

        $envPath = base_path('.env');
        $checks[] = [
            'label'    => 'Fichier .env accessible en écriture',
            'status'   => File::exists($envPath) ? is_writable($envPath) : is_writable(base_path()),
            'detail'   => File::exists($envPath) ? $envPath : 'Sera créé à partir de .env.example',
            'required' => true,
        ];

        return $checks;
    }

    private function testSqliteConnection(string $path): ?string
    {
        try {
            new \PDO('sqlite:' . $path);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    private function testMysqlConnection(string $host, string $port, string $database, string $username, string $password): ?string
    {
        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Met à jour (ou ajoute) des clés dans le fichier .env.
     */
    private function updateEnv(array $values): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            $example = base_path('.env.example');
            File::put($envPath, File::exists($example) ? File::get($example) : '');
        }

        $content = File::get($envPath);

        foreach ($values as $key => $value) {
            $line = $key . '=' . $this->envEscape($value);
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $line, $content);
            } else {
                $content = rtrim($content) . "\n" . $line . "\n";
            }
        }

        File::put($envPath, $content);
    }

    private function envEscape(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"' . addslashes($value) . '"';
        }

        return $value;
    }
}
