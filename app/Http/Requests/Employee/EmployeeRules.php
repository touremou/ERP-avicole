<?php

namespace App\Http\Requests\Employee;

use Illuminate\Validation\Rule;

/**
 * RÈGLES DE VALIDATION PARTAGÉES — source unique pour l'embauche et la fiche.
 *
 * Les deux formulaires avaient dérivé, et la dérive était invisible parce que
 * `$request->validated()` jette silencieusement tout champ non validé :
 *
 *   - `hire_date` et `gender` étaient exigés à l'embauche mais ABSENTS de la
 *     mise à jour : une date d'entrée fautive ne pouvait plus être corrigée,
 *     le champ partait à la poubelle sans un mot ;
 *   - `orange_money_number` et `assigned_building_id` étaient AFFICHÉS dans les
 *     deux formulaires et validés dans AUCUN : le responsable les saisissait,
 *     et ils disparaissaient (assigned_building_id n'était même pas fillable) ;
 *   - `contract_end_date` n'existait nulle part, alors que CDD et Journalier
 *     sont par définition des contrats à terme.
 *
 * D'où ce jeu commun : ce qui décrit un employé se déclare ici une seule fois.
 * Ce qui est propre à un moment du cycle de vie (unicité du téléphone à
 * l'embauche, statut RH sur la fiche) reste dans le FormRequest concerné.
 */
trait EmployeeRules
{
    /** @return array<string, mixed> */
    protected function commonEmployeeRules(): array
    {
        return [
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'gender'      => 'required|in:M,F',
            'birth_date'  => 'nullable|date|before:today',
            'email'       => 'nullable|email|max:255',
            'job_title'   => 'required|string|max:255',
            'department'  => 'required|string|max:255',
            'hire_date'   => 'required|date',
            'salary'      => 'nullable|numeric|min:0',

            'contract_type' => 'required|in:CDI,CDD,Journalier',
            // LE TERME. Obligatoire sur un contrat à durée déterminée — sans lui,
            // rien ne peut signaler l'échéance, donc rien ne déclenche la
            // décision de prolonger ou de notifier la fin. Interdit sur un CDI,
            // qui n'a pas de terme par nature : l'y accepter créerait une
            // échéance fantôme dans la liste de suivi.
            'contract_end_date' => [
                'nullable',
                'date',
                'after:hire_date',
                Rule::requiredIf(fn () => in_array($this->input('contract_type'), ['CDD', 'Journalier'], true)),
                Rule::prohibitedIf(fn () => $this->input('contract_type') === 'CDI'),
            ],

            // Affichés depuis toujours, validés par personne jusqu'ici.
            'orange_money_number'   => 'nullable|string|max:20',
            'assigned_building_id'  => ['nullable', 'integer', Rule::exists('buildings', 'id')],

            'emergency_contact_name'  => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',

            'photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'cv'    => 'nullable|mimes:pdf|max:2048',
        ];
    }

    /** @return array<string, string> */
    protected function employeeMessages(): array
    {
        return [
            'contract_end_date.required' => __("Un contrat CDD ou Journalier doit porter une date de fin : c'est elle qui déclenche la décision de prolonger ou d'émettre un préavis."),
            'contract_end_date.prohibited' => __("Un CDI n'a pas de date de fin. Choisissez CDD ou Journalier pour fixer un terme."),
            'contract_end_date.after' => __("La fin du contrat doit être postérieure à la date d'embauche."),
        ];
    }
}
