<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\VoiceBridge\Client;

class InitVoiceSessionAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly string $sessionId,
        protected readonly string $userId,
        protected readonly array $initialContext = [],
    ) {
    }

    /**
     * Initialize a voice session in VoiceBridge.
     * If the session already exists, returns the current state without overwriting it.
     *
     * @return array{status: string, message: string, state: array}
     */
    public function execute(): array
    {
        return Client::getInstance($this->app)
            ->initSession($this->userId, $this->sessionId, $this->initialContext);
    }
}
