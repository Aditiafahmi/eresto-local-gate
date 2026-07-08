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
        $validEnabled = ($customer['status'] ?? 'active') === 'active';

        $person = new Person(
            employeeNo: $customer['member_id'],
            name: $customer['name'],
            userType: UserType::NORMAL,
            validEnabled: $validEnabled,
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
            'face_images_base64' => $faceImagesBase64,
        ];
    }

    private function faceImagesBase64(array $customer): array
    {
        return array_values(array_filter(
            $customer['face_images_base64'] ?? [],
            fn ($faceImage) => is_string($faceImage) && $faceImage !== ''
        ));
    }
}
