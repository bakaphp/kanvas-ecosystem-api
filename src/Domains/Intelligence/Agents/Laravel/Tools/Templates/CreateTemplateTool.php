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

#[AgentTool(name: 'Create Template', category: 'templates')]
class CreateTemplateTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a reusable HTML template (Blade syntax allowed, e.g. {{ $entity->name }}) that can later be '
            . 'rendered to a PDF and attached to a record with generate_template_pdf. Give it a unique name you will '
            . 'reuse. The template belongs to you — only you can update or delete it afterwards.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = $this->actingUser();
        if ($user === null) {
            return (string) json_encode(['error' => 'No acting user in scope to own the template.']);
        }

        $result = $this->createTemplateRecord(
            $this->app,
            $this->company,
            $user,
            (string) $request->string('name'),
            (string) $request->string('html'),
            $this->nullableString($request, 'subject'),
            $this->nullableString($request, 'title'),
        );

        return (string) json_encode($result, JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema
                ->string()
                ->description('A unique name for the template (used later to render it, e.g. "quote-summary").')
                ->required(),
            'html' => $schema
                ->string()
                ->description('The HTML body. Blade expressions like {{ $entity->firstname }} are rendered against '
                    . 'the record the PDF is attached to.')
                ->required(),
            'subject' => $schema
                ->string()
                ->description('Optional subject line stored with the template.'),
            'title' => $schema
                ->string()
                ->description('Optional title stored with the template.'),
        ];
    }
}
