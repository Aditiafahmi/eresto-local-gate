<?php

namespace App\Services\Hikvision;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\DTOs\Card;
use Shaykhnazar\HikvisionIsapi\DTOs\Person;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;
use Shaykhnazar\HikvisionIsapi\Services\CardService;
use Shaykhnazar\HikvisionIsapi\Services\FaceService;
use Shaykhnazar\HikvisionIsapi\Services\PersonService;

class CustomerGateSyncService
{
    private const FACE_LIBRARY_ID = 1;

    private const FACE_FPID_MAX_BYTES = 63;

    public function sync(array $syncPayload): array
    {
        $person = $syncPayload['person'];
        $card = $syncPayload['card'];
        $faceImagesBase64 = $syncPayload['face_images_base64'];

        $results = [];

        foreach (Hikvision::availableDevices() as $deviceName) {
            try {
                $client = Hikvision::device($deviceName);
                $personService = new PersonService($client);
                $cardService = new CardService($client);
                $faceService = new FaceService($client);

                $personAction = $this->syncPerson($client, $personService, $person);
                $cardAction = $this->syncCard($cardService, $card);
                $faceActions = $this->syncFaces($faceService, $person->employeeNo, $faceImagesBase64);

                $results[$deviceName] = [
                    'status' => 'success',
                    'message' => "Person {$personAction} and card credential {$cardAction} successfully",
                    'person_action' => $personAction,
                    'card_action' => $cardAction,
                    'face_synced' => ! empty($faceActions),
                    'face_image_count' => count($faceActions),
                    'face_actions' => $faceActions,
                ];
            } catch (Exception $e) {
                Log::error("Failed to sync customer {$person->employeeNo} to gate {$deviceName}: ".$e->getMessage());

                $results[$deviceName] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function syncPerson(
        HikvisionClient $client,
        PersonService $personService,
        Person $person
    ): string {
        if ($this->personExists($client, $person->employeeNo)) {
            $personService->update($person);

            return 'updated';
        }

        $personService->add($person);

        return 'created';
    }

    private function syncCard(CardService $cardService, Card $card): string
    {
        if ($this->cardExists($cardService, $card)) {
            $cardService->update($card);

            return 'updated';
        }

        $cardService->add($card);

        return 'created';
    }

    private function syncFaces(FaceService $faceService, string $employeeNo, array $faceImagesBase64): array
    {
        $actions = [];
        $faceImagesBase64 = array_values($faceImagesBase64);

        foreach ($faceImagesBase64 as $index => $faceImageBase64) {
            $fpid = $this->facePictureId($employeeNo, $index, count($faceImagesBase64));
            $imageContent = $this->faceImageContent($faceImageBase64);

            if ($this->faceExists($faceService, $fpid)) {
                $faceService->modifyFaceRecord(self::FACE_LIBRARY_ID, $fpid, $imageContent);

                $actions[$fpid] = 'updated';

                continue;
            }

            $faceService->uploadFaceDataRecord(self::FACE_LIBRARY_ID, $fpid, $imageContent);

            $actions[$fpid] = 'created';
        }

        return $actions;
    }

    private function personExists(HikvisionClient $client, string $employeeNo): bool
    {
        $response = $client->post('/ISAPI/AccessControl/UserInfo/Search', [
            'UserInfoSearchCond' => [
                'searchID' => (string) Str::uuid(),
                'searchResultPosition' => 0,
                'maxResults' => 1,
                'employeeNo' => $employeeNo,
            ],
        ]);

        return ((int) data_get($response, 'UserInfoSearch.numOfMatches', 0) > 0)
            || ! empty(data_get($response, 'UserInfoSearch.UserInfo', []));
    }

    private function cardExists(CardService $cardService, Card $card): bool
    {
        return ! empty($cardService->search(employeeNo: $card->employeeNo));
    }

    private function faceExists(FaceService $faceService, string $fpid): bool
    {
        $response = $faceService->searchFace(
            maxResults: 1,
            fdid: self::FACE_LIBRARY_ID,
            fpid: $fpid
        );

        return ((int) data_get($response, 'numOfMatches', 0) > 0)
            || ((int) data_get($response, 'FaceSearch.numOfMatches', 0) > 0)
            || ! empty(data_get($response, 'MatchList', []))
            || ! empty(data_get($response, 'FaceDataRecord', []));
    }

    private function facePictureId(string $employeeNo, int $index, int $totalImages): string
    {
        $suffix = $totalImages > 1 ? '_'.($index + 1) : '';
        $maxBaseLength = self::FACE_FPID_MAX_BYTES - strlen($suffix);

        return substr($employeeNo, 0, $maxBaseLength).$suffix;
    }

    private function faceImageContent(string $faceImageBase64): string
    {
        $normalizedFaceImage = preg_replace('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', '', $faceImageBase64)
            ?? $faceImageBase64;
        $decoded = base64_decode($normalizedFaceImage, true);

        return $decoded === false ? $normalizedFaceImage : $decoded;
    }

    public function updateAccess(array $syncPayload): array
    {
        $person = $syncPayload['person'];
        $results = [];

        foreach (Hikvision::availableDevices() as $deviceName) {
            try {
                $client = Hikvision::device($deviceName);
                $personService = new PersonService($client);

                $personService->update($person);

                $results[$deviceName] = [
                    'status' => 'success',
                    'message' => 'Customer access period updated successfully',
                ];
            } catch (Exception $e) {
                Log::error("Failed to update customer {$person->employeeNo} on gate {$deviceName}: ".$e->getMessage());

                $results[$deviceName] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function delete(string $memberId): array
    {
        $results = [];

        foreach (Hikvision::availableDevices() as $deviceName) {
            try {
                $client = Hikvision::device($deviceName);
                $personService = new PersonService($client);
                $cardService = new CardService($client);

                $cardService->delete([$memberId]);
                $personService->delete([$memberId]);

                $results[$deviceName] = [
                    'status' => 'success',
                    'message' => 'Customer card and person data deleted successfully',
                ];
            } catch (Exception $e) {
                Log::error("Failed to delete customer {$memberId} from gate {$deviceName}: ".$e->getMessage());

                $results[$deviceName] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
