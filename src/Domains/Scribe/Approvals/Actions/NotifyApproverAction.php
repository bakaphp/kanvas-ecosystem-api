<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Client as SlackClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Scribe\Approvals\Enums\ApprovalConfigurationEnum;
use Throwable;

/**
 * Best-effort Slack DM to the approver — resolved by looking up their email in Slack, since each
 * vendor/customer has its own approver rather than one fixed for the whole app. Silently does
 * nothing when Slack isn't configured or the email doesn't match a Slack workspace member, and
 * never lets a Slack failure block the record it's notifying about — that record is already
 * safely created by the time this runs.
 */
class NotifyApproverAction
{
    public function __construct(
        protected readonly Apps $app,
        protected readonly string $text,
        protected readonly ?string $approverEmail = null,
        protected readonly ?string $attachmentUrl = null,
        protected readonly ?string $attachmentFilename = null,
    ) {
    }

    public function execute(): void
    {
        $agentId = (string) ($this->app->get(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value) ?? '');

        if ($this->approverEmail === null || trim($this->approverEmail) === '' || $agentId === '') {
            return;
        }

        try {
            $agent = Agent::find((int) $agentId);

            if ($agent === null) {
                return;
            }

            $client = SlackClient::getInstanceByAgent($agent);
            $slackUserId = $client->lookupUserIdByEmail($this->approverEmail);

            if ($slackUserId === null) {
                return;
            }

            $dmChannel = $client->openDirectMessageChannel($slackUserId);

            if ($this->tryUploadAttachment($client, $dmChannel)) {
                return;
            }

            $client->postMessage($dmChannel, $this->text);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Uploads the invoice PDF as a real Slack attachment, with $this->text as its caption. Returns false (never throws) so execute() falls back to a plain text message on any failure. */
    private function tryUploadAttachment(SlackClient $client, string $dmChannel): bool
    {
        if ($this->attachmentUrl === null || trim($this->attachmentUrl) === '') {
            return false;
        }

        try {
            $contents = Http::timeout(30)->get($this->attachmentUrl)->throw()->body();
            $filename = $this->attachmentFilename !== null && trim($this->attachmentFilename) !== ''
                ? $this->attachmentFilename
                : basename(parse_url($this->attachmentUrl, PHP_URL_PATH) ?: 'invoice.pdf');

            $client->uploadFile($dmChannel, $filename, $contents, $this->text);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
