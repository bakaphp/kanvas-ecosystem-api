<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\DataTransferObject;

use Kanvas\Connectors\PiDev\Enums\JobStatusEnum;

class PiDevJob
{
    /** @var list<string> */
    private const array RETRYABLE_ERROR_CODES = ['provider_error'];

    public function __construct(
        public readonly string $jobId,
        public readonly JobStatusEnum $status,
        public readonly ?string $agentId = null,
        public readonly ?string $repoName = null,
        public readonly ?string $result = null,
        public readonly ?string $pullRequestUrl = null,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $createdAt = null,
        public readonly ?int $startedAt = null,
        public readonly ?int $finishedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            jobId: (string) $response['jobId'],
            status: JobStatusEnum::from((string) $response['status']),
            agentId: isset($response['agentId']) ? (string) $response['agentId'] : null,
            repoName: isset($response['repoName']) ? (string) $response['repoName'] : null,
            result: isset($response['result']) ? (string) $response['result'] : null,
            pullRequestUrl: isset($response['pullRequestUrl']) ? (string) $response['pullRequestUrl'] : null,
            error: isset($response['error']) ? (string) $response['error'] : null,
            errorCode: isset($response['errorCode']) ? (string) $response['errorCode'] : null,
            createdAt: isset($response['createdAt']) ? (int) $response['createdAt'] : null,
            startedAt: isset($response['startedAt']) ? (int) $response['startedAt'] : null,
            finishedAt: isset($response['finishedAt']) ? (int) $response['finishedAt'] : null,
        );
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * A failure that is pi.dev's upstream provider refusing the run, not the coding task being
     * wrong — an Anthropic usage cap, a 5xx, an overloaded model. These burn zero tokens (`usage`
     * comes back all-zero at turn 1) and clear on their own, so the same payload succeeds later.
     * Everything else — a bad clone, a task the agent could not complete — is a real failure that
     * re-running would only repeat.
     */
    public function isRetryable(): bool
    {
        return $this->status === JobStatusEnum::FAILED
            && in_array($this->errorCode, self::RETRYABLE_ERROR_CODES, true);
    }
}
