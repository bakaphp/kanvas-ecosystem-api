<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Sessions\Models\Session;

class SessionChannelService
{
    public static function createCanalId(string $channel, string $id): string
    {
        return match ($channel) {
            'whatsapp' => "$id@s.whatsapp.net",
            'sms' => "+$id",
            'email' => $id,
        };
    }

    public static function createChannelSlug(string $channel, string $id): string
    {
        return match ($channel) {
            'whatsapp' => "wa-chat-$id-at-swhatsappnet",
            'sms' => "twilio-$id",
            'email' => 'email-' . str_replace(['@', '.'], ['-at-', '-dot-'], $id),
        };
    }

    public static function generateChannelLink(Model $entity, AppInterface $app): ?string
    {
        $baseUrl = $app->get('kanvas_ai_assistant_base_url');

        if (empty($baseUrl)) {
            return null;
        }

        $locationId = $entity->branch->uuid;
        $agentSession = Session::fromApp($app)
            ->where('entity_namespace', get_class($entity))
            ->where('entity_id', $entity->getId())
            ->whereNotNull('agents_id')
            ->first();

        if ($agentSession === null || empty($agentSession?->uuid)) {
            return null;
        }

        return sprintf(
            '%s/chat?channel=%s&lead=%s&location=%s&app=%s',
            $baseUrl,
            $agentSession->uuid,
            $entity->getId(),
            $locationId,
            $app->key
        );
    }
}
