<?php

namespace App\Http\Controllers\Mock;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudCustomerController extends Controller
{
    public function show(string $memberId): JsonResponse
    {
        $this->ensureAvailable();

        $customers = config('mock.cloud.customers', []);
        $customer = is_array($customers) ? ($customers[$memberId] ?? null) : null;

        abort_unless(is_array($customer), 404, 'Mock customer not found.');

        return response()->json(['data' => $customer]);
    }

    public function delta(Request $request): JsonResponse
    {
        $this->ensureAvailable();

        $validated = $request->validate([
            'since' => ['nullable', 'string'],
        ]);

        $nextCursor = (string) config('mock.cloud.next_cursor', 'mock-cursor-1');
        $changes = ($validated['since'] ?? null) === $nextCursor
            ? []
            : config('mock.cloud.changes', []);

        return response()->json([
            'data' => is_array($changes) ? array_values($changes) : [],
            'next_cursor' => $nextCursor,
        ]);
    }

    public function markFaceEnrolled(string $memberId): JsonResponse
    {
        $this->ensureAvailable();

        $customers = config('mock.cloud.customers', []);

        abort_unless(
            is_array($customers) && isset($customers[$memberId]),
            404,
            'Mock customer not found.'
        );

        return response()->json([
            'message' => 'Mock face enrolment accepted.',
            'member_id' => $memberId,
            'face_enrolled' => true,
        ]);
    }

    private function ensureAvailable(): void
    {
        abort_unless(
            app()->environment(['local', 'testing'])
                && (bool) config('mock.cloud.enabled', false),
            404
        );
    }
}
