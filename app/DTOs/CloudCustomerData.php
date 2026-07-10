<?php

namespace App\DTOs;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class CloudCustomerData
{
    public function __construct(
        public string $memberId,
        public string $name,
        public string $startDate,
        public string $endDate,
        public string $status,
        public ?string $cardNo,
        public array $faceImagesBase64
    ) {}

    public static function fromArray(array $customer, ?string $expectedMemberId = null): self
    {
        $customer['status'] = $customer['status'] ?? 'active';
        $customer['card_no'] = $customer['card_no'] ?? null;
        $customer['face_images_base64'] = $customer['face_images_base64'] ?? [];

        $memberIdRules = ['bail', 'required', 'string'];

        if ($expectedMemberId !== null) {
            $memberIdRules[] = Rule::in([$expectedMemberId]);
        }

        $validated = Validator::make($customer, [
            'member_id' => $memberIdRules,
            'name' => ['bail', 'required', 'string'],
            'start_date' => ['bail', 'required', 'date'],
            'end_date' => ['bail', 'required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'card_no' => ['nullable', 'string'],
            'face_images_base64' => ['array'],
            'face_images_base64.*' => ['required', 'string'],
        ], [
            'member_id.in' => 'The Cloud customer member_id does not match the requested customer.',
        ])->validate();

        return new self(
            memberId: $validated['member_id'],
            name: $validated['name'],
            startDate: $validated['start_date'],
            endDate: $validated['end_date'],
            status: $validated['status'],
            cardNo: $validated['card_no'],
            faceImagesBase64: array_values($validated['face_images_base64'])
        );
    }

    public function cardNumber(): string
    {
        return $this->cardNo !== null && $this->cardNo !== ''
            ? $this->cardNo
            : $this->memberId;
    }

    public function accessEnabled(): bool
    {
        return $this->status === 'active';
    }
}
