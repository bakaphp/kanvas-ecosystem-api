<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Notifications;

use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Notifications\Notification;
use Kanvas\Users\Models\Users;
use Override;

/**
 * A company asked for an integration the platform sets up with them (mode `request_setup`). Goes to
 * the app's owners; template-free so a new app needs no email template to receive it.
 */
class IntegrationSetupRequestedNotification extends Notification
{
    public function __construct(
        CompanyOnboarding $row,
        protected string $requirementLabel,
        protected string $optionLabel,
        protected ?string $notes = null,
        ?Users $fromUser = null,
    ) {
        $data = [
            'app' => $row->app,
            'company' => $row->company,
            'fromUser' => $fromUser,
            'title' => $this->subjectLine($row),
            'message' => $this->body($row),
            'metadata' => [
                'onboarding_id' => $row->getId(),
                'requirement' => $requirementLabel,
                'option' => $optionLabel,
                'notes' => $notes,
            ],
        ];

        parent::__construct($row, $data);
        $this->setType('blank');
        $this->setSubject($data['title']);
        $this->setData($data);
        $this->channels = ['mail', 'database'];
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    #[Override]
    public function getEmailContent(): string
    {
        return '<h2>' . e((string) $this->data['title']) . '</h2><p>' . nl2br(e((string) $this->data['message'])) . '</p>';
    }

    private function subjectLine(CompanyOnboarding $row): string
    {
        return sprintf('Setup requested: %s for %s', $this->optionLabel, $row->company->name);
    }

    private function body(CompanyOnboarding $row): string
    {
        $lines = [
            sprintf('%s asked for %s to be set up as their "%s" during onboarding.', $row->company->name, $this->optionLabel, $this->requirementLabel),
        ];
        if ($this->notes !== null && trim($this->notes) !== '') {
            $lines[] = 'Notes: ' . trim($this->notes);
        }

        return implode("\n", $lines);
    }
}
