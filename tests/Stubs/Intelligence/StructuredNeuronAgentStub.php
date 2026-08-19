<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * An agent that answers with a whole record instead of a sentence — the shape a content/publishing
 * agent produces. The reply is fenced because that is what models actually emit, fence instructions
 * notwithstanding.
 */
class StructuredNeuronAgentStub extends SalesNeuronAgentStub
{
    public const string ENVELOPE = <<<'JSON'
        ```json
        {
          "title": "Educación acelera construcción de aulas en El Seibo",
          "content": "Hola Mundo",
          "excerpt": "Resumen corto",
          "categories": ["Nacionales", "Educación"],
          "tags": ["Educación", "El Seibo"],
          "status": "draft"
        }
        ```
        JSON;

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider(self::ENVELOPE);
    }
}
