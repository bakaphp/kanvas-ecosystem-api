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
 * agent-mailbox lane — those stay Lead-shaped until a real need for them shows up here. Defaults to
 * the same `first-time-agent-engagement` template Leads already use, so the common case needs no new
 * `templates` row — but the caller can point at any other template it owns (see create_template).
 */
class SendEmailToPeopleAction
{
    private const string DEFAULT_TEMPLATE = 'first-time-agent-engagement';

    public function __construct(
        protected readonly People $people,
    ) {
    }

    /**
     * @param  list<string>  $attachmentUrls
     */
    public function execute(
        string $to,
        string $message,
        ?string $subject = null,
        array $attachmentUrls = [],
        ?string $templateName = null,
    ): array {
        if (trim($to) === '') {
            throw new ValidationException('People has no email address to send to');
        }

        $templateName = $templateName !== null && trim($templateName) !== '' ? trim($templateName) : self::DEFAULT_TEMPLATE;
        $html = MarkdownEmailRenderer::toEmailHtml($message);

        $notification = new Blank(
            $templateName,
            [
                'content' => $html,
                'noHi' => true,
                'company' => $this->people->company,
                'signature' => false,
            ],
            ['mail'],
            $this->people,
            $attachmentUrls !== [] ? $attachmentUrls : null,
        );
        $notification->setSubject($subject ?? 'Message from ' . $this->people->company->name);

        Notification::route('mail', $to)->notify($notification);

        return [
            'channel' => 'email',
            'to' => $to,
            'template' => $templateName,
            'body_length' => strlen($html),
            'people_id' => $this->people->getId(),
        ];
    }
}
