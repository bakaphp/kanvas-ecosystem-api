<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Hermes;

use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Exceptions\AgentRuntimeUnreachableException;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Mockery;
use PHPUnit\Framework\TestCase;

final class HermesContainerCliServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testReapedContainerRaisesUnreachableNotGenericValidation(): void
    {
        $ssh = Mockery::mock(SshClient::class);
        $ssh->shouldReceive('exec')
            ->andReturn('Error response from daemon: No such container: hermes-hermes-agent-agent');

        $service = new HermesContainerCliService($ssh, 'hermes-hermes-agent-agent');

        $this->expectException(AgentRuntimeUnreachableException::class);
        $service->runJson(['kanban', 'list']);
    }

    public function testTrulyMalformedOutputStillRaisesGenericValidation(): void
    {
        $ssh = Mockery::mock(SshClient::class);
        $ssh->shouldReceive('exec')->andReturn('this is not json and not a docker error');

        $service = new HermesContainerCliService($ssh, 'container');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-JSON output');
        $service->runJson(['kanban', 'list']);
    }

    public function testValidJsonDecodesNormally(): void
    {
        $ssh = Mockery::mock(SshClient::class);
        $ssh->shouldReceive('exec')->andReturn('warning: something' . "\n" . '{"cards": []}');

        $service = new HermesContainerCliService($ssh, 'container');

        $this->assertSame(['cards' => []], $service->runJson(['kanban', 'list']));
    }
}
