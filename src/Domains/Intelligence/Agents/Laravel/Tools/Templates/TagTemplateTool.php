<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Templates;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Concerns\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Concerns\HasKanvasContext;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Tools\Traits\Templates\ManagesTemplatesTrait;
use Kanvas\Social\Tags\Models\Tag;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Tag Template', category: 'templates')]
class TagTemplateTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Add or remove tags on a template. Pass template_id and a list of tag names; set remove=true to '
            . 'detach them instead of attaching. Tags that do not exist yet are created. Returns the template\'s current tags.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = $this->actingUser();
        if ($user === null) {
            return (string) json_encode(['error' => 'No acting user in scope.']);
        }

        $input = $request->all();
        $templateId = $request->integer('template_id');
        $tags = Tag::normalizeNames($input['tags'] ?? []);
        $remove = $request->boolean('remove');

        if ($tags === []) {
            return (string) json_encode($this->invalidArgs('Provide at least one tag name.'), JSON_PRETTY_PRINT);
        }

        $owned = $this->resolveOwnedTemplate(
            $this->app,
            $this->company,
            $user,
            $templateId
        );
        if (isset($owned['error'])) {
            return (string) json_encode($owned, JSON_PRETTY_PRINT);
        }

        $template = $owned['template'];

        if ($remove) {
            $template->removeTags($tags);
        } else {
            $template->addTags(
                $tags,
                $this->app,
                $user,
                $this->company
            );
        }

        return (string) json_encode($this->ok([
            'template_id' => $template->getId(),
            'tags' => $template->tags()->pluck('name')->all(),
            'message' => $remove ? 'Tags removed.' : 'Tags added.',
        ]), JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema
                ->integer()
                ->description('The ID of the template to tag.')
                ->required(),
            'tags' => $schema
                ->array()
                ->items($schema->string())
                ->description('List of tag names to add or remove.')
                ->required(),
            'remove' => $schema
                ->boolean()
                ->description('When true, remove the given tags instead of adding them.'),
        ];
    }
}
