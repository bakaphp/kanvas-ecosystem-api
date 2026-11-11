<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Closure;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\RuntimeNotListeningException;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * The factory's opencode server stopped (a reboot without auto-login, Terminal quit, a crash). The agent
 * cannot fix that, since it runs inside the server, so Kanvas starts it again and says so in the chat.
 */
class RestartFactoryServerAction
{
    public function __construct(
        private readonly AgentTaskSession $session,
    ) {
    }

    /**
     * Runs `$call`; when the server is not listening, restarts it and runs `$call` once more. A second
     * failure is the caller's, like any other.
     *
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     */
    public static function retrying(AgentTaskSession $session, Closure $call): mixed
    {
        try {
            return $call();
        } catch (RuntimeNotListeningException) {
            new self($session)->execute();

            return $call();
        }
    }

    public function execute(): void
    {
        $factory = HarnessFactory::codingFactoryFor($this->session)
            ?? throw new HarnessTransportException('The chat lost its machine or agent, so the factory server cannot be restarted.');

        $factory->start();

        new PostCodingChatMessageAction(
            $this->session,
            '⚙️ The factory server on **' . $this->session->machine?->name . '** had stopped. I restarted it.'
        )->execute();
    }
}
