<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\DataTransferObject;

class PiDevWorkRequest
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $githubToken,
        public readonly string $workingGithubRepoUrl,
        public readonly string $task,
        public readonly ?string $systemPrompt = null,
        public readonly ?string $workingGithubRepoName = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toApiPayload(): array
    {
        $payload = [
            'agentId' => $this->agentId,
            'githubToken' => $this->githubToken,
            'workingGithubRepoUrl' => $this->workingGithubRepoUrl,
            'task' => $this->task,
        ];

        if ($this->systemPrompt !== null && $this->systemPrompt !== '') {
            $payload['systemPrompt'] = $this->systemPrompt;
        }

        if ($this->workingGithubRepoName !== null && $this->workingGithubRepoName !== '') {
            $payload['workingGithubRepoName'] = $this->workingGithubRepoName;
        }

        return $payload;
    }
}
