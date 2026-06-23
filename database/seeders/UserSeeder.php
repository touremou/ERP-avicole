<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de démarrage par type d'utilisateur.
 *
 * Crée quatre rôles (Administrateur, Technicien, Vendeur, Ouvrier) avec leur
 * matrice de permissions L/C/M/S, initialise la table `module_permissions`
 * (source de vérité des Gates) pour chacun, puis crée un utilisateur de test
 * par rôle. Évite d'avoir à recréer des comptes en tinker après un refresh.
 *
 * Idempotent : firstOrCreate sur les clés naturelles (roles.name, users.email)
 * et updateOrCreate sur module_permissions. Relançable sans doublon.
 *
 * Mot de passe par défaut pour tous les comptes : « password ».
 */
class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Définition des rôles : name => [display_name, label, icon, permissions LCMS].
     *
     *  L = Lire, C = Créer, M = Modifier, S = Supprimer.
     */
    private const ROLES = [
        'admin' => [
            'display_name' => 'Administrateur',
            'label'        => 'Administrateur',
            'icon'         => '👑',
            'description'  => 'Accès complet à tous les modules (gestion, paramétrage, suppression).',
            'permissions'  => ['L', 'C', 'M', 'S'],
        ],
        'technicien' => [
            'display_name' => 'Technicien',
            'label'        => 'Technicien',
            'icon'         => '🧑‍🔧',
            'description'  => 'Suit et met à jour les opérations terrain (lecture, création, modification).',
            'permissions'  => ['L', 'C', 'M'],
        ],
        'vendeur' => [
            'display_name' => 'Vendeur',
            'label'        => 'Vendeur',
            'icon'         => '🧑‍💼',
            'description'  => 'Saisit les ventes et consulte les données (lecture, création).',
            'permissions'  => ['L', 'C'],
        ],
        'ouvrier' => [
            'display_name' => 'Ouvrier',
            'label'        => 'Ouvrier',
            'icon'         => '👷',
            'description'  => 'Consultation seule des modules.',
            'permissions'  => ['L'],
        ],
    ];

    /**
     * Comptes de test : email => [name, role name].
     */
    private const USERS = [
        'admin@avismart.com'      => ['Admin AviSmart', 'admin'],
        'technicien@avismart.com' => ['Technicien AviSmart', 'technicien'],
        'vendeur@avismart.com'    => ['Vendeur AviSmart', 'vendeur'],
        'ouvrier@avismart.com'    => ['Ouvrier AviSmart', 'ouvrier'],
    ];

    public function run(): void
    {
        // Tous les modules existants : la matrice de permissions couvre chacun
        // d'eux pour que les Gates L/C/M/S répondent dès la connexion.
        $moduleIds = Module::query()->pluck('id');

        foreach (self::ROLES as $name => $data) {
            $role = Role::firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $data['display_name'],
                    'label'        => $data['label'],
                    'icon'         => $data['icon'],
                    'description'  => $data['description'],
                    'permissions'  => $data['permissions'],
                ]
            );

            // Aligne systématiquement la matrice LCMS globale (utile si le rôle
            // préexistait avec d'autres valeurs).
            $role->forceFill(['permissions' => $data['permissions']])->save();

            // Initialise / met à jour module_permissions à partir du LCMS global.
            $perms = $data['permissions'];
            foreach ($moduleIds as $moduleId) {
                \App\Models\ModulePermission::updateOrCreate(
                    ['role_id' => $role->id, 'module_id' => $moduleId],
                    [
                        'can_read'   => in_array('L', $perms, true),
                        'can_create' => in_array('C', $perms, true),
                        'can_modify' => in_array('M', $perms, true),
                        'can_delete' => in_array('S', $perms, true),
                    ]
                );
            }
        }

        foreach (self::USERS as $email => [$displayName, $roleName]) {
            $role = Role::where('name', $roleName)->first();

            User::firstOrCreate(
                ['email' => $email],
                [
                    'name'      => $displayName,
                    'password'  => Hash::make('password'),
                    'role_id'   => $role?->id,
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info('UserSeeder : 4 rôles et 4 comptes de test prêts (mot de passe : password).');
    }
}
