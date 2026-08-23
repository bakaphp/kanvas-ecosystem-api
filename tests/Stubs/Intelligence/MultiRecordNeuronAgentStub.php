<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * An agent handed several press releases in one burst and answering with all of them — a fenced JSON
 * LIST rather than a single record. Captured from El Nuevo Diario, where it filed a reply whose body
 * was the raw JSON dump.
 */
class MultiRecordNeuronAgentStub extends SalesNeuronAgentStub
{
    public const string ENVELOPE = <<<'JSON'
        ```json
        [
          {
            "title": "Foundation delivers school supplies in Herrera",
            "content": "<p>First article body.</p>",
            "categories": ["National", "Education"],
            "status": "draft"
          },
          {
            "title": "Congressman presents his legislative report",
            "content": "<p>Second article body.</p>",
            "categories": ["National", "Politics"],
            "status": "draft"
          }
        ]
        ```
        JSON;

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider(self::ENVELOPE);
    }
}
