<?php

use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\InitializationController;


Route::post('/', [InitializationController::class, 'initialize']);

Route::get('/', [InitializationController::class, 'isInitialized']);
