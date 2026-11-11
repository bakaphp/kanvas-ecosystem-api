<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;

/**
 * Answers each chat() with the next scripted reply (the last one repeats), and keeps every request so a test can
 * read what the model was told between them.
 */
class SequencedNeuronProvider extends FakeNeuronProvider
{
    /** @var list<list<Message>> */
    public array $requests = [];

    /**
     * @param list<string> $replies
     */
    public function __construct(private array $replies)
    {
        parent::__construct($replies[0] ?? '');
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        self::$lastMessages = $messages;
        $this->requests[] = $messages;
        $reply = count($this->replies) > 1 ? array_shift($this->replies) : ($this->replies[0] ?? '');

        return $this->respond(new AssistantMessage($reply));
    }
}
