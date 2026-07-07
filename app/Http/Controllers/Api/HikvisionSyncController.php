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
     *    "name": "Customer Name",
     *    "start_date": "2024-06-01T00:00:00",
     *    "end_date": "2025-06-01T00:00:00",
     *    "status": "active",
     *    "card_no": "CARD-M-1260-VJIV",
     *    "avatar_base64": "...",
     *    "face_images_base64": ["base64-front", "base64-left", "base64-right"]
     * }
     *
     * card_no optional dan default ke member_id.
     * face_image_base64/avatar_base64 tetap didukung untuk backward compatibility.
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

    public function updateCustomerAccessOnGates(
        string $memberId,
        Request $request,
        CustomerGatePayloadBuilder $payloadBuilder,
        CustomerGateSyncService $gateSyncService
    ) {
        $validated = $this->validatedAccessUpdatePayload($request);
        $validated['member_id'] = $memberId;

        $syncPayload = $payloadBuilder->build($validated);

        return response()->json([
            'message' => 'Update process completed',
            'data' => $gateSyncService->updateAccess($syncPayload),
        ]);
    }

    public function deleteCustomerFromGates(
        string $memberId,
        CustomerGateSyncService $gateSyncService
    ) {
        return response()->json([
            'message' => 'Delete process completed',
            'data' => $gateSyncService->delete($memberId),
        ]);
    }

    private function validatedSyncPayload(Request $request): array
    {
        return $request->validate([
            'member_id' => 'required|string',
            'name' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'status' => 'nullable|in:active,inactive',
            'card_no' => 'nullable|string',
            'face_image_base64' => 'nullable|string',
            'face_images_base64' => 'nullable|array',
            'face_images_base64.*' => 'required|string',
            'avatar_base64' => 'nullable|string',
        ]);
    }

    private function validatedAccessUpdatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'status' => 'nullable|in:active,inactive',
        ]);
    }
}
