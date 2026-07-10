<?php

namespace App\Http\Controllers;

use App\Services\Hikvision\CustomerGateSyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        CustomerGateSyncDispatcher $syncDispatcher
    ): JsonResponse {
        $this->verifySignature($request);

        $validated = $request->validate([
            'event' => 'required|in:customer.created,customer.updated,customer.deleted',
            'member_id' => 'required|string',
        ]);

        $deviceNames = $syncDispatcher->dispatch(
            $validated['member_id'],
            $validated['event']
        );

        return response()->json([
            'message' => 'Webhook accepted',
            'event' => $validated['event'],
            'member_id' => $validated['member_id'],
            'devices' => $deviceNames,
        ], 202);
    }

    private function verifySignature(Request $request): void
    {
        $secret = config('services.eresto_cloud.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return;
        }

        $signature = $request->header('X-Hub-Signature')
            ?? $request->header('X-Hub-Signature-256');

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        abort_if(
            ! is_string($signature) || ! hash_equals($expected, $signature),
            401,
            'Invalid webhook signature.'
        );
    }
}
