<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Models\Session;

class SessionChannelService
{
    public static function createCanalId(string $channel, string $id): string
    {
        $normalizedId = self::normalizePhoneNumber($id);

        return match ($channel) {
            'whatsapp' => $normalizedId . '@s.whatsapp.net',
            'sms' => '+' . $normalizedId,
            'email' => $id,
        };
    }

    public static function createChannelSlug(string $channel, string $id): string
    {
        $normalizedId = self::normalizePhoneNumber($id);

        return match ($channel) {
            'whatsapp' => 'wa-chat-' . $normalizedId . '-at-swhatsappnet',
            'sms' => 'twilio-' . $normalizedId,
            'email' => 'email-' . self::sanitizeEmail($id),
        };
    }

    private static function normalizePhoneNumber(string $phone): string
    {
        return preg_replace('/^\+?1/', '', $phone);
    }

    private static function sanitizeEmail(string $email): string
    {
        return str_replace(['@', '.'], ['-at-', '-dot-'], $email);
    }

    public static function generateChannelLink(Model $entity, AppInterface $app): ?string
    {
        $baseUrl = $app->get('kanvas_ai_assistant_base_url');

        if (empty($baseUrl)) {
            return null;
        }

        $locationId = $entity->branch->uuid;
        $channelSlug = Session::fromApp($app)
            ->where('entity_namespace', get_class($entity))
            ->where('entity_id', $entity->getId())
            ->whereNotNull('agents_id')
            ->first()?->uuid;

        if ($entity instanceof Lead) {
            /**
             * @todo make the channel dynamic based on the lead's preferred contact method
             */
            $channelSlug = $entity->socialChannels()
                ->where(function (Builder $query) {
                    $query->where('name', 'like', '%email%')
                        ->orWhere('name', 'like', '%sms%');
                })
                ->first()?->slug;
        }

        if ($channelSlug === null) {
            return null;
        }

        return sprintf(
            '%s/chats/%s?app=%s&location=%s&channel=%s',
            $baseUrl,
            $entity->getId(),
            $app->key,
            $locationId,
            $channelSlug
        );
    }
}
