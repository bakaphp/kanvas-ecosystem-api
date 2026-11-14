<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\WaSender\Enums\CustomFieldEnum;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Spatie\LaravelData\DataCollection;

/**
 * Find-or-create the People behind a phone-form WhatsApp JID, matching by `whatsapp_jid` custom
 * field first and normalized phone second. Returns null for group/newsletter JIDs — a room is not
 * a person.
 */
class CreatePeopleFromJidAction
{
    public function __construct(
        protected readonly ReceiverWebhook $receiver,
        protected readonly string $jid,
        protected readonly ?string $name = null,
        protected readonly bool $keepExisting = false,
    ) {
    }

    public function execute(): ?People
    {
        if (ConversationChannelService::isGroupJid($this->jid) || ConversationChannelService::isChannelJid($this->jid)) {
            return null;
        }

        $existing = $this->existingPerson();

        if ($existing !== null) {
            return $existing;
        }

        $displayName = $this->name ?? ConversationChannelService::extractContactName($this->jid);
        $nameParts = explode(' ', $displayName, 2);

        return new CreatePeopleAction(
            new PeopleDTO(
                app: $this->receiver->app,
                branch: $this->receiver->company->defaultBranch,
                user: $this->receiver->user,
                firstname: $nameParts[0] ?? 'WhatsApp',
                contacts: Contact::collect([
                    [
                        'value' => str_replace('@s.whatsapp.net', '', $this->jid),
                        'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                        'weight' => 100,
                    ],
                ], DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $nameParts[1] ?? 'Contact',
                custom_fields: [
                    CustomFieldEnum::WHATSAPP_JID->value => $this->jid,
                ],
                tags: ['whatsapp', 'wa-contact']
            )
        )->execute();
    }

    /**
     * A person already on file is returned as they are. A WhatsApp display name, or the absence of
     * one on an echo of our own send, says nothing about who the CRM knows them to be, and running
     * them through the create action would replace their name and blank what the payload does not
     * carry (date of birth, licence). The JID is the one thing added, so the next lookup is direct.
     */
    private function existingPerson(): ?People
    {
        $existing = People::getByCustomField(
            CustomFieldEnum::WHATSAPP_JID->value,
            $this->jid,
            $this->receiver->company
        ) ?? PeoplesRepository::getByPhoneNumber(
            $this->receiver->app,
            $this->receiver->company,
            $this->normalizedPhones()
        )->first();

        // A hijacked session carries someone else's JID, which must not be pinned on this person.
        if ($existing !== null
            && ! $this->keepExisting
            && empty($existing->get(CustomFieldEnum::WHATSAPP_JID->value))
        ) {
            $existing->set(CustomFieldEnum::WHATSAPP_JID->value, $this->jid);
        }

        return $existing;
    }

    /**
     * Every plausible stored form of the number: as-is, without a leading US country code, and
     * with one prepended — records predate consistent normalization.
     *
     * @return array<int, string>
     */
    private function normalizedPhones(): array
    {
        $digits = Str::digitsOnly(str_replace('@s.whatsapp.net', '', $this->jid));

        return collect([$digits])
            ->filter()
            ->flatMap(function ($number) {
                return collect([
                    $number,
                    strlen($number) === 11 && str_starts_with($number, '1') ? substr($number, 1) : null,
                    strlen($number) === 10 ? '1' . $number : null,
                ])->filter();
            })
            ->unique()
            ->values()
            ->all();
    }
}
