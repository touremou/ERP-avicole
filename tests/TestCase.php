<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Le cache des paramètres (store « array » en test) n'est pas réinitialisé
        // par RefreshDatabase : sans ça, l'instantané d'un test précédent fuit sur
        // le suivant (setting() renvoie des valeurs périmées). On force un cache
        // propre à chaque test.
        Setting::clearCache();
    }
}
