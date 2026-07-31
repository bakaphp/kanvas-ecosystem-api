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

#[AgentTool(name: 'Update Template', category: 'templates')]
class UpdateTemplateTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Change the HTML (or subject/title) of a template you previously created — use this when a rendered '
            . 'PDF does not look right and you want to fix the markup. Only templates you created can be updated. '
            . 'Pass the template_id returned by create_template.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = $this->actingUser();
        if ($user === null) {
            return (string) json_encode(['error' => 'No acting user in scope.']);
        }

        $result = $this->updateTemplateRecord(
            $this->app,
            $this->company,
            $user,
            $request->integer('template_id'),
            $this->nullableString($request, 'html'),
            $this->nullableString($request, 'subject'),
            $this->nullableString($request, 'title'),
        );

        return (string) json_encode($result, JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema
                ->integer()
                ->description('The ID of the template to update (returned by create_template).')
                ->required(),
            'html' => $schema
                ->string()
                ->description('The new HTML body. Omit to leave the body unchanged.'),
            'subject' => $schema
                ->string()
                ->description('New subject line. Omit to leave unchanged.'),
            'title' => $schema
                ->string()
                ->description('New title. Omit to leave unchanged.'),
        ];
    }
}
