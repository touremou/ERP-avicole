<?php

namespace App\Support;

use App\Models\Farm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * LES SITES D'UN COMPTE — le cloisonnement multi-sites.
 *
 * Un compte n'agit que sur les sites auxquels il est RATTACHÉ (farm_user) :
 * le bureau (SetCurrentFarm) et le terrain (SetApiFarmContext) le bornent à
 * eux. Mais aucune des trois façons de créer un accès (page Utilisateurs,
 * fiche employé, création en masse) ne rattachait le compte : il retombait
 * sur la FERME PRINCIPALE — un technicien du site de Kindia travaillait dans
 * les lots de Conakry, où il n'était affecté nulle part.
 *
 *   • à la création, le compte est rattaché à son site (celui de l'employé, ou
 *     ceux choisis par l'administrateur) ; les comptes existants l'ont été par
 *     rattrapage (2026_11_05_000007) ;
 *   • on ne retire plus à un compte son DERNIER site : il retomberait sur la
 *     ferme principale (la « limite connue » de SetCurrentFarm) ;
 *   • l'écran Utilisateurs montre les sites de chacun, et signale qui n'en a pas.
 *
 * Le repli sur la ferme principale reste pour l'administrateur de
 * l'installation et l'installation mono-site, où il ne fuit rien.
 */
final class SitesDuCompte
{
    /**
     * Rattache le compte à ces sites (actifs), le premier devenant son site par
     * défaut s'il n'en a pas. Idempotent.
     *
     * @param  array<int, int|null>  $sites
     */
    public static function rattacher(User $compte, array $sites): void
    {
        $sites = Farm::active()->whereIn('id', array_filter(array_map('intval', $sites)))->pluck('id')->all();
        if ($sites === []) {
            return;
        }

        $aDejaUnDefaut = DB::table('farm_user')->where('user_id', $compte->id)->where('is_default', true)->exists();

        foreach ($sites as $i => $site) {
            DB::table('farm_user')->updateOrInsert(
                ['farm_id' => $site, 'user_id' => $compte->id],
                [
                    'is_default' => ! $aDejaUnDefaut && $i === 0,
                    'is_owner'   => DB::table('farm_user')->where('farm_id', $site)->where('user_id', $compte->id)->value('is_owner') ?? false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * Ces comptes perdraient-ils leur DERNIER site si on les retirait de
     * celui-ci ? Ils retomberaient sur la ferme principale.
     *
     * @param  array<int, int>  $comptes
     * @return array<int, string>  les noms de ceux qui n'auraient plus de site
     */
    public static function perdraientLeurDernierSite(int $site, array $comptes): array
    {
        if ($comptes === []) {
            return [];
        }

        $ailleurs = DB::table('farm_user')
            ->join('farms', 'farms.id', '=', 'farm_user.farm_id')
            ->whereIn('farm_user.user_id', $comptes)
            ->where('farm_user.farm_id', '!=', $site)
            ->where('farms.is_active', true)->whereNull('farms.deleted_at')
            ->pluck('farm_user.user_id')->unique()->all();

        return User::whereIn('id', array_diff($comptes, $ailleurs))
            ->whereDoesntHave('userRole', fn ($q) => $q->where('name', 'admin'))
            ->orderBy('name')->pluck('name')->all();
    }

    /** Les noms des sites du compte, pour l'écran Utilisateurs. */
    public static function nomsDesSites(User $compte): array
    {
        return DB::table('farm_user')
            ->join('farms', 'farms.id', '=', 'farm_user.farm_id')
            ->where('farm_user.user_id', $compte->id)
            ->whereNull('farms.deleted_at')
            ->orderBy('farms.name')
            ->pluck('farms.name')
            ->all();
    }
}
