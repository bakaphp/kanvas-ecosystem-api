<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Templates;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Templates\ManagesTemplatesTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Get Template', category: 'templates')]
class GetTemplateTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Get a single template by id, including its full HTML body — use this to inspect the current markup '
            . 'before fixing it with update_template. Returns an "owned" flag telling you whether you may edit or '
            . 'delete it.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = $this->actingUser();
        if ($user === null) {
            return (string) json_encode(['error' => 'No acting user in scope.']);
        }

        $result = $this->getTemplate(
            $this->app,
            $this->company,
            $user,
            $request->integer('template_id'),
        );

        return (string) json_encode($result, JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema
                ->integer()
                ->description('The ID of the template to fetch (from list_templates or create_template).')
                ->required(),
        ];
    }
}
