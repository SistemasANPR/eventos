<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// El inicio de sesión del panel de Filament es el único acceso de organizadores (CA-001-34).
// La ruta con nombre `login` existe porque el framework redirige a ella a los invitados;
// `/register` no existe y responde 404.
Route::redirect('/login', '/admin/login')->name('login');
