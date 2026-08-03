<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\DataTransferObject;

use Kanvas\Connectors\PiDev\Enums\JobStatusEnum;

class PiDevJob
{
    public function __construct(
        public readonly string $jobId,
        public readonly JobStatusEnum $status,
        public readonly ?string $agentId = null,
        public readonly ?string $repoName = null,
        public readonly ?string $result = null,
        public readonly ?string $pullRequestUrl = null,
        public readonly ?string $error = null,
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
            createdAt: isset($response['createdAt']) ? (int) $response['createdAt'] : null,
            startedAt: isset($response['startedAt']) ? (int) $response['startedAt'] : null,
            finishedAt: isset($response['finishedAt']) ? (int) $response['finishedAt'] : null,
        );
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
