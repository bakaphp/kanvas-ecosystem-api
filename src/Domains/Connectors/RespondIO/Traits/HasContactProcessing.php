<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Spatie\LaravelData\DataCollection;

trait HasContactProcessing
{
    protected function getContactIdentifier(array $contact): ?string
    {
        $phone = $contact['phone'] ?? null;
        $contactId = (string) ($contact['id'] ?? '');
        $identifier = $phone ?? $contactId;

        return $identifier !== '' ? $identifier : null;
    }

    protected function getContactDisplayName(array $contact, string $identifier): string
    {
        /** @var string $firstName */
        $firstName = $contact['firstName'] ?? '';
        /** @var string $lastName */
        $lastName = $contact['lastName'] ?? '';
        $displayName = trim($firstName . ' ' . $lastName);

        return $displayName !== '' ? $displayName : $identifier;
    }

    protected function processContactFromMessage(
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        string $identifier,
        ?string $firstName,
        ?string $lastName
    ): ?People {
        $phoneNumber = Str::normalizePhoneNumber($identifier);
        $phoneNumberWithCountryCode = str_replace('+', '', $identifier);

        $existingCustomer = PeoplesRepository::getByPhoneNumber(
            app: $app,
            company: $company,
            phoneNumbers: [$phoneNumber, $phoneNumberWithCountryCode]
        )->first();

        if ($existingCustomer) {
            return $existingCustomer;
        }

        $contactData = [
            [
                'value' => $phoneNumber,
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'weight' => 100,
            ],
        ];

        $peopleDto = new PeopleDTO(
            app: $app,
            branch: $company->defaultBranch,
            user: $user,
            firstname: $firstName ?? $phoneNumber,
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $lastName ?? '',
            custom_fields: [
                'respondio_phone' => $identifier,
            ],
            tags: ['respondio', 'ai-agent']
        );

        return new CreatePeopleAction($peopleDto)->execute();
    }

    protected function findPeopleByIdentifier(
        AppInterface $app,
        CompanyInterface $company,
        string $identifier
    ): ?People {
        $phoneNumber = Str::normalizePhoneNumber($identifier);
        $phoneNumberWithCountryCode = str_replace('+', '', $identifier);

        return PeoplesRepository::getByPhoneNumber(
            app: $app,
            company: $company,
            phoneNumbers: [$phoneNumber, $phoneNumberWithCountryCode]
        )->first();
    }

    protected function syncContactToRespondIO(
        AppInterface $app,
        CompanyInterface $company,
        People $people,
        string $identifier
    ): void {
        try {
            $client = new Client($app, $company);
            $phone = Str::ensurePhonePrefix($identifier);

            $data = [
                'firstName' => $people->firstname,
                'lastName' => $people->lastname,
            ];

            $email = $people->getEmails()->first()?->value;
            if ($email !== null) {
                $data['email'] = $email;
            }

            $client->createOrUpdateContact("phone:{$phone}", $data);
        } catch (Exception $e) {
            // Contact sync is best-effort, don't fail the webhook
        }
    }
}
