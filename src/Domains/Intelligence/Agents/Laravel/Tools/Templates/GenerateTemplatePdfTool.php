<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Templates;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\HasEntityContext;
use Kanvas\Intelligence\Tools\Traits\Templates\ManagesTemplatesTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Generate Template PDF', category: 'templates')]
class GenerateTemplatePdfTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasEntityContext;
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Render a stored template to a PDF and attach it to the record currently in scope (the lead, order, '
            . 'message, etc. you are working on). Blade expressions in the template are rendered against that record. '
            . 'Pass the template name (from create_template) — not the id.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = $this->actingUser();
        if ($user === null) {
            return (string) json_encode(['error' => 'No acting user in scope.']);
        }

        $result = $this->generateTemplatePdfForEntity(
            $this->app,
            $user,
            $this->entity,
            (string) $request->string('template_name'),
            $this->nullableString($request, 'file_name'),
        );

        return (string) json_encode($result, JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'template_name' => $schema
                ->string()
                ->description('The name of the template to render (the name you gave it in create_template).')
                ->required(),
            'file_name' => $schema
                ->string()
                ->description('Optional name for the generated PDF file (".pdf" is added if missing). Defaults to the '
                    . 'template name.'),
        ];
    }
}
