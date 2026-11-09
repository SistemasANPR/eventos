<?php

declare(strict_types=1);

use Tests\TestCase;

/*
 * Configuración de Pest. Las pruebas de Feature arrancan la aplicación de
 * Laravel; las de Unit y Arch no (lógica pura y reglas de arquitectura).
 */

pest()->extend(TestCase::class)->in('Feature');
