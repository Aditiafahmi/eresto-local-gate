<?php

namespace App\Services\Hikvision;

use App\DTOs\CloudCustomerData;
use Illuminate\Support\Carbon;
use Shaykhnazar\HikvisionIsapi\DTOs\Card;
use Shaykhnazar\HikvisionIsapi\DTOs\Person;
use Shaykhnazar\HikvisionIsapi\Enums\UserType;

class CustomerGatePayloadBuilder
{
    public function build(CloudCustomerData $customer): array
    {
        $person = new Person(
            employeeNo: $customer->memberId,
            name: $customer->name,
            userType: UserType::NORMAL,
            validEnabled: $customer->accessEnabled(),
            beginTime: Carbon::parse($customer->startDate)->format('Y-m-d\TH:i:s'),
            endTime: Carbon::parse($customer->endDate)->format('Y-m-d\TH:i:s'),
            doorRight: '1',
            rightPlan: [
                ['doorNo' => 1, 'planTemplateNo' => '1'],
            ]
        );

        $card = new Card(
            employeeNo: $person->employeeNo,
            cardNo: $customer->cardNumber(),
            cardType: 'normal'
        );

        return [
            'person' => $person,
            'card' => $card,
            'face_images_base64' => $customer->faceImagesBase64,
        ];
    }
}
