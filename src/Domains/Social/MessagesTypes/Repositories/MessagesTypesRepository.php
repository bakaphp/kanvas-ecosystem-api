<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Enums\MessageChannelEnum;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class MessagesTypesRepository
{
    public static function getById(int $id, AppInterface $app): MessageType
    {
        return MessageType::fromApp($app)->findOrFail($id);
    }

    public static function getByUuid(string $uuid): MessageType
    {
        return MessageType::getByUuid($uuid);
    }

    public static function getByVerb(string $verb, AppInterface $app): MessageType
    {
        return MessageType::fromApp($app)->where('verb', $verb)
            ->firstOrFail();
    }

    public static function getGlobalByVerbAndName(string $verb, string $name): MessageType
    {
        return MessageType::fromPublicApp()->where('verb', $verb)
                            ->where('name', $name)
                            ->firstOrFail();
    }

    /**
     * The message-type ids that count as real customer communication for an app, optionally
     * narrowed to a channel. Restricting usage analytics to these ids is what keeps social posts,
     * comments and internal notes from inflating the numbers.
     *
     * @return array<int, int>
     */
    public static function getCommunicationTypeIds(
        AppInterface $app,
        MessageChannelEnum $channel = MessageChannelEnum::ALL,
    ): array {
        $keywords = $channel->verbKeywords();

        return MessageType::query()
            ->where('apps_id', $app->getId())
            ->get(['id', 'verb'])
            ->filter(function (MessageType $type) use ($keywords): bool {
                $verb = (string) $type->verb;

                if (! ChannelCategoryEnum::isCommunicationVerb($verb)) {
                    return false;
                }

                if ($keywords === []) {
                    return true;
                }

                foreach ($keywords as $keyword) {
                    if (str_contains($verb, $keyword)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (MessageType $type): int => (int) $type->id)
            ->values()
            ->all();
    }
}
