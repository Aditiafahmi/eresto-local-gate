<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Hikvision\CustomerGateSyncStatusStore;
use Illuminate\Http\JsonResponse;

class HikvisionSyncStatusController extends Controller
{
    public function __invoke(
        string $memberId,
        CustomerGateSyncStatusStore $statusStore
    ): JsonResponse {
        $status = $statusStore->get($memberId);

        if ($status === null) {
            return response()->json([
                'message' => 'No sync status found for this customer.',
                'member_id' => $memberId,
            ], 404);
        }

        return response()->json(['data' => $status]);
    }
}
