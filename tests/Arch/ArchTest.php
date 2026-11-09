<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/*
 * Pruebas de arquitectura (guardianas). Convierten reglas de AGENTS.md y
 * app/CLAUDE.md en fallos reales. Si una regla deja de aplicar, se cambia aquí
 * con una decisión registrada; no se debilita sin más.
 *
 * Verificar la sintaxis contra la versión de Pest instalada: el API de
 * `arch()` y de los presets puede cambiar entre versiones mayores.
 */

arch()->preset()->php();
arch()->preset()->security();

arch('every application file declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('env() is only read inside config files')
    ->expect('env')
    ->not->toBeUsedIn(['App', 'Database']);

arch('models extend Eloquent and know nothing about HTTP or panels')
    ->expect('App\Models')
    ->toExtend(Model::class)
    ->ignoring('App\Models\Concerns')
    ->not->toUse(['Illuminate\Http', 'App\Http', 'App\Filament']);

arch('model concerns are traits')
    ->expect('App\Models\Concerns')
    ->toBeTraits();

arch('models never call actions')
    ->expect('App\Actions')
    ->not->toBeUsedIn('App\Models');

arch('actions are classes with a handle() method')
    ->expect('App\Actions')
    ->toBeClasses()
    ->toHaveMethod('handle');

arch('controllers do not touch the database directly')
    ->expect('Illuminate\Support\Facades\DB')
    ->not->toBeUsedIn('App\Http\Controllers');

arch('policies are suffixed with Policy')
    ->expect('App\Policies')
    ->toHaveSuffix('Policy');

arch('form requests extend FormRequest and end in Request')
    ->expect('App\Http\Requests')
    ->toExtend(FormRequest::class)
    ->toHaveSuffix('Request');

arch('enums live in App\Enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('domain exceptions extend Exception')
    ->expect('App\Exceptions\Domain')
    ->toExtend(Exception::class);
