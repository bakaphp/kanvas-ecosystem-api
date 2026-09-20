<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * Stands in for a connector's channel responder so a test can count how many turns actually ran,
 * which is the whole question behind the delayed-reply dedup.
 */
class RecordingChannelReplyAction
{
    public static int $runs = 0;

    public static function reset(): void
    {
        self::$runs = 0;
    }

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent,
        protected ?Session $session = null,
    ) {
    }

    public function execute(array $params = []): array
    {
        self::$runs++;

        return ['runs' => self::$runs];
    }
}
