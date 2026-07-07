<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Hikvision\CustomerGatePayloadBuilder;
use App\Services\Hikvision\CustomerGateSyncService;
use Illuminate\Http\Request;

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
     *    "card_no": "CARD-M-1260-VJIV",
     *    "avatar_base64": "...",
     *    "face_images_base64": ["base64-front", "base64-left", "base64-right"]
     * }
     *
     * card_no optional dan default ke member_id.
     * begin_time/end_time + face_image_base64/avatar_base64 tetap didukung untuk backward compatibility.
     */
    public function syncCustomerToGates(
        Request $request,
        CustomerGatePayloadBuilder $payloadBuilder,
        CustomerGateSyncService $gateSyncService
    ) {
        $syncPayload = $payloadBuilder->build($this->validatedSyncPayload($request));

        return response()->json([
            'message' => 'Sync process completed',
            'data' => $gateSyncService->sync($syncPayload),
        ]);
    }

    private function validatedSyncPayload(Request $request): array
    {
        return $request->validate([
            'member_id' => 'required|string',
            'name' => 'required|string',
            'begin_time' => 'nullable|required_without:start_date|date',
            'start_date' => 'nullable|required_without:begin_time|date',
            'end_time' => 'nullable|required_without:end_date|date',
            'end_date' => 'nullable|required_without:end_time|date',
            'card_no' => 'nullable|string',
            'face_image_base64' => 'nullable|string',
            'face_images_base64' => 'nullable|array',
            'face_images_base64.*' => 'required|string',
            'avatar_base64' => 'nullable|string',
        ]);
    }
}
