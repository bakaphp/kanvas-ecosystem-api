<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Notifications;

use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Override;

/**
 * Mail only. A summary can carry what the model read in a customer conversation, so it is rendered with
 * HTML off.
 */
class SupervisorFindingsNotification extends Notification
{
    public array $channels = ['mail'];

    /**
     * @param list<array<string, mixed>> $findings
     */
    public function __construct(
        Companies $company,
        string $subject,
        private readonly array $findings,
    ) {
        parent::__construct($company, ['company' => $company]);

        $this->setType('blank');
        $this->setSubject($subject);
    }

    #[Override]
    public function getEmailContent(): string
    {
        $lines = [];

        foreach ($this->findings as $finding) {
            $severity = strtoupper((string) ($finding['severity'] ?? ''));
            $lines[] = "- **{$severity}** `{$finding['code']}`: {$finding['summary']}";
            $lines[] = '  Evidence: `' . json_encode($finding['evidence'] ?? []) . '`';
        }

        return MarkdownEmailRenderer::toEmailHtml(implode("\n", $lines), allowHtml: false);
    }
}
