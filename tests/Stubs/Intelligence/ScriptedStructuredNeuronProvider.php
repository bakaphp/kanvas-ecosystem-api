<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;

/**
 * Answers each structured() call with the next scripted body and records what it was asked, so a test
 * can drive a validation retry and read the rejection reasons the model was given.
 */
class ScriptedStructuredNeuronProvider extends FakeNeuronProvider
{
    /** @var list<string> the user prompt of every structured() call, in order */
    public array $prompts = [];

    /**
     * @param list<string> $responses
     */
    public function __construct(
        private array $responses,
    ) {
        parent::__construct();
    }

    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        $messages = is_array($messages) ? $messages : [$messages];
        $this->prompts[] = (string) end($messages)->getContent();

        return $this->respond(new AssistantMessage(array_shift($this->responses) ?? 'no more scripted answers'));
    }
}
