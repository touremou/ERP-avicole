<?php

namespace App\Rules;

use App\Models\Batch;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Interdit toute date d'événement (pointage, intervention sanitaire, collecte…)
 * antérieure à l'arrivée du lot.
 *
 * Cohérence métier : un lot n'existe physiquement qu'à partir de sa date
 * d'arrivée. Saisir un pointage ou un traitement avant cette date produit un
 * âge négatif et fausse tous les indicateurs (FCR, GMQ, courbes de poids…).
 *
 * Le lot est résolu depuis le champ `batch_id` du même formulaire (DataAware).
 * Si le lot ou sa date d'arrivée sont absents, la règle ne bloque pas
 * (d'autres règles — exists, required — prennent le relais).
 */
class AfterBatchArrival implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function __construct(protected string $batchIdField = 'batch_id') {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $batchId = $this->data[$this->batchIdField] ?? null;

        if (! $batchId || ! $value) {
            return;
        }

        $batch = Batch::find($batchId);

        if (! $batch || ! $batch->arrival_date) {
            return;
        }

        $arrival = Carbon::parse($batch->arrival_date)->startOfDay();

        if (Carbon::parse($value)->startOfDay()->lt($arrival)) {
            $fail("La date ne peut pas précéder l'arrivée du lot ({$arrival->format('d/m/Y')}).");
        }
    }
}
