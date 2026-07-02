<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HikvisionSyncController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Route untuk sinkronisasi dari Eresto Cloud ke Mesin Hikvision
Route::post('/sync-customer', [HikvisionSyncController::class, 'syncCustomerToGates']);