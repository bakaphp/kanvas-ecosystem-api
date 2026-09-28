<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Kanvas\Connectors\PiDev\DataTransferObject\PiDevJob;
use Kanvas\Connectors\PiDev\Enums\JobStatusEnum;
use Tests\TestCase;

final class PiDevJobTest extends TestCase
{
    public function testMapsCompletedJobWithPullRequest(): void
    {
        $job = PiDevJob::fromApiResponse([
            'jobId' => 'job-123',
            'status' => 'completed',
            'result' => 'Opened PR #42.',
            'pullRequestUrl' => 'https://github.com/acme/widgets/pull/42',
            'createdAt' => 1785424436845,
            'startedAt' => 1785424437991,
            'finishedAt' => 1785424612003,
        ]);

        $this->assertSame('job-123', $job->jobId);
        $this->assertSame(JobStatusEnum::COMPLETED, $job->status);
        $this->assertSame('https://github.com/acme/widgets/pull/42', $job->pullRequestUrl);
        $this->assertSame(1785424612003, $job->finishedAt);
        $this->assertTrue($job->isTerminal());
    }

    public function testAbsentFieldsMapToNullNotEmptyString(): void
    {
        $job = PiDevJob::fromApiResponse([
            'jobId' => 'job-123',
            'status' => 'running',
            'createdAt' => 1785424436845,
            'startedAt' => 1785424437991,
        ]);

        $this->assertNull($job->pullRequestUrl);
        $this->assertNull($job->finishedAt);
        $this->assertNull($job->result);
        $this->assertNull($job->error);
        $this->assertFalse($job->isTerminal());
    }

    public function testMapsFailedJobError(): void
    {
        $job = PiDevJob::fromApiResponse([
            'jobId' => 'job-123',
            'status' => 'failed',
            'error' => 'git clone failed (exit 128): remote: Repository not found.',
            'finishedAt' => 1785424440115,
        ]);

        $this->assertSame(JobStatusEnum::FAILED, $job->status);
        $this->assertStringContainsString('Repository not found', (string) $job->error);
        $this->assertTrue($job->isTerminal());
    }

    public function testProviderErrorIsRetryable(): void
    {
        $job = PiDevJob::fromApiResponse([
            'jobId' => 'job-123',
            'status' => 'failed',
            'error' => 'You have reached your specified API usage limits.',
            'errorCode' => 'provider_error',
            'usage' => ['turns' => 1, 'costUsd' => 0],
        ]);

        $this->assertSame('provider_error', $job->errorCode);
        $this->assertTrue($job->isRetryable());
    }

    public function testFailureWithoutAProviderErrorCodeIsNotRetryable(): void
    {
        $job = PiDevJob::fromApiResponse([
            'jobId' => 'job-123',
            'status' => 'failed',
            'error' => 'git clone failed (exit 128): remote: Repository not found.',
        ]);

        $this->assertNull($job->errorCode);
        $this->assertFalse($job->isRetryable());
    }

    public function testACompletedJobIsNeverRetryable(): void
    {
        $job = PiDevJob::fromApiResponse([
            'jobId' => 'job-123',
            'status' => 'completed',
            'errorCode' => 'provider_error',
        ]);

        $this->assertFalse($job->isRetryable());
    }
}
