<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;
use Shaykhnazar\HikvisionIsapi\Services\PersonService;
use Shaykhnazar\HikvisionIsapi\Services\CardService;
use Shaykhnazar\HikvisionIsapi\Services\FaceService;
use Shaykhnazar\HikvisionIsapi\DTOs\Person;
use Shaykhnazar\HikvisionIsapi\DTOs\Card;
use Shaykhnazar\HikvisionIsapi\Enums\UserType;
use Exception;
use Illuminate\Support\Facades\Log;

class HikvisionSyncController extends Controller
{
    /**
     * Endpoint ini akan di-hit oleh Eresto Cloud.
     * Payload expected:
     * {
     *    "member_id": "M-1260-VJIV",
     *    "name": "Aditia",
     *    "start_date": "2024-06-01T00:00:00",
     *    "end_date": "2025-06-01T00:00:00",
     *    "avatar_base64": "..."
     * }
     *
     * qr_code optional dan default ke member_id.
     * begin_time/end_time + face_image_base64 tetap didukung untuk backward compatibility.
     */
    public function syncCustomerToGates(Request $request)
    {
        // Validasi payload dari cloud
        $validated = $request->validate([
            'member_id' => 'required|string',
            'name' => 'required|string',
            'begin_time' => 'nullable|required_without:start_date|date',
            'start_date' => 'nullable|required_without:begin_time|date',
            'end_time' => 'nullable|required_without:end_date|date',
            'end_date' => 'nullable|required_without:end_time|date',
            'qr_code' => 'nullable|string',
            'face_image_base64' => 'nullable|string',
            'avatar_base64' => 'nullable|string',
        ]);

        $beginTime = $validated['begin_time'] ?? $validated['start_date'];
        $endTime = $validated['end_time'] ?? $validated['end_date'];
        $qrCode = $validated['qr_code'] ?? $validated['member_id'];
        $faceImageBase64 = $validated['face_image_base64'] ?? $validated['avatar_base64'] ?? null;

        // Buat objek Person (Customer) sesuai format package Hikvision.
        $person = new Person(
            employeeNo: $validated['member_id'],
            name: $validated['name'],
            userType: UserType::NORMAL,
            validEnabled: true,
            beginTime: date('Y-m-d\TH:i:s', strtotime($beginTime)),
            endTime: date('Y-m-d\TH:i:s', strtotime($endTime)),
            doorRight: '1',
            rightPlan: [
                ['doorNo' => 1, 'planTemplateNo' => '1']
            ]
        );

        // QR diperlakukan sebagai CardInfo/cardNo supaya device bisa mengenali credential scan QR.
        $card = new Card(
            employeeNo: $person->employeeNo,
            cardNo: $qrCode,
            cardType: 'normal'
        );

        // Ambil semua daftar gate (device) yang sudah dikonfigurasi di config/hikvision.php
        $devices = Hikvision::availableDevices();
        $results = [];

        foreach ($devices as $deviceName) {
            try {
                // Connect ke device (Gate)
                $client = Hikvision::device($deviceName);
                $personService = new PersonService($client);
                $cardService = new CardService($client);
                $faceService = new FaceService($client);

                // 1. Daftarkan Data Person ke Mesin
                $personService->add($person);

                // 2. Daftarkan QR ke Mesin sebagai CardInfo
                $cardService->add($card);

                // 3. Optional: daftarkan Foto Wajah ke Mesin kalau payload mengirim face image.
                if (!empty($faceImageBase64)) {
                    $faceService->uploadFace(
                        $person->employeeNo,
                        $faceImageBase64,
                        1
                    );
                }

                $results[$deviceName] = [
                    'status' => 'success',
                    'message' => 'Person and QR credential synced successfully',
                    'face_synced' => !empty($faceImageBase64),
                ];
            } catch (Exception $e) {
                Log::error("Failed to sync customer {$person->employeeNo} to gate {$deviceName}: " . $e->getMessage());
                
                $results[$deviceName] = [
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'message' => 'Sync process completed',
            'data' => $results
        ]);
    }
}
