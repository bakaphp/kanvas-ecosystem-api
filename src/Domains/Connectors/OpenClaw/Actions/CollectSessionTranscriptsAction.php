<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Services\OpenClawSessionsReaderService;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCollectSessionTranscriptsAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\SessionTranscriptReader;
use Override;

class CollectSessionTranscriptsAction extends BaseCollectSessionTranscriptsAction
{
    private ?SshClient $sshClient = null;

    #[Override]
    protected function reader(): SessionTranscriptReader
    {
        return new OpenClawSessionsReaderService($this->ssh());
    }

    #[Override]
    public function execute(): int
    {
        try {
            return parent::execute();
        } finally {
            $this->sshClient?->disconnect();
            $this->sshClient = null;
        }
    }

    #[Override]
    protected function runtimeName(): string
    {
        return 'openclaw';
    }

    private function ssh(): SshClient
    {
        if ($this->sshClient instanceof SshClient) {
            return $this->sshClient;
        }
        $this->sshClient = SshClient::fromMachine($this->deployment->machine);

        return $this->sshClient;
    }
}
