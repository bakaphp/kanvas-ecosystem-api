<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Approvals;

use Baka\Support\Str;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\DraftCustomerUpdateAction;
use Kanvas\Intelligence\Agents\Contracts\AgentApprovalHandler;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateRenderer;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Social\Messages\Models\Message;
use Override;

/**
 * What approving a monthly customer update DOES: mail it, then record that this account has been told.
 *
 * Reuses KIND_EMAIL_DRAFT so the existing email-draft card renders it, which also fixes the recipient
 * convention — `chat_jid` on the message, the same place SendAgentEmailAction reads it from.
 */
class CustomerUpdateApprovalHandler implements AgentApprovalHandler
{
    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function approve(Message $message, array $context): void
    {
        $recipient = Str::trimToNull(
            $context['recipient'] ?? ($message->message['chat_jid'] ?? null)
        );

        if ($recipient === null) {
            throw new ValidationException('This update has no recipient — choose who to send it to before approving.');
        }

        // The card content, not the original draft: a human may edit the copy before approving, and
        // mailing the unedited version would send what nobody signed off on.
        $markdown = Str::trimToNull($message->message['content'] ?? null);

        if ($markdown === null) {
            throw new ValidationException('This update has no content to send.');
        }

        $app = Apps::getById((int) $message->apps_id);
        $company = Companies::getById((int) $message->companies_id);

        $smtp = new SmtpRuntimeConfiguration($app, $company);
        $from = $smtp->getFromEmail();

        Mail::send(
            new KanvasMailable($smtp->loadSmtpSettings(), new CustomerUpdateRenderer()->toEmailHtml($markdown, $app, $company))
                ->from($from['address'], $from['name'])
                ->to($recipient)
                ->subject((string) ($context['subject'] ?? ''))
        );

        $this->markCovered($context, $company, $app);
    }

    /**
     * Watermark only after the send actually happened. Advancing on approval-but-failed-send would skip
     * next month past releases this account was never told about, and the miss would be invisible.
     *
     * @param array<string, mixed> $context
     */
    private function markCovered(array $context, Companies $company, Apps $app): void
    {
        $coveredThrough = Str::trimToNull($context['covered_through'] ?? null);
        $organizationId = (int) ($context['organization_id'] ?? 0);

        if ($coveredThrough === null || $organizationId <= 0) {
            return;
        }

        /** @var Organization $organization */
        $organization = Organization::getByIdFromCompanyApp($organizationId, $company, $app);
        $organization->set(DraftCustomerUpdateAction::WATERMARK_FIELD, $coveredThrough);
    }
}
