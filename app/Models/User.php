<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, LogsActivity;

    /**
     * Audit des comptes : on journalise les changements sensibles (rôle, email,
     * activation…) MAIS jamais le mot de passe ni le token (logExcept).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'role_id', 'whatsapp_phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('audit');
    }

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'whatsapp_phone', 'is_active', 'locale', 'avatar_path',
    ];

    /**
     * À qui attribuer une écriture faite HORS session utilisateur.
     *
     * Plusieurs écritures traçables (mouvement de stock, ordre de production…)
     * portent un auteur NOT NULL. Quand le geste vient d'un observer, d'une
     * commande planifiée ou d'un import, il n'y a personne derrière : le code
     * repliait alors sur l'identifiant 1, écrit en dur.
     *
     * Ce 1 n'est garanti nulle part. Sur une base où le premier compte a été
     * supprimé, l'écriture entière échoue — et la seule façon de s'en rendre
     * compte est de la voir échouer. On résout donc un compte qui EXISTE.
     */
    public static function systemActorId(): ?int
    {
        return \Illuminate\Support\Facades\Auth::id()
            ?? static::query()->orderBy('id')->value('id');
    }

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // ─── RELATIONS ───

    /**
     * URL publique de la photo de profil (disque public via /media), partagée
     * par le web ET le mobile. Null si aucune photo → repli sur les initiales.
     *
     * REPLI sur la photo de la FICHE EMPLOYÉ : le visage d'une personne est un
     * seul visage, et il vivait dans deux champs indépendants
     * (users.avatar_path, employees.photo_path). Un agent photographié par son
     * responsable apparaissait donc en initiales sur son propre téléphone.
     *
     * On ne retombe QUE sur une vraie photo : jamais sur l'avatar SVG génénique
     * par genre, sinon les initiales — plus reconnaissables — disparaîtraient.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar_path) {
            return media_url($this->avatar_path);
        }

        $employeePhoto = $this->employee?->photo_path;

        return $employeePhoto ? media_url($employeePhoto) : null;
    }

    public function userRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Fiche employé (RH) rattachée à ce compte de connexion, le cas échéant.
     */
    /**
     * Fiche employé rattachée à ce compte.
     *
     * HORS SCOPE DE FERME, et c'est le cœur du correctif : « suis-je un
     * employé ? » est une propriété de la PERSONNE, pas du site actuellement
     * sélectionné. Avec le scope, un technicien dont la fiche est rattachée à
     * Kérouané perdait l'accès à SES tâches dès que l'application résolvait
     * Kindia — le mobile affichait « votre compte n'est pas rattaché à une fiche
     * employé » alors que le web, qui avait résolu l'autre ferme, l'affichait
     * correctement. Même compte, même base, deux réponses.
     *
     * Aucune fuite : le lien est un `user_id` direct, on ne lit que SA propre
     * fiche. Les données de la ferme (tâches, lots) restent bornées par leurs
     * propres scopes.
     *
     * `employees.user_id` porte une contrainte UNIQUE : un compte n'a au plus
     * qu'UNE fiche. Aucun départage n'est donc nécessaire — et si le mobile ne
     * trouve rien alors que le web trouve, c'est qu'il s'agit de DEUX comptes
     * différents pour la même personne (cf. `php artisan hr:diagnose-account`).
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class)
            ->withoutGlobalScope(\App\Scopes\FarmScope::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    /**
     * Chaque nouveau compte reçoit ses préférences d'alerte.
     *
     * Elles n'étaient créées qu'en ouvrant l'écran des réglages : un compte qui
     * n'y allait jamais ne recevait aucune alerte in-app, et rien ne le disait.
     * Le code n'en dépend plus, mais la ligne existe désormais dès la création —
     * la ferme la VOIT et peut la régler, plutôt que de subir un implicite.
     */
    protected static function booted(): void
    {
        static::created(function (self $user) {
            \Illuminate\Support\Facades\DB::table('notification_preferences')->insertOrIgnore(
                array_merge(NotificationPreference::DEFAULTS, [
                    'user_id'    => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        });
    }

    public function dashboardConfiguration(): HasOne
    {
        return $this->hasOne(DashboardConfiguration::class);
    }

    // ─── RBAC GLOBAL (rétrocompatible) ───

    /**
     * Vérifie une permission « globale » (L, C, M, S) — déléguée au rôle, dont
     * la SOURCE UNIQUE est la matrice `module_permissions`. L'administrateur a
     * un accès total (cohérent avec Gate::before / AppServiceProvider).
     */
    public function hasPermission(string $permissionName): bool
    {
        if (! $this->role_id) return false;

        if ($this->hasRole('admin')) return true;

        $this->loadMissing('userRole');

        return $this->userRole?->hasPermission($permissionName) ?? false;
    }

    // ─── RBAC PAR MODULE ───

    /**
     * Vérifie si l'utilisateur a une permission spécifique sur un module.
     *
     * Usage :
     *   $user->canModule('elevage', 'C')    // Peut créer dans le module Élevage ?
     *   $user->canModule('abattoir', 'L')   // Peut lire le module Abattoir ?
     *   $user->canModule('admin', 'S')      // Peut supprimer dans Administration ?
     *
     * La matrice Modules × Rôles (`module_permissions`) est seule autorité :
     * chaque rôle possède une ligne par module (cf. migrations
     * 2026_06_10_000004 et 2026_06_14_000001). Le rôle "admin" reste
     * bypassé partout via Gate::before / AppServiceProvider.
     */
    public function canModule(string $moduleSlug, string $level): bool
    {
        if (! $this->role_id) return false;

        if ($this->hasRole('admin')) return true;

        $modulePerm = ModulePermission::where('role_id', $this->role_id)
            ->whereHas('module', fn($q) => $q->where('slug', $moduleSlug))
            ->first();

        return $modulePerm && $modulePerm->hasLevel($level);
    }

    /**
     * Récupère tous les modules accessibles par l'utilisateur (au moins lecture).
     */
    public function getAccessibleModules(): \Illuminate\Support\Collection
    {
        if (! $this->role_id) return collect();

        if ($this->hasRole('admin')) {
            $modules = Module::active()->get();
        } else {
            $explicitModuleIds = ModulePermission::where('role_id', $this->role_id)
                ->where('can_read', true)
                ->pluck('module_id');

            $modules = Module::active()->whereIn('id', $explicitModuleIds)->get();
        }

        // Le module Administration n'a AUCUNE fonction en lecture seule : toutes
        // ses routes exigent admin.S. On ne présente donc sa tuile qu'aux
        // utilisateurs réellement habilités (admin.S) — sinon un simple droit
        // admin.L afficherait une tuile morte et trompeuse dans le lanceur.
        if (\Illuminate\Support\Facades\Gate::forUser($this)->denies('admin.S')) {
            $modules = $modules->reject(fn ($m) => $m->slug === 'admin')->values();
        }

        // Verrou d'abonnement : on masque les modules hors licence (tuiles du
        // lanceur, navigation). Sans effet si le système de licence est inactif.
        $licenses = app(\App\Services\LicenseService::class);
        if ($licenses->isEnabled()) {
            $modules = $modules->filter(fn ($m) => $licenses->allowsModule($m->slug))->values();
        }

        return $modules->values();
    }

    /**
     * Matrice complète des permissions par module pour ce user.
     */
    public function getModulePermissionsMatrix(): array
    {
        $modules = Module::active()->get();
        $matrix = [];

        foreach ($modules as $module) {
            $matrix[$module->slug] = [
                'module' => $module,
                'L' => $this->canModule($module->slug, 'L'),
                'C' => $this->canModule($module->slug, 'C'),
                'M' => $this->canModule($module->slug, 'M'),
                'S' => $this->canModule($module->slug, 'S'),
            ];
        }

        return $matrix;
    }

    // ─── HELPERS ───

    public function hasRole(string $roleName): bool
    {
        $this->loadMissing('userRole');
        return $this->userRole && $this->userRole->name === $roleName;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasPermission('S');
    }

    /**
     * Le compte est-il actif (autorisé à se connecter) ?
     * Rétrocompatible : un null (colonne absente / ancien compte) = actif.
     */
    public function isActive(): bool
    {
        return $this->is_active === null ? true : (bool) $this->is_active;
    }

    /**
     * Route d'atterrissage après connexion, adaptée au profil.
     *
     * - Superviseurs (admin / manager / permission S) → tableau de bord global.
     * - Employé rattaché à une fiche RH → son espace personnel.
     * - Sinon → tableau de bord.
     */
    public function homeRoute(): string
    {
        if ($this->isSuperAdmin() || $this->hasRole('admin') || $this->hasRole('manager')) {
            return 'dashboard';
        }

        if ($this->employee()->exists()) {
            return 'mon-espace';
        }

        return 'dashboard';
    }
}
