<?php

namespace Tests\Support;

use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;

class RecordingHikvisionHttpClient implements HttpClientInterface
{
    public array $posts = [];

    public array $puts = [];

    public function get(string $uri, array $options = []): array
    {
        return ['status' => 'ok'];
    }

    public function post(string $uri, array $data = [], array $options = []): array
    {
        $this->posts[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

        return ['status' => 'ok'];
    }

    public function put(string $uri, array $data = [], array $options = []): array
    {
        $this->puts[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

        return ['status' => 'ok'];
    }

    public function delete(string $uri, array $options = []): array
    {
        return ['status' => 'ok'];
    }

    public function postMultipart(string $uri, array $multipart = [], array $options = []): array
    {
        return ['status' => 'ok'];
    }
}
