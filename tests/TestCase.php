<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ninguna prueba necesita el frontend compilado: el panel y el sitio publico
     * cargan sus activos con Vite, y sin `public/build` (como en el job de backend
     * de CI) responderian 500 por no hallar el manifiesto.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
