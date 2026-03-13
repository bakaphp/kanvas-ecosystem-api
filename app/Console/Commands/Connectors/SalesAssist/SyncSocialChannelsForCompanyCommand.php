<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\SalesAssist;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Actions\CreateSocialChannelForContactAction;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;

class SyncSocialChannelsForCompanyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:sales-assist-sync-channels {app_id} {company_id} {agent_id} {--limit=500} {--chunk=50}';

    protected $description = 'Create social channels for leads in a company that do not have sessions';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $agentId = (int) $this->argument('agent_id');
        $limit = (int) $this->option('limit');
        $chunk = (int) $this->option('chunk');

        $this->info("Syncing social channels for company {$company->name} (ID: {$company->getId()})");
        $this->info("App: {$app->name}, Agent ID: {$agentId}");

        $processedLeads = 0;
        $createdChannels = 0;
        $skippedContacts = 0;

        Lead::fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('leads_status_id', '!=', 0)
            ->limit($limit)
            ->chunk($chunk, function ($leads) use ($app, $agentId, &$processedLeads, &$createdChannels, &$skippedContacts) {
                foreach ($leads as $lead) {
                    $processedLeads++;
                    $people = $lead->people;

                    if (! $people) {
                        $this->line("Lead {$lead->getId()}: No people associated, skipping");
                        continue;
                    }

                    $contacts = $people->contacts;

                    if ($contacts->isEmpty()) {
                        $this->line("Lead {$lead->getId()}: No contacts found, skipping");
                        continue;
                    }

                    foreach ($contacts as $contact) {
                        if ($this->contactHasSession($contact, $app)) {
                            $skippedContacts++;
                            continue;
                        }

                        $contactTypesAllowed = [
                            ContactTypeEnum::CELLPHONE->value,
                            ContactTypeEnum::PHONE->value,
                            ContactTypeEnum::EMAIL->value,
                        ];

                        if (! in_array($contact->contacts_types_id, $contactTypesAllowed, true)) {
                            continue;
                        }

                        $result = new CreateSocialChannelForContactAction(
                            $contact,
                            $app,
                            ['agent_id' => $agentId],
                            $lead,
                            sendPusherNotification: false
                        )->execute();

                        if (! empty($result['success']) && ! empty($result['is_new_channel'])) {
                            $createdChannels++;
                            $this->line("Lead {$lead->getId()}: Created channel for contact {$contact->value}");
                        }
                    }
                }
            });

        $this->newLine();
        $this->info("Sync completed!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Leads processed', $processedLeads],
                ['Channels created', $createdChannels],
                ['Contacts skipped (already have session)', $skippedContacts],
            ]
        );
    }

    private function contactHasSession($contact, Apps $app): bool
    {
        $communicationChannel = match ($contact->contacts_types_id) {
            ContactTypeEnum::CELLPHONE->value => 'sms',
            ContactTypeEnum::EMAIL->value => 'email',
            default => null,
        };

        if ($communicationChannel === null) {
            return false;
        }

        $contactValue = $contact->value;
        if ($communicationChannel === 'sms') {
            $contactValue = Str::normalizePhoneNumber($contact->value);
        }

        $canalId = SessionChannelService::createCanalId($communicationChannel, $contactValue);

        return Session::fromApp($app)
            ->where('canal_id', $canalId)
            ->exists();
    }
}
