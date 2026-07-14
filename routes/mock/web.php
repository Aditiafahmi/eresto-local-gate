<?php

use App\Http\Controllers\Mock\CloudCustomerController;
use App\Http\Controllers\Mock\HikvisionIsapiController;
use App\Http\Middleware\Mock\HikvisionDigestAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('mock-cloud/api')->group(function () {
    Route::get('/customers/{memberId}', [CloudCustomerController::class, 'show']);
    Route::post('/customers/{memberId}/enrol-face', [CloudCustomerController::class, 'markFaceEnrolled']);
});

Route::prefix('ISAPI')->middleware(HikvisionDigestAuth::class)->group(function () {
    Route::post('/AccessControl/UserInfo/Search', [HikvisionIsapiController::class, 'personSearch']);
    Route::post('/AccessControl/UserInfo/Record', [HikvisionIsapiController::class, 'personRecord']);
    Route::put('/AccessControl/UserInfo/Modify', [HikvisionIsapiController::class, 'personRecord']);
    Route::put('/AccessControl/UserInfo/Delete', [HikvisionIsapiController::class, 'personDelete']);

    Route::post('/AccessControl/CardInfo/Search', [HikvisionIsapiController::class, 'cardSearch']);
    Route::post('/AccessControl/CardInfo/Record', [HikvisionIsapiController::class, 'cardRecord']);
    Route::put('/AccessControl/CardInfo/Modify', [HikvisionIsapiController::class, 'cardRecord']);
    Route::put('/AccessControl/CardInfo/Delete', [HikvisionIsapiController::class, 'cardDelete']);

    Route::post('/Intelligent/FDLib/FDSearch', [HikvisionIsapiController::class, 'faceSearch']);
    Route::post('/Intelligent/FDLib/FaceDataRecord', [HikvisionIsapiController::class, 'faceRecord']);
    Route::put('/Intelligent/FDLib/FDModify', [HikvisionIsapiController::class, 'faceModify']);
});

Route::get('/__mock/hikvision/state', [HikvisionIsapiController::class, 'state']);
Route::delete('/__mock/hikvision/state', [HikvisionIsapiController::class, 'reset']);
