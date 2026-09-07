<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class RecordMirage
{
    public function execute(Incubation $incubation, array $data): Incubation
    {
        return DB::transaction(function () use ($incubation, $data) {
            if ($incubation->status === 'clos') {
                throw new \DomainException("Impossible d'effectuer un mirage sur un cycle clôturé.");
            }

            $fertile = (int) $data['fertile_eggs'];

            /*
             * LE TAUX EST DÉRIVÉ, ON NE L'ÉCRIT PAS.
             *
             * Cette ligne écrivait `fertility_rate`. Elle n'écrivait rien :
             * la colonne est absente du `$fillable` d'`Incubation`, et
             * `update()` passe par `fill()`, qui jette sans un mot toute clé non
             * listée. Le taux mesuré sortait NULL en base à chaque mirage.
             *
             * La déclaration vivante est l'accesseur
             * `Incubation::getFertilityRateAttribute` (fertiles ÷ mis à couver),
             * exposé par `$appends` : tous les écrans le lisent déjà. Stocker en
             * plus une copie d'une valeur dérivée, c'est la faire diverger un
             * jour — le défaut qu'on répare ailleurs dans ce module.
             */
            $incubation->update([
                'fertile_eggs' => $fertile,
                'status'       => 'mirage_fait',
            ]);

            return $incubation->fresh();
        });
    }
}
