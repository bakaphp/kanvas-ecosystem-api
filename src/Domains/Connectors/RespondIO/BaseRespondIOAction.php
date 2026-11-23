<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * One Respond.io event about a contact or a conversation, on the workspace the receiver belongs
 * to. The contact is found the way it was filed: by phone, as a person and as a conversation.
 */
abstract class BaseRespondIOAction
{
    public function __construct(
        protected ReceiverWebhook $receiver,
        protected array $payload,
    ) {
    }

    abstract public function execute(): array;

    /**
     * The contact's phone, or its Respond.io id when it has none.
     */
    protected function contactIdentifier(): ?string
    {
        return Str::trimToNull((string) ($this->payload['contact']['phone'] ?? $this->payload['contact']['id'] ?? ''));
    }

    protected function resolvePeople(): ?People
    {
        $identifier = $this->contactIdentifier();

        if ($identifier === null) {
            return null;
        }

        return PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: [Str::normalizePhoneNumber($identifier), str_replace('+', '', $identifier)]
        )->first();
    }

    protected function resolveChannel(): ?Channel
    {
        $identifier = $this->contactIdentifier();

        if ($identifier === null) {
            return null;
        }

        return Channel::query()
            ->fromApp((int) $this->receiver->apps_id)
            ->fromCompany((int) $this->receiver->companies_id)
            ->where('slug', WhatsAppTransport::channelSlug($identifier))
            ->first();
    }

    /**
     * A conversation filed since Respond.io became a provider belongs to the person, whose open
     * lead is the one meant; an older one names its lead directly.
     */
    protected function resolveLead(Channel $channel): ?Lead
    {
        if (! $channel->entity_id) {
            return null;
        }

        if ($channel->entity_namespace === People::class) {
            $people = People::query()
                ->fromApp((int) $this->receiver->apps_id)
                ->fromCompany((int) $this->receiver->companies_id)
                ->find($channel->entity_id);

            return $people !== null ? LeadsRepository::getPeopleActiveLead($people) : null;
        }

        return Lead::query()
            ->fromApp((int) $this->receiver->apps_id)
            ->fromCompany((int) $this->receiver->companies_id)
            ->notDeleted()
            ->whereKey($channel->entity_id)
            ->first();
    }

    /**
     * What an action answers when the contact resolved to nobody.
     *
     * @return array<string, string>
     */
    protected function unresolved(string $status): array
    {
        $identifier = $this->contactIdentifier();

        return $identifier === null
            ? ['error' => 'Missing contact identifier']
            : ['status' => $status, 'identifier' => $identifier];
    }

    /**
     * The lead's notes thread, opened here when the lead was created without one.
     */
    protected function notesChannelOf(Lead $lead): Channel
    {
        return $lead->notes ?? new CreateChannelAction(
            new ChannelDto(
                $this->receiver->app,
                $this->receiver->company,
                $this->receiver->user,
                (string) $lead->getKey(),
                $lead::class,
                ChannelNameEnum::NOTES->value,
                'AI Notes Channel',
                Str::uuid()->toString()
            )
        )->execute();
    }
}
