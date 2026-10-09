<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Notifications\Templates\Blank;

/**
 * The People-only counterpart of SendMessageToLeadAction's email path, for a recipient that has no
 * Lead behind it (a bulk-imported campaign recipient). Deliberately a thin subset — no SMS, no
 * agent-mailbox lane, no attachments — those stay Lead-shaped until a real need for them shows up
 * here. Reuses the exact same `first-time-agent-engagement` template Leads already use, so no new
 * email_templates/notification_types row has to be registered for this to work.
 */
class SendEmailToPeopleAction
{
    public function __construct(
        protected readonly People $people,
    ) {
    }

    public function execute(string $to, string $message, ?string $subject = null): array
    {
        if (trim($to) === '') {
            throw new ValidationException('People has no email address to send to');
        }

        $html = MarkdownEmailRenderer::toEmailHtml($message);

        $notification = new Blank(
            'first-time-agent-engagement',
            [
                'content' => $html,
                'noHi' => true,
                'company' => $this->people->company,
                'signature' => false,
            ],
            ['mail'],
            $this->people,
        );
        $notification->setSubject($subject ?? 'Message from ' . $this->people->company->name);

        Notification::route('mail', $to)->notify($notification);

        return [
            'channel' => 'email',
            'to' => $to,
            'template' => 'first-time-agent-engagement',
            'body_length' => strlen($html),
            'people_id' => $this->people->getId(),
        ];
    }
}
