<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Support\Str;
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

        $existingCustomer = People::getByCustomField(
            'whatsapp_jid',
            $this->jid,
            $this->receiver->company
        );

        $phoneNumber = str_replace('@s.whatsapp.net', '', $this->jid);

        if (! $existingCustomer) {
            $existingCustomer = PeoplesRepository::getByPhoneNumber(
                $this->receiver->app,
                $this->receiver->company,
                $this->normalizedPhones()
            )->first();
        }

        // Session hijack maps someone else's traffic onto this record; overwriting their name and
        // contacts with the hijacked JID's data would corrupt the real person.
        if ($existingCustomer && $this->keepExisting) {
            return $existingCustomer;
        }

        $displayName = $this->name ?? ConversationChannelService::extractContactName($this->jid);
        $nameParts = explode(' ', $displayName, 2);

        $peopleDto = new PeopleDTO(
            app: $this->receiver->app,
            branch: $this->receiver->company->defaultBranch,
            user: $this->receiver->user,
            firstname: $nameParts[0] ?? 'WhatsApp',
            contacts: Contact::collect([
                [
                    'value' => $phoneNumber,
                    'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                    'weight' => 100,
                ],
            ], DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $nameParts[1] ?? 'Contact',
            custom_fields: [
                'whatsapp_jid' => $this->jid,
            ],
            tags: ['whatsapp', 'wa-contact']
        );

        if ($existingCustomer) {
            $peopleDto->id = $existingCustomer->getId();
        }

        return new CreatePeopleAction($peopleDto)->execute();
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
