<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Microsoft\Client as MicrosoftClient;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Social\Messages\Models\Message;

/**
 * Polls Microsoft Graph API for new emails and creates Kanvas messages.
 *
 * This is the main email sync action, meant to run on a cron schedule via
 * SyncMicrosoftEmailCommand (kanvas:sync-microsoft-email) or as a workflow activity.
 *
 * Flow:
 * 1. Gets a valid access token (auto-refreshes if expired via Client::getValidAccessToken)
 * 2. Fetches emails received since the last sync (or last 24h on first run)
 * 3. For each new email (skips duplicates by Graph message ID):
 *    a. Fetches full email content via Client::getMessage()
 *    b. Looks up the sender in People contacts (PeoplesRepository::getByEmail)
 *    c. If contact found, looks for their active lead (LeadsRepository::getPeopleActiveLead)
 *    d. Creates a Kanvas message linked to the lead/channel via CreateMessageFromMicrosoftEmailAction
 * 4. Updates LAST_SYNC_AT timestamp on the company config
 *
 * Token storage keys use the pattern: {config_key}-{appId}-{companyId}
 * e.g., microsoft_access_token-2-1
 *
 * @see CreateMessageFromMicrosoftEmailAction
 * @see \Kanvas\Connectors\Microsoft\Client::getValidAccessToken
 */
class SyncMicrosoftEmailAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
    ) {
    }

    /**
     * @return array<int, Message>
     */
    public function execute(): array
    {
        $accessToken = MicrosoftClient::getValidAccessToken($this->app, $this->company);
        $configPrefix = '-' . $this->app->getId() . '-' . $this->company->getId();
        $lastSyncAt = (string) $this->company->get(ConfigurationEnum::LAST_SYNC_AT->value . $configPrefix);

        // OData filter: fetch only emails newer than last sync (or last 24h on first run)
        $filter = $lastSyncAt !== ''
            ? "receivedDateTime gt {$lastSyncAt}"
            : 'receivedDateTime gt ' . Carbon::now()->subDay()->toIso8601String();

        $emails = MicrosoftClient::listMessages($accessToken, top: 50, filter: $filter);

        $syncedMessages = [];

        foreach ($emails as $email) {
            $graphMessageId = $email['id'] ?? '';

            if ($graphMessageId === '') {
                continue;
            }

            // Skip already-synced emails by checking the Graph message ID stored in message JSON
            $existingMessage = Message::where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->where('message->message_id', $graphMessageId)
                ->where('is_deleted', 0)
                ->first();

            if ($existingMessage !== null) {
                continue;
            }

            // listMessages returns a summary; fetch full email with body for message creation
            $fullEmail = MicrosoftClient::getMessage($accessToken, $graphMessageId);
            $fromEmail = $fullEmail['from']['emailAddress']['address'] ?? '';

            // Try to match sender to an existing contact and their active lead
            $lead = null;
            if ($fromEmail !== '') {
                $people = PeoplesRepository::getByEmail($fromEmail, $this->company, $this->app);
                if ($people !== null) {
                    $lead = LeadsRepository::getPeopleActiveLead($people);
                }
            }

            $message = new CreateMessageFromMicrosoftEmailAction(
                $this->app,
                $this->company,
                $this->user,
                $fullEmail,
                $lead,
            )->execute();

            $syncedMessages[] = $message;
        }

        $this->company->set(
            ConfigurationEnum::LAST_SYNC_AT->value . $configPrefix,
            Carbon::now()->toIso8601String()
        );

        return $syncedMessages;
    }
}
