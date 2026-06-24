<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * Neuron stub that returns the structured email envelope the outreach trigger asks
 * for: `{"subject": "...", "response": "<body>"}`. Lets the email-channel reach-out
 * test verify the subject is lifted to the mail title and the body stays clean.
 */
class SalesEmailEnvelopeNeuronAgentStub extends KanvasGenericNeuronAgent
{
    public const string SUBJECT = 'Eliminating fragile agent workflows';
    public const string BODY = "Hi Max,\n\nLet's talk.\n\nSally Castro | Kanvas";

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider(json_encode([
            'subject' => self::SUBJECT,
            'response' => self::BODY,
        ]));
    }

    #[Override]
    public function instructions(): string
    {
        return implode("\n\n", array_filter([
            $this->agent?->soul,
            $this->agent?->instructions,
            $this->agent?->output_format,
        ]));
    }
}
