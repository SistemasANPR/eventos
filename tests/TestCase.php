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

        // El mailer «smtp» entrega a Mailpit (MAIL_HOST). `MAIL_MAILER=array` de phpunit.xml solo cambia
        // el mailer por defecto, y el adaptador SMTP usa este por su nombre: sin esto, las pruebas que
        // envían correo dependen de que haya un servidor SMTP (en CI no lo hay).
        config(['mail.mailers.smtp' => ['transport' => 'array']]);
    }
}
