<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Coding;

use Kanvas\Connectors\PiDev\Client;
use Kanvas\Connectors\PiDev\Enums\CustomFieldEnum;
use Kanvas\Connectors\PiDev\Exceptions\PiDevApiException;
use Kanvas\Connectors\PiDev\Services\RepoAllowListService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Check Coding Setup')]
class CheckCodingSetupTool extends Tool
{
    private const string PROBE_JOB_ID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly Agent $agent,
        private readonly ?Client $client = null,
    ) {
        parent::__construct(
            name: 'check_coding_setup',
            description: 'Check whether you are ready to dispatch coding tasks: that you have a GitHub token '
                . 'and at least one allowed repository, that the pi.dev connector is configured for your '
                . 'company, and that the pi.dev server is reachable and accepts the API token. Use this before '
                . 'starting work, or when a user asks if you are set up to code.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $issues = [];

        $token = $this->agent->get(CustomFieldEnum::PIDEV_GITHUB_TOKEN->value);
        $hasToken = is_string($token) && $token !== '';
        if (! $hasToken) {
            $issues[] = 'No GitHub token configured (an admin must set PIDEV_GITHUB_TOKEN on this agent).';
        }

        $slugs = $this->allowedRepoSlugs();
        $hasRepos = $slugs !== [];
        if (! $hasRepos) {
            $issues[] = 'No allowed repositories configured (an admin must set PIDEV_ALLOWED_REPOS on this agent).';
        }

        $configured = false;
        $reachable = false;
        $authorized = false;

        try {
            $client = $this->client ?? new Client($this->agent->app, $this->agent->company);
            $configured = true;

            try {
                $client->getJob(self::PROBE_JOB_ID);
                $reachable = true;
                $authorized = true;
            } catch (PiDevApiException $e) {
                $reachable = true;
                if ($e->status === 404) {
                    $authorized = true;
                } elseif ($e->status === 401) {
                    $issues[] = 'pi.dev rejected the API token (401 unauthorized) — check the company pidev_api_token.';
                } else {
                    $issues[] = 'pi.dev returned an error: ' . $e->getMessage();
                }
            } catch (Throwable $e) {
                $issues[] = 'pi.dev server is not reachable: ' . $e->getMessage();
            }
        } catch (ValidationException) {
            $issues[] = 'The pi.dev connector is not configured for this company (missing base URL or API token).';
        }

        $ready = $hasToken && $hasRepos && $configured && $reachable && $authorized;

        return [
            'status' => 'success',
            'ready' => $ready,
            'checks' => [
                'github_token' => $hasToken,
                'allowed_repos' => $hasRepos,
                'pidev_configured' => $configured,
                'pidev_reachable' => $reachable,
                'pidev_authorized' => $authorized,
            ],
            'allowed_repos' => $slugs,
            'issues' => $issues,
            'note' => $ready
                ? 'All set — you can dispatch coding tasks with dispatch_coding_task.'
                : 'Not ready. Report the issues above to the user and ask an admin to fix the missing pieces; do not attempt to dispatch.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedRepoSlugs(): array
    {
        $slugs = [];
        foreach (RepoAllowListService::forAgent($this->agent) as $repo) {
            if (is_array($repo) && isset($repo['slug'])) {
                $slugs[] = (string) $repo['slug'];
            }
        }

        return $slugs;
    }
}
