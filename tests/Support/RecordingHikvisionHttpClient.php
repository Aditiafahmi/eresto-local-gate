<?php

namespace Tests\Support;

use RuntimeException;
use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;

class RecordingHikvisionHttpClient implements HttpClientInterface
{
    public array $posts = [];

    public array $puts = [];

    public array $searches = [];

    public array $postMultiparts = [];

    public array $putMultiparts = [];

    private array $existingCardEmployeeNos = [];

    private array $existingFaceFpids = [];

    private array $existingPersonEmployeeNos = [];

    private array $failingHosts = [];

    public function withFailingHost(string $host): self
    {
        $this->failingHosts[] = $host;

        return $this;
    }

    public function withExistingPerson(string $employeeNo): self
    {
        $this->existingPersonEmployeeNos[] = $employeeNo;

        return $this;
    }

    public function withExistingCard(string $employeeNo): self
    {
        $this->existingCardEmployeeNos[] = $employeeNo;

        return $this;
    }

    public function withExistingFace(string $fpid): self
    {
        $this->existingFaceFpids[] = $fpid;

        return $this;
    }

    public function get(string $uri, array $options = []): array
    {
        $this->failWhenConfigured($uri);

        return ['status' => 'ok'];
    }

    public function post(string $uri, array $data = [], array $options = []): array
    {
        $this->failWhenConfigured($uri);

        if (str_contains($uri, '/ISAPI/AccessControl/UserInfo/Search')) {
            return $this->recordUserSearch($uri, $data, $options);
        }

        if (str_contains($uri, '/ISAPI/AccessControl/CardInfo/Search')) {
            return $this->recordCardSearch($uri, $data, $options);
        }

        if (str_contains($uri, '/ISAPI/Intelligent/FDLib/FDSearch')) {
            return $this->recordFaceSearch($uri, $data, $options);
        }

        $this->posts[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

        return ['status' => 'ok'];
    }

    public function put(string $uri, array $data = [], array $options = []): array
    {
        $this->failWhenConfigured($uri);

        $this->puts[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

        return ['status' => 'ok'];
    }

    public function delete(string $uri, array $options = []): array
    {
        $this->failWhenConfigured($uri);

        return ['status' => 'ok'];
    }

    public function postMultipart(string $uri, array $multipart = [], array $options = []): array
    {
        $this->failWhenConfigured($uri);

        $this->postMultiparts[] = [
            'uri' => $uri,
            'multipart' => $multipart,
            'options' => $options,
        ];

        return ['status' => 'ok'];
    }

    public function putMultipart(string $uri, array $multipart = [], array $options = []): array
    {
        $this->failWhenConfigured($uri);

        $this->putMultiparts[] = [
            'uri' => $uri,
            'multipart' => $multipart,
            'options' => $options,
        ];

        return ['status' => 'ok'];
    }

    private function recordUserSearch(string $uri, array $data, array $options): array
    {
        $this->searches[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

        $employeeNo = $data['UserInfoSearchCond']['employeeNo'] ?? null;

        if (is_string($employeeNo) && in_array($employeeNo, $this->existingPersonEmployeeNos, true)) {
            return [
                'UserInfoSearch' => [
                    'numOfMatches' => 1,
                    'UserInfo' => [
                        [
                            'employeeNo' => $employeeNo,
                            'name' => 'Existing Customer',
                            'userType' => 'normal',
                            'Valid' => ['enable' => true],
                        ],
                    ],
                ],
            ];
        }

        return [
            'UserInfoSearch' => [
                'numOfMatches' => 0,
                'UserInfo' => [],
            ],
        ];
    }

    private function recordCardSearch(string $uri, array $data, array $options): array
    {
        $this->searches[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

        $employeeNo = $data['CardInfoSearchCond']['employeeNo'] ?? null;

        if (is_string($employeeNo) && in_array($employeeNo, $this->existingCardEmployeeNos, true)) {
            return [
                'CardInfoSearch' => [
                    'numOfMatches' => 1,
                    'CardInfo' => [
                        [
                            'employeeNo' => $employeeNo,
                            'cardNo' => 'CARD-'.$employeeNo,
                            'cardType' => 'normal',
                        ],
                    ],
                ],
            ];
        }

        return [
            'CardInfoSearch' => [
                'numOfMatches' => 0,
                'CardInfo' => [],
            ],
        ];
    }

    private function recordFaceSearch(string $uri, array $data, array $options): array
    {
        $this->searches[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

        $fpid = $data['FPID'] ?? null;

        if (is_string($fpid) && in_array($fpid, $this->existingFaceFpids, true)) {
            return [
                'numOfMatches' => 1,
                'MatchList' => [
                    ['FPID' => $fpid],
                ],
            ];
        }

        return [
            'numOfMatches' => 0,
            'MatchList' => [],
        ];
    }

    private function failWhenConfigured(string $uri): void
    {
        foreach ($this->failingHosts as $host) {
            if (str_contains($uri, '://'.$host.':')) {
                throw new RuntimeException("Simulated Hikvision connection failure for {$host}");
            }
        }
    }
}
