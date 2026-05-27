<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Connectors\RespondIO\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

class PushLeadAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(): array
    {
        $people = $this->lead->people;

        if (! $people) {
            throw new ValidationException('Lead has no people record to push to Respond.io');
        }

        $identifier = $this->resolveIdentifier();

        if ($identifier === null) {
            throw new ValidationException(
                'Lead people record has no phone or email to use as Respond.io identifier'
            );
        }

        $client = new Client($this->lead->app, $this->lead->company);

        $response = $client->createOrUpdateContact($identifier, $this->buildContactPayload());

        $contactId = (string) ($response['id'] ?? '');
        if ($contactId !== '') {
            $people->set(CustomFieldEnum::RESPONDIO_CONTACT_ID->value, $contactId);
        }

        $tags = $this->buildTags();
        if (! empty($tags)) {
            $client->addContactTags($identifier, $tags);
        }

        $client->updateConversationStatus($identifier, 'open');

        return $response;
    }

    /**
     * @return array<int, string>
     */
    protected function buildTags(): array
    {
        $tags = [];

        foreach ($this->lead->tags()->pluck('name') as $name) {
            $name = (string) $name;
            if ($name !== '') {
                $tags[] = $name;
            }
        }

        return $tags;
    }

    protected function resolveIdentifier(): ?string
    {
        $phone = $this->firstPhone();
        if ($phone !== '') {
            return 'phone:' . Str::ensurePhonePrefix($phone);
        }

        $email = $this->firstEmail();
        if ($email !== '') {
            return 'email:' . $email;
        }

        return null;
    }

    protected function buildContactPayload(): array
    {
        $people = $this->lead->people;
        $payload = [
            'firstName' => (string) ($people->firstname ?? ''),
            'lastName' => (string) ($people->lastname ?? ''),
        ];

        $email = $this->firstEmail();
        if ($email !== '') {
            $payload['email'] = $email;
        }

        $phone = $this->firstPhone();
        if ($phone !== '') {
            $payload['phone'] = Str::ensurePhonePrefix($phone);
        }

        return $payload;
    }

    protected function firstPhone(): string
    {
        $people = $this->lead->people;

        $cell = $people->getCellPhones()->first()?->value;
        if ($cell !== null && $cell !== '') {
            return (string) $cell;
        }

        return (string) ($people->getAllPhones()->first()?->value ?? '');
    }

    protected function firstEmail(): string
    {
        return (string) ($this->lead->people->getEmails()->first()?->value ?? '');
    }
}
