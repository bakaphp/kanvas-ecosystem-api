<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Actions;

use Kanvas\Connectors\PiDev\Enums\CustomFieldEnum;
use Kanvas\Connectors\PiDev\Services\RepoAllowListService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

class ConfigureAgentAction
{
    /**
     * @param array<array-key, mixed> $allowedRepos
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $githubToken,
        private readonly array $allowedRepos,
        private readonly ?string $systemPrompt = null,
    ) {
    }

    public function execute(): Agent
    {
        if (trim($this->githubToken) === '') {
            throw new ValidationException('A GitHub token is required to configure the agent for pi.dev');
        }

        $repos = RepoAllowListService::validate($this->allowedRepos);

        $this->agent->set(CustomFieldEnum::PIDEV_GITHUB_TOKEN->value, $this->githubToken);
        $this->agent->set(CustomFieldEnum::PIDEV_ALLOWED_REPOS->value, $repos);

        if ($this->systemPrompt !== null && trim($this->systemPrompt) !== '') {
            $this->agent->set(CustomFieldEnum::PIDEV_SYSTEM_PROMPT->value, $this->systemPrompt);
        }

        return $this->agent;
    }
}
