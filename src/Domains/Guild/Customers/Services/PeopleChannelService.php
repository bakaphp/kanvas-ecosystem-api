<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * Durable conversation channel keyed by People. Each People row has one stable
 * channel ("people-channel-{id}") that accumulates every conversation the agent
 * ever has with that prospect across every Lead they generate.
 */
class PeopleChannelService
{
    private const SLUG_PREFIX = 'people-channel-';

    public function findOrCreateForPeople(
        People $people,
        Apps $app,
        Companies $company,
        ?Users $user = null,
    ): Channel {
        $owner = $this->resolveOwnerUser($people, $company, $user);

        $dto = new ChannelDto(
            apps: $app,
            companies: $company,
            users: $owner,
            entity_id: $people->getId(),
            entity_namespace: People::class,
            name: 'Conversation with ' . trim((string) $people->getName()),
            description: 'Durable people-scoped conversation channel.',
            slug: $this->slugFor($people),
        );

        return new CreateChannelAction($dto)->execute();
    }

    public function attachMessageToPeopleChannel(
        Message $message,
        People $people,
        Apps $app,
        Companies $company,
        ?Users $user = null,
    ): void {
        $channel = $this->findOrCreateForPeople($people, $app, $company, $user);
        $owner = $this->resolveOwnerUser($people, $company, $user);

        $alreadyInChannel = $channel->messages()
            ->where('messages.id', $message->getId())
            ->exists();

        if (! $alreadyInChannel) {
            $channel->messages()->attach($message->getId(), [
                'users_id' => $message->users_id ?? $owner->getId(),
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => 0,
            ]);
        }

        $alreadyLinked = AppModuleMessage::query()
            ->where('message_id', $message->getId())
            ->where('system_modules', People::class)
            ->where('entity_id', $people->getId())
            ->exists();

        if (! $alreadyLinked) {
            $message->addEntity($people);
        }
    }

    public function slugFor(People $people): string
    {
        return self::SLUG_PREFIX . $people->getId();
    }

    /**
     * Caller-supplied user → AI agent user → People creator. No auth() —
     * runs in queued/background contexts where no request is in scope.
     */
    private function resolveOwnerUser(People $people, Companies $company, ?Users $user): Users
    {
        if ($user !== null) {
            return $user;
        }

        $aiAgentUser = $company->getAiAgentUser();
        if ($aiAgentUser !== null) {
            return $aiAgentUser;
        }

        $creator = $people->user;
        if ($creator instanceof Users) {
            return $creator;
        }

        throw new ValidationException(
            'Cannot create or attach to a People channel: no user passed in, '
            . 'no AI agent user configured for company ' . $company->getId()
            . ', and People row ' . $people->getId() . ' has no creator.'
        );
    }
}
