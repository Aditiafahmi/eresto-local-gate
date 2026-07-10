<?php

namespace App\Services\Cloud;

use App\DTOs\CloudCustomerData;
use App\DTOs\CloudCustomerDeltaData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudCustomerClient
{
    public function findCustomer(string $memberId): CloudCustomerData
    {
        $response = $this->request()
            ->get('/api/customers/'.rawurlencode($memberId));

        return CloudCustomerData::fromArray(
            $this->responseData($response->throw()->json()),
            $memberId
        );
    }

    public function delta(?string $since = null): CloudCustomerDeltaData
    {
        $query = [];

        if ($since !== null && $since !== '') {
            $query['since'] = $since;
        }

        $response = $this->request()
            ->get('/api/customers/delta', $query);

        $responseData = $response->throw()->json();

        if (! is_array($responseData)) {
            throw new RuntimeException('Eresto Cloud returned an invalid delta JSON response.');
        }

        return CloudCustomerDeltaData::fromArray($responseData);
    }

    public function markFaceEnrolled(string $memberId): void
    {
        $this->request()
            ->post('/api/customers/'.rawurlencode($memberId).'/enrol-face')
            ->throw();
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('services.eresto_cloud.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('Eresto Cloud base URL is not configured. Please set ERESTO_CLOUD_URL.');
        }

        $request = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->timeout((int) config('services.eresto_cloud.timeout', 10));

        $token = config('services.eresto_cloud.token');

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function responseData(mixed $response): array
    {
        if (! is_array($response)) {
            throw new RuntimeException('Eresto Cloud returned an invalid JSON response.');
        }

        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return $response;
    }
}
