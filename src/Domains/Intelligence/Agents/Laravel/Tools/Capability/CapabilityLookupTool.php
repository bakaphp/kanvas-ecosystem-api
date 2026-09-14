<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Capability;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Services\CapabilityLookupService;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

/**
 * Laravel-side twin of the Neuron capability lookup. Same service, same three answers.
 *
 * The agent arrives through `withContext()` rather than the constructor — `KanvasLaravelAgent`
 * passes its own record when it builds the toolset. A tool reached through `KanvasAgentAsTool`
 * (a sub-agent) gets app and company but no agent, which is why the null path has to answer
 * usefully instead of failing.
 */
#[AgentTool(name: 'Capability Lookup', category: 'ecosystem')]
class CapabilityLookupTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;

    public function name(): string
    {
        return 'capability_lookup';
    }

    public function instructions(): string
    {
        return 'Use `capability_lookup` before attempting something with a tool that only roughly fits. '
            . 'It tells you what the platform can do, not just what you hold.';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Check whether Kanvas has a tool for something BEFORE you try it with a tool that only roughly '
            . 'fits. Answers with three things: matching tools you already have, matching tools the platform has '
            . 'that you were NOT granted (and which agents hold them), and whether nothing matches at all. Use it '
            . 'whenever a request sounds like a capability you are not sure you have.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $topic = trim((string) $request->string('topic'));
        $category = $this->nullableString($request, 'category');

        if ($this->agent === null) {
            return $this->encode([
                'status' => 'error',
                'message' => 'This tool needs to run as a specific agent and no agent is in scope, so it cannot '
                    . 'tell what you were granted. Answer from the tools you can already see.',
            ]);
        }

        try {
            return $this->encode(new CapabilityLookupService($this->agent)->lookup($topic, $category));
        } catch (Throwable $e) {
            report($e);

            return $this->encode([
                'status' => 'error',
                'message' => 'Could not read the capability catalog. Do not treat this as "the tool does not '
                    . 'exist" — answer from the tools you can already see, and say the catalog was unavailable.',
            ]);
        }
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema
                ->string()
                ->description('What you are trying to do, in a few plain words — e.g. "create a spreadsheet", '
                    . '"refund an order". Not a tool name.')
                ->required(),
            'category' => $schema
                ->string()
                ->description('Optional catalog category to narrow the search, e.g. "crm", "accounting", '
                    . '"productivity". Omit unless you already know the area.'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
