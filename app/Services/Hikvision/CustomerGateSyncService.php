<?php

namespace App\Services\Hikvision;

use Exception;
use Illuminate\Support\Facades\Log;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;
use Shaykhnazar\HikvisionIsapi\Services\CardService;
use Shaykhnazar\HikvisionIsapi\Services\FaceService;
use Shaykhnazar\HikvisionIsapi\Services\PersonService;

class CustomerGateSyncService
{
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

                $personService->add($person);
                $cardService->add($card);

                foreach ($faceImagesBase64 as $faceImageBase64) {
                    $faceService->uploadFace(
                        $person->employeeNo,
                        $faceImageBase64,
                        1
                    );
                }

                $results[$deviceName] = [
                    'status' => 'success',
                    'message' => 'Person and card credential synced successfully',
                    'face_synced' => ! empty($faceImagesBase64),
                    'face_image_count' => count($faceImagesBase64),
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
