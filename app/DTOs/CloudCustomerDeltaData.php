<?php

namespace App\DTOs;

use Illuminate\Support\Facades\Validator;

final readonly class CloudCustomerDeltaData
{
    /**
     * @param  list<CloudCustomerDeltaChangeData>  $changes
     */
    public function __construct(
        public array $changes,
        public string $nextCursor
    ) {}

    public static function fromArray(array $response): self
    {
        $validated = Validator::make($response, [
            'data' => ['present', 'array', 'max:500'],
            'data.*' => ['required', 'array'],
            'data.*.member_id' => ['bail', 'required', 'string'],
            'data.*.event' => ['required', 'string', 'in:customer.created,customer.updated,customer.deleted'],
            'data.*.modified_at' => ['required', 'date'],
            'next_cursor' => ['required', 'string'],
        ])->validate();

        $changes = array_map(
            fn (array $change) => new CloudCustomerDeltaChangeData(
                memberId: $change['member_id'],
                event: $change['event'],
                modifiedAt: $change['modified_at']
            ),
            array_values($validated['data'])
        );

        return new self(
            changes: $changes,
            nextCursor: $validated['next_cursor']
        );
    }

    /**
     * Keep only the final ordered event for each customer in this delta page.
     *
     * @return list<CloudCustomerDeltaChangeData>
     */
    public function latestChanges(): array
    {
        $latestByMember = [];

        foreach ($this->changes as $change) {
            unset($latestByMember[$change->memberId]);
            $latestByMember[$change->memberId] = $change;
        }

        return array_values($latestByMember);
    }
}
