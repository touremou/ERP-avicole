<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Résolution de la fenêtre temporelle des journaux mobiles.
 *
 * Un paramètre `period` (today | yesterday | 7days) sélectionne la plage de
 * dates ; par défaut « today ». Utilisé par tous les journaux datés
 * (ventes, trésorerie, provenderie, abattoir, cultures) pour un contrat
 * homogène côté PWA.
 */
class JournalPeriod
{
    /** @return array{start: Carbon, end: Carbon, key: string, label: string} */
    public static function resolve(Request $request): array
    {
        $key = (string) $request->query('period', 'today');

        return match ($key) {
            'yesterday' => [
                'start' => today()->subDay()->startOfDay(),
                'end'   => today()->subDay()->endOfDay(),
                'key'   => 'yesterday',
                'label' => 'Hier',
            ],
            '7days' => [
                'start' => today()->subDays(6)->startOfDay(),
                'end'   => today()->endOfDay(),
                'key'   => '7days',
                'label' => '7 derniers jours',
            ],
            default => [
                'start' => today()->startOfDay(),
                'end'   => today()->endOfDay(),
                'key'   => 'today',
                'label' => "Aujourd'hui",
            ],
        };
    }

    /**
     * Série journalière (Y-m-d) couvrant la plage, valeurs à 0. Sert à
     * construire les graphiques temporels : on la remplit ensuite par jour.
     *
     * @return array<string, float>
     */
    public static function dailyBuckets(Carbon $start, Carbon $end): array
    {
        $buckets = [];
        for ($day = $start->copy()->startOfDay(); $day->lte($end); $day->addDay()) {
            $buckets[$day->toDateString()] = 0.0;
        }

        return $buckets;
    }

    /**
     * Sérialise des buckets {date => valeur} en liste [{date, value}] ordonnée.
     *
     * @param array<string, float> $buckets
     * @return list<array{date: string, value: float}>
     */
    public static function series(array $buckets): array
    {
        $out = [];
        foreach ($buckets as $date => $value) {
            $out[] = ['date' => $date, 'value' => round((float) $value, 2)];
        }

        return $out;
    }
}
