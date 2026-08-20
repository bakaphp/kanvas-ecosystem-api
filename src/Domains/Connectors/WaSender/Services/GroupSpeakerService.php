<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Spatie\LaravelData\DataCollection;

/**
 * Resolves a group speaker to a People record, keyed on the lid — under lid addressing that is
 * often all a group discloses.
 *
 * Goes through `SyncPeopleByThirdPartyCustomFieldAction` — the same path Reynolds and the rest
 * of SalesAssist use for a CRM's customer id — because it takes a lock on
 * app+company+field+value before looking up. A burst is exactly the shape that breaks an
 * unlocked lookup-or-create: the captured album fired seven webhooks in forty seconds, and two
 * workers handling two parts from the same unknown speaker would both miss and both insert.
 */
final readonly class GroupSpeakerService
{
    public function __construct(
        private ReceiverWebhook $receiver,
        private InboundMessage $inbound,
    ) {
    }

    public function resolve(): People
    {
        $reference = $this->speakerReference();
        $peopleData = $this->speakerData($reference);

        if ($reference === null) {
            return new CreatePeopleAction($peopleData)->execute();
        }

        return new SyncPeopleByThirdPartyCustomFieldAction($peopleData)->execute();
    }

    /**
     * The identity the sync locks and matches on. The lid is stable across a rename and present
     * even when the number is withheld, so it wins over the phone-form JID.
     *
     * @return array{0: string, 1: string}|null
     */
    private function speakerReference(): ?array
    {
        if ($this->inbound->senderLid !== null) {
            return [CustomFieldEnum::WHATSAPP_LID->value, $this->inbound->senderLid];
        }

        if ($this->inbound->senderJid !== null && $this->inbound->senderPhone !== null) {
            return [CustomFieldEnum::WHATSAPP_JID->value, $this->inbound->senderJid];
        }

        return null;
    }

    /**
     * @param array{0: string, 1: string}|null $reference
     */
    private function speakerData(?array $reference): PeopleDTO
    {
        $displayName = $this->inbound->pushName ?? 'WhatsApp Group Member';
        $nameParts = explode(' ', trim($displayName), 2);

        // Reference first: the sync action reads key and value off the head of this array.
        $customFields = $reference !== null ? [$reference[0] => $reference[1]] : [];

        if ($this->inbound->senderPhone !== null && $this->inbound->senderJid !== null) {
            $customFields[CustomFieldEnum::WHATSAPP_JID->value] = $this->inbound->senderJid;
        }

        // A lid is not a phone number. Filing `900000000000001` as a cellphone would poison every
        // phone lookup in the company, so a speaker WhatsApp never disclosed a number for gets no
        // contact row at all.
        $contacts = $this->inbound->senderPhone !== null
            ? [
                [
                    'value' => $this->inbound->senderPhone,
                    'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                    'weight' => 100,
                ],
            ]
            : [];

        $peopleData = new PeopleDTO(
            app: $this->receiver->app,
            branch: $this->receiver->company->defaultBranch,
            user: $this->receiver->user,
            firstname: $nameParts[0] ?? 'WhatsApp',
            contacts: Contact::collect($contacts, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $nameParts[1] ?? '',
            custom_fields: $customFields,
            tags: [
                'whatsapp',
                'wa-group-contact',
            ],
        );

        // Someone already known from a DM is the same person here, so adopt that record rather
        // than opening a second one under their lid.
        $knownFromPhone = $this->findByDisclosedPhone();

        if ($knownFromPhone !== null) {
            $peopleData->id = $knownFromPhone->getId();
        }

        return $peopleData;
    }

    private function findByDisclosedPhone(): ?People
    {
        if ($this->inbound->senderPhone === null) {
            return null;
        }

        return PeoplesRepository::getByPhoneNumber(
            $this->receiver->app,
            $this->receiver->company,
            [$this->inbound->senderPhone]
        )->first();
    }
}
