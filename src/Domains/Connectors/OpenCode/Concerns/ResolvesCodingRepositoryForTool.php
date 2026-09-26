<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Concerns;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\DataTransferObject\ResolvedCodingRepository;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\GitHubRepositoryService;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Turns a repository an LLM named into one this agent can actually reach, or a structured refusal.
 *
 * Every repository-facing tool needs the same four steps — resolve the name, find the token, refuse
 * cleanly, build a client — and they must refuse identically. A tool that resolved a repository its
 * own way would be the one place the token stops being the boundary.
 */
trait ResolvesCodingRepositoryForTool
{
    /**
     * @return ResolvedCodingRepository|array<string, mixed> the repository, or an error to return verbatim
     */
    private function resolveCodingRepository(Agent $agent, string $identifier): ResolvedCodingRepository|array
    {
        $token = Str::trimToNull((string) $agent->get(AgentCustomFieldEnum::GIT_TOKEN->value));

        if ($token === null) {
            return $this->denied(
                'This agent has no git token, so it cannot reach any repository.',
                guidance: 'Say that an administrator has to set CODING_GIT_TOKEN on this agent.'
            );
        }

        try {
            $repository = new RepoAllowListService($agent)->resolveOrFail(trim($identifier));
        } catch (Throwable $e) {
            return $this->notFound(
                $e->getMessage(),
                guidance: 'Nothing was read. Do not guess at another repository name.'
            );
        }

        return new ResolvedCodingRepository($repository, new GitHubRepositoryService($token, $repository->cloneUrl));
    }
}
