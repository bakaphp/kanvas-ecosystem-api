<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionData;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;

/**
 * Resolves the durable channel + session a human uses to talk to a system agent.
 *
 * The channel slug (per agent + entity) drives the session uuid via
 * buildChannelSessionUuid, so CreateSessionAction dedups to ONE durable session
 * per (human/entity, agent) — the ongoing thread. A caller that already owns the
 * inbound channel (a connector or @mention) passes it in and we thread on that.
 */
class UserAgentChannelService
{
    public function resolveSession(
        Users $human,
        Agent $agent,
        Apps $app,
        Companies $company,
        Model $entity,
        ?Channel $channel = null,
    ): Session {
        $channel ??= $this->resolveChannel(
            $agent,
            $app,
            $company,
            $entity
        );

        return new CreateSessionAction(
            new SessionData(
                app: $app,
                company: $company,
                agent: $agent,
                entity_namespace: $entity::class,
                entity_id: (string) $entity->getKey(),
                user: [
                    'id' => $human->getId(),
                    'name' => trim($human->firstname . ' ' . $human->lastname),
                    'email' => $human->email,
                ],
                channel: $channel,
                canal_id: SessionChannelService::createCanalId('ai-assist', (string) $human->getId()),
            ),
        )->execute();
    }

    private function resolveChannel(
        Agent $agent,
        Apps $app,
        Companies $company,
        Model $entity
    ): Channel {
        $owner = $agent->user;

        if ($owner === null) {
            throw new ValidationException(
                'Agent ' . $agent->getId() . ' has no user identity (user_id). '
                . 'A system agent must be a Kanvas user to hold a conversation channel.',
            );
        }

        $entityId = (int) $entity->getKey();

        $slug = SessionChannelService::createChannelSlug(
            'ai-assist',
            sprintf('%s-%s-%s', $agent->getId(), class_basename($entity), $entityId),
        );

        return new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $owner,
                entity_id: $entityId,
                entity_namespace: $entity::class,
                name: 'Chat with ' . $agent->name,
                description: $agent->name,
                slug: $slug,
            ),
        )->execute();
    }
}
