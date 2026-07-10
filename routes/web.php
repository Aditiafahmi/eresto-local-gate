<?php

use App\Http\Controllers\Api\HikvisionSyncStatusController;
use App\Http\Controllers\CloudWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/cloud/webhook', CloudWebhookController::class);
Route::get('/admin/status/{memberId}', HikvisionSyncStatusController::class);
