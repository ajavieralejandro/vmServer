<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\PiletasBridgeController;

Route::post('/auth/login', [AuthController::class, 'login']);

// ✅ Registro no-socio (público)
Route::post('/auth/register', [AuthController::class, 'register']);

// ✅ Recuperación por email (público)
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // ✅ Cambiar contraseña estando logueado
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    // ✅ puente: VM -> Piletas
    Route::post('/piletas/token', [PiletasBridgeController::class, 'token']);
});
