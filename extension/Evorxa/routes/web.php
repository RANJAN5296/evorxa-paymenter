<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Evorxa\Http\AssetController;
use Paymenter\Extensions\Servers\Evorxa\Http\ClientApiController;

// Client panel JSON endpoint: session + CSRF (web), logged in (auth), service policy checked in the controller.
Route::middleware(['web', 'auth'])
    ->post('/evorxa/services/{service}/api', ClientApiController::class)
    ->where('service', '[0-9]+')
    ->name('evorxa.api');

// Panel assets (CSS, JS, OS and app logos).
Route::get('/evorxa/assets/{path}', AssetController::class)
    ->where('path', '.*')
    ->name('evorxa.asset');
