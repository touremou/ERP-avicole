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
}
