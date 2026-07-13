<?php

namespace App\Services\Mock;

use Illuminate\Support\Facades\File;
use RuntimeException;

class HikvisionStateStore
{
    private string $deviceName;

    private string $deviceDirectory;

    private string $stateFile;

    public function __construct()
    {
        $this->deviceName = (string) config(
            'mock.hikvision.device_name',
            'mock-device'
        );

        $basePath = (string) config(
            'mock.hikvision.storage_path',
            storage_path('app/mock-hikvision')
        );

        $safeDeviceName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $this->deviceName)
            ?: 'mock-device';

        $this->deviceDirectory = rtrim($basePath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$safeDeviceName;
        $this->stateFile = $this->deviceDirectory.DIRECTORY_SEPARATOR.'state.json';
    }

    public function snapshot(): array
    {
        return $this->read(function (array $state): array {
            return [
                'device' => $this->deviceName,
                'persons' => array_values($state['persons']),
                'cards' => array_values($state['cards']),
                'faces' => array_values($state['faces']),
            ];
        });
    }

    public function searchPersons(?string $employeeNo = null): array
    {
        return $this->read(function (array $state) use ($employeeNo): array {
            if ($employeeNo !== null && $employeeNo !== '') {
                return isset($state['persons'][$employeeNo])
                    ? [$state['persons'][$employeeNo]]
                    : [];
            }

            return array_values($state['persons']);
        });
    }

    public function upsertPerson(array $person): string
    {
        $employeeNo = $this->requiredString($person, 'employeeNo');

        return $this->update(function (array &$state) use ($employeeNo, $person): string {
            $action = isset($state['persons'][$employeeNo]) ? 'updated' : 'created';
            $state['persons'][$employeeNo] = $person;

            return $action;
        });
    }

    public function deletePersons(array $employeeNos): int
    {
        return $this->update(function (array &$state) use ($employeeNos): int {
            $deleted = 0;

            foreach ($employeeNos as $employeeNo) {
                if (! is_string($employeeNo) || $employeeNo === '') {
                    continue;
                }

                if (isset($state['persons'][$employeeNo])) {
                    unset($state['persons'][$employeeNo]);
                    $deleted++;
                }

                foreach ($state['faces'] as $fpid => $face) {
                    if ($fpid !== $employeeNo && ! str_starts_with($fpid, $employeeNo.'_')) {
                        continue;
                    }

                    $this->deleteFaceFile($face);
                    unset($state['faces'][$fpid]);
                }
            }

            return $deleted;
        });
    }

    public function searchCards(?string $employeeNo = null, ?string $cardNo = null): array
    {
        return $this->read(function (array $state) use ($employeeNo, $cardNo): array {
            return array_values(array_filter(
                $state['cards'],
                static function (array $card) use ($employeeNo, $cardNo): bool {
                    if ($employeeNo !== null && $employeeNo !== ''
                        && ($card['employeeNo'] ?? null) !== $employeeNo) {
                        return false;
                    }

                    return $cardNo === null
                        || $cardNo === ''
                        || ($card['cardNo'] ?? null) === $cardNo;
                }
            ));
        });
    }

    public function upsertCard(array $card): string
    {
        $employeeNo = $this->requiredString($card, 'employeeNo');
        $this->requiredString($card, 'cardNo');

        return $this->update(function (array &$state) use ($employeeNo, $card): string {
            $action = isset($state['cards'][$employeeNo]) ? 'updated' : 'created';
            $state['cards'][$employeeNo] = $card;

            return $action;
        });
    }

    public function deleteCards(array $employeeNos): int
    {
        return $this->update(function (array &$state) use ($employeeNos): int {
            $deleted = 0;

            foreach ($employeeNos as $employeeNo) {
                if (is_string($employeeNo) && isset($state['cards'][$employeeNo])) {
                    unset($state['cards'][$employeeNo]);
                    $deleted++;
                }
            }

            return $deleted;
        });
    }

    public function searchFaces(?string $fpid = null, ?string $fdid = null): array
    {
        return $this->read(function (array $state) use ($fpid, $fdid): array {
            return array_values(array_filter(
                $state['faces'],
                static function (array $face) use ($fpid, $fdid): bool {
                    if ($fpid !== null && $fpid !== '' && ($face['FPID'] ?? null) !== $fpid) {
                        return false;
                    }

                    return $fdid === null
                        || $fdid === ''
                        || (string) ($face['FDID'] ?? '') === $fdid;
                }
            ));
        });
    }

    public function upsertFace(array $record, string $imageContent): string
    {
        $fpid = $this->requiredString($record, 'FPID');
        $this->requiredString($record, 'FDID');

        return $this->update(function (array &$state) use ($fpid, $record, $imageContent): string {
            $action = isset($state['faces'][$fpid]) ? 'updated' : 'created';
            $facesDirectory = $this->deviceDirectory.DIRECTORY_SEPARATOR.'faces';
            File::ensureDirectoryExists($facesDirectory);

            $safeFpid = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fpid) ?: 'face';
            $relativePath = 'faces/'.$safeFpid.'-'.substr(sha1($fpid), 0, 10).'.jpg';
            File::put(
                $this->deviceDirectory.DIRECTORY_SEPARATOR.$relativePath,
                $imageContent
            );

            $state['faces'][$fpid] = array_merge($record, [
                'file' => $relativePath,
                'bytes' => strlen($imageContent),
                'sha256' => hash('sha256', $imageContent),
            ]);

            return $action;
        });
    }

    public function reset(): void
    {
        $this->update(function (array &$state): void {
            foreach ($state['faces'] as $face) {
                $this->deleteFaceFile($face);
            }

            $state = $this->emptyState();
        });
    }

    private function read(callable $callback): mixed
    {
        return $this->withLockedState($callback, LOCK_SH, false);
    }

    private function update(callable $callback): mixed
    {
        return $this->withLockedState($callback, LOCK_EX, true);
    }

    private function withLockedState(callable $callback, int $lockType, bool $write): mixed
    {
        File::ensureDirectoryExists($this->deviceDirectory);

        $handle = fopen($this->stateFile, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open mock Hikvision state file.');
        }

        try {
            if (! flock($handle, $lockType)) {
                throw new RuntimeException('Unable to lock mock Hikvision state file.');
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $decoded = is_string($contents) && $contents !== ''
                ? json_decode($contents, true)
                : null;
            $state = is_array($decoded) ? $decoded : $this->emptyState();
            $state = array_merge($this->emptyState(), $state);

            $result = $callback($state);

            if ($write) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite(
                    $handle,
                    json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
                );
                fflush($handle);
            }

            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function emptyState(): array
    {
        return [
            'persons' => [],
            'cards' => [],
            'faces' => [],
        ];
    }

    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Mock Hikvision payload requires {$key}.");
        }

        return $value;
    }

    private function deleteFaceFile(array $face): void
    {
        $relativePath = $face['file'] ?? null;

        if (is_string($relativePath) && $relativePath !== '') {
            File::delete($this->deviceDirectory.DIRECTORY_SEPARATOR.$relativePath);
        }
    }
}
