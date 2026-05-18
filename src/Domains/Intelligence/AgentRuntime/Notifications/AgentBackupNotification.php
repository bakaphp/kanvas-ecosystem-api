<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Notifications;

use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Override;
use Throwable;

class AgentBackupNotification extends Notification
{
    public const string TEMPLATE_NAME = 'agent_backup_result';

    public array $channels = ['mail'];

    public function __construct(
        AgentBackup $backup,
        AgentDeployment $deployment,
        bool $success,
        ?Throwable $error = null,
    ) {
        parent::__construct($backup, [
            'company' => $deployment->company,
        ]);

        $agentName = $deployment->agent?->name ?? 'unknown';
        $this->setSubject(
            $success
                ? sprintf('Backup completed for agent "%s"', $agentName)
                : sprintf('Backup FAILED for agent "%s"', $agentName),
        );
        $this->setTemplateName(self::TEMPLATE_NAME);
        $this->setData([
            'success' => $success,
            'agent_name' => $agentName,
            'backup_id' => $backup->getId(),
            'file_path' => $backup->file_path ?? 'unknown',
            'file_size_bytes' => $backup->file_size_bytes ?? 'unknown',
            'error_message' => $error?->getMessage() ?? $backup->error_message ?? 'no details captured',
        ]);
    }

    #[Override]
    public function getEmailContent(): string
    {
        return new RenderTemplateAction($this->app, $this->company)
            ->execute(self::TEMPLATE_NAME, $this->getData());
    }
}
