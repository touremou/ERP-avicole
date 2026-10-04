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
     * Comptes de démonstration : email => [name, role name]. Mot de passe :
     * « password ». Déclaration UNIQUE de TOUS les comptes semés.
     *
     * `admin@admin.com` et `user@users.com` étaient créés à part, par
     * `DatabaseSeeder`. La liste ci-dessous, que l'assistant d'installation lit
     * pour reconnaître un compte de démonstration, ne les connaissait donc pas,
     * avec deux conséquences mesurées sur le VRAI semeur :
     *
     *   • l'assistant prenait `admin@admin.com` pour un administrateur RÉEL et
     *     se fermait à l'étape 4 — une installation neuve ne pouvait plus se
     *     terminer (régression introduite par #390, dont le test de borne
     *     n'utilisait pas ce semeur) ;
     *   • seul un compte était supprimé à la fin de l'installation : cinq
     *     comptes au mot de passe « password » restaient, dont
     *     `admin@avismart.com`, ADMINISTRATEUR.
     */
    public const USERS = [
        'admin@admin.com'         => ['Admin AviSmart', 'admin'],
        'user@users.com'          => ['User AviSmart', 'ouvrier'],
        'admin@avismart.com'      => ['Admin AviSmart', 'admin'],
        'technicien@avismart.com' => ['Technicien AviSmart', 'technicien'],
        'vendeur@avismart.com'    => ['Vendeur AviSmart', 'vendeur'],
        'ouvrier@avismart.com'    => ['Ouvrier AviSmart', 'ouvrier'],
    ];

    /** Le mot de passe de tous les comptes de démonstration. */
    public const MOT_DE_PASSE_DEMO = 'password';

    /**
     * Les adresses de TOUS les comptes semés — lues par l'assistant
     * d'installation et par le diagnostic, jamais recopiées ailleurs.
     *
     * @return array<int, string>
     */
    public static function emailsDeDemonstration(): array
    {
        return array_keys(self::USERS);
    }

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
                    'password'  => Hash::make(self::MOT_DE_PASSE_DEMO),
                    'role_id'   => $role?->id,
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info(sprintf(
            'UserSeeder : %d comptes de démonstration prêts (mot de passe public : %s) — l’assistant /install les supprime.',
            count(self::USERS),
            self::MOT_DE_PASSE_DEMO,
        ));
    }
}
