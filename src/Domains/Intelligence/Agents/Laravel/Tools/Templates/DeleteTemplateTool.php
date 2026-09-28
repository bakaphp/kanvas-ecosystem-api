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

#[AgentTool(name: 'Delete Template', category: 'templates')]
class DeleteTemplateTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Delete a template you previously created. Only templates you created can be deleted — you cannot '
            . 'remove system templates or ones created by others. Pass the template_id returned by create_template.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = $this->actingUser();
        if ($user === null) {
            return (string) json_encode(['error' => 'No acting user in scope.']);
        }

        $result = $this->deleteTemplateRecord(
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
                ->description('The ID of the template to delete (returned by create_template).')
                ->required(),
        ];
    }
}
