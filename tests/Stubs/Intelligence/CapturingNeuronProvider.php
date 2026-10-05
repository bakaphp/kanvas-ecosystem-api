<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use Override;

/**
 * Like FakeNeuronProvider but records the exact Message list it was handed on the last chat() call,
 * so a test can assert which content blocks (image / audio / PDF / text) the runner attached to the
 * outgoing UserMessage — without any network round-trip.
 */
class CapturingNeuronProvider extends FakeNeuronProvider
{
    /** @var list<Message> */
    public array $messages = [];

    /**
     * Cross-instance sink: a full webhook/job run instantiates the agent (and this provider) deep
     * inside the kernel, so a test can't reach the instance. This static holds the last run's messages
     * regardless of which instance served them. Reset it in the test's setUp.
     *
     * @var list<Message>
     */
    public static array $lastMessages = [];

    public function __construct(string $response = 'Captured reply')
    {
        parent::__construct($response);
    }

    #[Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        $this->messages = $messages;
        self::$lastMessages = $messages;

        return $this->respond(new AssistantMessage($this->response));
    }
}
