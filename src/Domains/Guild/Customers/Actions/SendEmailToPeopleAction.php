<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Illuminate\Support\Facades\Notification;
use Kanvas\Connectors\Mailgun\Actions\SendAsAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;

/**
 * The People-only counterpart of SendMessageToLeadAction's email path, for a recipient that has no
 * Lead behind it (a bulk-imported campaign recipient). No SMS lane — that stays Lead-shaped until a
 * real need shows up here. Defaults to the same `first-time-agent-engagement` template Leads already
 * use, so the common case needs no new `templates` row — but the caller can point at any other
 * template it owns (see create_template).
 *
 * Mirrors SendMessageToLeadAction's dual path: an agent with a mailbox sends through Mailgun's API
 * with open tracking on (SendAsAgentMailboxAction's trackingPerson), everyone else falls back to the
 * company's SMTP identity via Notification, same as before this existed.
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
     * @param  list<string>  $cc  already filtered by the caller; this action sends to whatever it is given
     */
    public function execute(
        string $to,
        string $message,
        ?string $subject = null,
        array $attachmentUrls = [],
        ?string $templateName = null,
        bool $important = false,
        ?Agent $fromAgent = null,
        ?Users $signatureOwner = null,
        array $cc = [],
        ?CampaignRecipient $campaignRecipient = null,
    ): array {
        if (trim($to) === '') {
            throw new ValidationException('People has no email address to send to');
        }

        $subject ??= 'Message from ' . $this->people->company->name;
        $mailboxAddress = $fromAgent !== null ? new AgentMailboxService()->addressFor($fromAgent) : null;
        $ownerSignature = UserEmailSignature::forSender($signatureOwner, $this->people->company, $fromAgent);

        if ($mailboxAddress !== null) {
            new SendAsAgentMailboxAction(
                agent: $fromAgent,
                to: $to,
                subject: $subject,
                markdownBody: $message,
                cc: $cc,
                attachmentUrls: $attachmentUrls,
                signature: $ownerSignature,
                trackingPerson: $this->people,
                trackingCampaignRecipient: $campaignRecipient,
            )->execute();

            return [
                'channel' => 'email',
                'to' => $to,
                'template' => 'agent-mailbox',
                'from' => $mailboxAddress,
                'people_id' => $this->people->getId(),
            ];
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
        $notification->setSubject($subject);
        $notification->setUserSignature($ownerSignature);
        if ($important) {
            $notification->setImportant();
        }
        if ($cc !== []) {
            $notification->setCc($cc);
        }

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
