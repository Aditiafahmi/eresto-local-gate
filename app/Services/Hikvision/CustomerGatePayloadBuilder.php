<?php

namespace App\Services\Hikvision;

use Illuminate\Support\Carbon;
use Shaykhnazar\HikvisionIsapi\DTOs\Card;
use Shaykhnazar\HikvisionIsapi\DTOs\Person;
use Shaykhnazar\HikvisionIsapi\Enums\UserType;

class CustomerGatePayloadBuilder
{
    public function build(array $customer): array
    {
        $cardNo = $customer['card_no'] ?? $customer['member_id'];
        $faceImagesBase64 = $this->faceImagesBase64($customer);
        $faceImageBase64 = $faceImagesBase64[0] ?? null;

        $person = new Person(
            employeeNo: $customer['member_id'],
            name: $customer['name'],
            userType: UserType::NORMAL,
            validEnabled: true,
            beginTime: Carbon::parse($customer['start_date'])->format('Y-m-d\TH:i:s'),
            endTime: Carbon::parse($customer['end_date'])->format('Y-m-d\TH:i:s'),
            doorRight: '1',
            rightPlan: [
                ['doorNo' => 1, 'planTemplateNo' => '1'],
            ]
        );

        $card = new Card(
            employeeNo: $person->employeeNo,
            cardNo: $cardNo,
            cardType: 'normal'
        );

        return [
            'person' => $person,
            'card' => $card,
            'face_image_base64' => $faceImageBase64,
            'face_images_base64' => $faceImagesBase64,
            'payloads' => [
                'person' => $person->toArray(),
                'card' => $card->toArray(),
                'face' => $faceImageBase64 ? [
                    'faceInfo' => [
                        'employeeNo' => $person->employeeNo,
                        'faceLibType' => 'blackFD',
                    ],
                    'faceData' => $faceImageBase64,
                ] : null,
                'face_samples' => array_map(
                    fn (string $faceImage) => [
                        'faceInfo' => [
                            'employeeNo' => $person->employeeNo,
                            'faceLibType' => 'blackFD',
                        ],
                        'faceData' => $faceImage,
                    ],
                    $faceImagesBase64
                ),
            ],
            'endpoints' => [
                'person' => [
                    'method' => 'POST',
                    'path' => '/ISAPI/AccessControl/UserInfo/Record',
                ],
                'card' => [
                    'method' => 'POST',
                    'path' => '/ISAPI/AccessControl/CardInfo/Record',
                ],
                'face' => [
                    'method' => 'POST',
                    'path' => '/ISAPI/Intelligent/FDLib/1/picture',
                ],
            ],
        ];
    }

    private function faceImagesBase64(array $customer): array
    {
        if (! empty($customer['face_images_base64']) && is_array($customer['face_images_base64'])) {
            return array_values(array_filter(
                $customer['face_images_base64'],
                fn ($faceImage) => is_string($faceImage) && $faceImage !== ''
            ));
        }

        $singleFaceImage = $customer['face_image_base64'] ?? $customer['avatar_base64'] ?? null;

        return is_string($singleFaceImage) && $singleFaceImage !== ''
            ? [$singleFaceImage]
            : [];
    }
}
