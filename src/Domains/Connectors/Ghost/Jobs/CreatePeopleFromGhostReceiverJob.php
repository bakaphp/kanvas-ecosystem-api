<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ghost\Jobs;

use Baka\Support\Str;
use Kanvas\Connectors\Ghost\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Spatie\LaravelData\DataCollection;

class CreatePeopleFromGhostReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload['member']['current'] ?? [];

        if (empty($payload)) {
            return [
                'message' => 'No data found',
                'payload' => $this->webhookRequest->payload,
            ];
        }

        if ($payload['name']) {
            $name = explode(' ', $payload['name']);
            $firstname = $name[0];
            $lastname = $name[1] ?? null;
        } else {
            $name = explode('@', $payload['email']);
            $firstname = $name[0];
            $lastname = null;
        }

        $customerEmail = [
            [
                'value' => $payload['email'],
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => 0,
            ],
        ];

        $tags = [];
        $customFields = [
            [
                'key' => 'status',
                'value' => true,
            ],
            [
                'key' => 'paid_subscription',
                'value' => $payload['status'] !== 'free',
            ],
            [
                'key' => 'subscribed_to_emails',
                'value' => true,
            ],
            [
                'key' => CustomFieldEnum::GHOST_MEMBER_ID->value,
                'value' => $payload['id'],
            ],
            [
                'key' => CustomFieldEnum::GHOST_MEMBER_UUID->value,
                'value' => $payload['uuid'],
            ],
        ];
        $unlockedReports = [];
        if (isset($payload['labels']) && empty($payload['labels'])) {
            foreach ($payload['labels'] as $label) {
                if (Str::contains($label['name'], ':')) {
                    // Split "key:value" into key and value for custom fields
                    [$key, $value] = explode(':', $label['name'], 2);
                    $customFields[] = [
                        'key' => $key,
                        'value' => $value,
                    ];
                    if ($key === 'report') {
                        $tags[] = $label['name'];
                        $unlockedReports[] = $value;
                    }
                } else {
                    $tags[] = $label['name'];
                }
            }
        }
        $customFields[] = [
            'key' => CustomFieldEnum::GHOST_UNLOCK_CUSTOM_FIELD->value,
            'value' => $unlockedReports,
        ];

        $newsletters = [];
        if (isset($payload['newsletters']) && ! empty($payload['newsletters'])) {
            foreach ($payload['newsletters'] as $newsletter) {
                $newsletters[] = [
                    'id' => $newsletter['id'],
                    'name' => $newsletter['name'],
                    'description' => $newsletter['description'],
                    'status' => $newsletter['status'],
                ];
            }
        }

        if (! empty($newsletters)) {
            $customFields[] = [
                'key' => 'newsletters',
                'value' => $newsletters,
            ];
        }

        $createPeople = new CreatePeopleAction(
            People::from([
                'app' => $this->webhookRequest->receiverWebhook->app,
                'branch' => $this->webhookRequest->receiverWebhook->company->defaultBranch,
                'user' => $this->webhookRequest->receiverWebhook->user,
                'firstname' => $firstname,
                'middlename' => null,
                'lastname' => $lastname,
                'contacts' => Contact::collect($customerEmail ?? [], DataCollection::class),
                'address' => Address::collect([], DataCollection::class),
                'dob' => null,
                'facebook_contact_id' => null,
                'google_contact_id' => null,
                'apple_contact_id' => null,
                'linkedin_contact_id' => null,
                'tags' => $tags ?? [],
                'custom_fields' => $customFields ?? [],
            ])
        );
        $people = $createPeople->execute();

        return [
            'message' => 'People created successfully',
            'people' => $people->getId(),
        ];
    }
}
