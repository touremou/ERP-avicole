<?php

namespace App\Contracts;

/**
 * ACTIF SOUMIS À MAINTENANCE PRÉVENTIVE.
 *
 * Ce contrat existe pour une raison précise : deux familles d'actifs portaient
 * déjà un indicateur `needs_maintenance`, et une seule était RELIÉE à quoi que
 * ce soit. Le groupe électrogène engendrait une tâche 48 h avant l'échéance et
 * figurait au résumé quotidien ; la machine de provenderie, elle, ne faisait
 * que teinter un badge sur un écran du bureau — pour un promoteur qui vit à
 * l'étranger, c'est-à-dire personne.
 *
 * L'indicateur avait donc des LECTEURS mais aucun ACTEUR. Nommer le contrat
 * rend la chose vérifiable : un test dérivé exige que tout modèle exposant
 * `needs_maintenance` l'implémente ET soit parcouru par `maintenance:check`.
 * Une troisième famille d'actifs ne pourra plus être oubliée en silence.
 */
interface MaintainableAsset
{
    /** L'entretien est-il dû (ou imminent) ? */
    public function isMaintenanceDue(): bool;

    /** Entretien DÉPASSÉ, et non simplement proche : fixe la priorité de la tâche. */
    public function isMaintenanceOverdue(): bool;

    /** Nom de l'actif, tel qu'il apparaîtra dans le titre de la tâche. */
    public function maintenanceLabel(): string;

    /** Ce que l'intervenant doit savoir : échéance, compteur, points à vérifier. */
    public function maintenanceDetail(): string;
}
