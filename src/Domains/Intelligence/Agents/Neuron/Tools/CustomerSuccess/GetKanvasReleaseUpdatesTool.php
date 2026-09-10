<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CustomerSuccess;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Github\DataTransferObject\GithubRelease;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\KanvasReleaseFeedService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Get Kanvas Release Updates', category: 'customer-success')]
class GetKanvasReleaseUpdatesTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_kanvas_release_updates',
            description: 'What KANVAS itself has shipped since a given date, oldest first, with the release notes verbatim. '
                . 'Pass the date this customer was last written to so you only see what they have not been told about '
                . 'yet. These notes are the ONLY thing you may describe as shipped — never infer a capability that is '
                . 'not written here.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'since',
                type: PropertyType::STRING,
                description: 'ISO-8601 date. Only releases published after this are returned. Omit for the last 30 days.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $since = null): array
    {
        try {
            $from = $since !== null && trim($since) !== ''
                ? Carbon::parse($since)
                : null;

            $releases = new KanvasReleaseFeedService($this->app)->publishedSince($from);
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Could not read the Kanvas release feed: ' . $e->getMessage(),
            ];
        }

        return [
            'status' => 'success',
            'since' => $from?->toIso8601String() ?? 'last 30 days',
            'count' => count($releases),
            'releases' => array_map(
                fn (GithubRelease $release): array => $release->toAgentPayload(),
                $releases
            ),
        ];
    }
}
