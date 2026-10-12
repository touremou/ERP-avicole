<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * UN NOMBRE DE JOURS, C'EST UN ENTIER DE CALENDRIER.
 *
 * Depuis Carbon 3, `diffInDays()` rend un nombre DÉCIMAL et SIGNÉ. Mesuré
 * contre `now()`, il porte les heures de la journée : un lot arrivé ce matin
 * affichait « 0.8118930709375J » à sa clôture, et l'âge du jour (1.81…) ne
 * tombait jamais sur le J1 entier d'un protocole. Mesuré à rebours, il est
 * négatif : « dernier poids il y a -3 jours » ne s'affichait plus du tout.
 *
 * Une déclaration : le nombre de jours de calendrier de `$debut` à `$fin`
 * (minuit à minuit), négatif si `$fin` précède `$debut`.
 */
final class Jours
{
    public static function entre(DateTimeInterface|string $debut, DateTimeInterface|string|null $fin = null): int
    {
        $de = Carbon::parse($debut)->startOfDay();
        $a  = $fin === null ? now()->startOfDay() : Carbon::parse($fin)->startOfDay();

        return (int) round($de->diffInDays($a, false));
    }
}
