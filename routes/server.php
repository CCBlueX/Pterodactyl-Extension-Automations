<?php

declare(strict_types=1);

use Automations\Http\Controllers\AutomationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AutomationController::class, 'index']);
Route::post('/preview', [AutomationController::class, 'preview'])->middleware('throttle:60,1');
Route::post('/automations', [AutomationController::class, 'store']);
Route::put('/automations/{id}', [AutomationController::class, 'update'])->whereNumber('id');
Route::put('/automations/{id}/enabled', [AutomationController::class, 'toggle'])->whereNumber('id');
Route::delete('/automations/{id}', [AutomationController::class, 'destroy'])->whereNumber('id');
Route::post('/automations/{id}/run', [AutomationController::class, 'run'])->whereNumber('id')->middleware('throttle:10,1');
Route::post('/automations/{id}/cancel', [AutomationController::class, 'cancel'])->whereNumber('id');
Route::get('/automations/{id}/runs', [AutomationController::class, 'runs'])->whereNumber('id');
