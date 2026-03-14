<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VoiceBridge;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VoiceBridge\Actions\BuildLeadVoiceContextAction;
use Kanvas\Connectors\VoiceBridge\Client;
use Kanvas\Connectors\VoiceBridge\DataTransferObject\VoiceBridge as VoiceBridgeDto;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Services\VoiceBridgeService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class SetupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:voice-bridge:setup
                            {app_id : The application ID}
                            {api_key : VoiceBridge API key (X-API-Key)}
                            {base_url : VoiceBridge base URL (e.g. https://voice-bridge-xxx.run.app)}
                            {company_id : VoiceBridge company ID for Twilio credentials resolution}
                            {lead_id? : Lead ID to test session init and call trigger}
                            {--trigger : Also trigger the call after session init (requires lead_id)}';

    protected $description = 'Configure and validate the VoiceBridge integration for an app';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $apiKey = $this->argument('api_key');
        $baseUrl = $this->argument('base_url');
        $companyId = $this->argument('company_id');

        $this->info('Configuring VoiceBridge...');
        $this->line("  App ID   : {$app->getId()} ({$app->name})");
        $this->line("  Base URL : {$baseUrl}");
        $this->line("  Company  : {$companyId}");
        $this->newLine();

        $dto = new VoiceBridgeDto(
            app: $app,
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            companyId: $companyId,
        );

        VoiceBridgeService::setup($dto);
        $this->info('Credentials stored.');

        $this->line('Validating connection...');
        try {
            $valid = Client::validateCredentials($apiKey, $baseUrl);

            if ($valid) {
                $this->info('Connection successful.');
            } else {
                $this->error('Connection failed: unexpected response from VoiceBridge.');

                return;
            }
        } catch (Throwable $e) {
            $this->error('Connection failed: ' . $e->getMessage());

            return;
        }

        $this->newLine();
        $this->line('Listing recent sessions...');
        try {
            $client = Client::getInstance($app);
            $sessions = $client->getSessions(['company_id' => $companyId, 'limit' => 5]);

            $count = $sessions['count'] ?? 0;
            $this->info("Found {$count} recent session(s).");

            foreach ($sessions['sessions'] ?? [] as $session) {
                $this->line(sprintf(
                    '  [%s] %s | %s | %s',
                    $session['session_type'] ?? '?',
                    $session['session_id'],
                    $session['phone_number'] ?? 'N/A',
                    $session['ended_at'] ? 'ended' : 'active',
                ));
            }
        } catch (Throwable $e) {
            $this->warn('Could not fetch sessions: ' . $e->getMessage());
        }

        if ($this->argument('lead_id')) {
            $this->newLine();
            $this->testWithLead($app, (int) $this->argument('lead_id'));
        }

        $this->newLine();
        $this->info('VoiceBridge setup completed successfully.');
    }

    private function testWithLead(Apps $app, int $leadId): void
    {
        $voiceBridgeCompanyId = (string) $app->get(ConfigurationEnum::COMPANY_ID->value);

        if (empty($voiceBridgeCompanyId)) {
            $this->error('VoiceBridge company_id is not configured. Re-run setup with the correct VoiceBridge company_id argument.');

            return;
        }

        $this->line("  VoiceBridge Company ID: {$voiceBridgeCompanyId} (from app config)");
        $this->newLine();

        $this->line("Loading lead #{$leadId}...");

        try {
            $lead = Lead::with(['people', 'people.contacts'])->findOrFail($leadId);
        } catch (Throwable $e) {
            $this->error("Lead #{$leadId} not found: " . $e->getMessage());

            return;
        }

        $phone = $this->resolveLeadPhone($lead);

        if (empty($phone)) {
            $this->error('Lead has no phone number. Cannot proceed.');

            return;
        }

        $people = $lead->people;
        $firstname = $people?->firstname ?? $lead->firstname ?? '';
        $lastname = $people?->lastname ?? $lead->lastname ?? '';
        $email = $people?->getEmails()->first()?->value ?? $lead->email ?? '';
        $userId = (string) ($lead->leads_owner_id ?: $lead->users_id ?: 'kanvas_crm');

        $this->line("  Lead     : {$firstname} {$lastname}");
        $this->line("  Phone    : {$phone}");
        $this->line("  Email    : {$email}");
        $this->line("  Owner ID : {$userId}");
        $this->newLine();

        $sessionId = VoiceBridgeService::buildOutboundSessionId((string) $lead->getId(), $phone, $voiceBridgeCompanyId);
        $this->line("  Session ID: {$sessionId}");

        try {
            $agent = Agent::fromApp($app)
                ->fromCompany($lead->company)
                ->where('name', 'voiceOutreachAgent')
                ->firstOrFail();
            $initialContext = new BuildLeadVoiceContextAction($lead, $agent)->execute();
        } catch (Throwable $e) {
            $this->warn('Could not load voice agent (will use hardcoded context): ' . $e->getMessage());
            $initialContext = [];
        }

        $client = Client::getInstance($app);

        $this->line('Checking for existing session...');
        try {
            $client->deleteSession($userId, $sessionId);
            $this->line('  Existing session deleted.');
        } catch (Throwable) {
            $this->line('  No existing session found, proceeding.');
        }

        $this->line('Initializing voice session...');

        // $initialContext = [
        //     'company_id' => 'id',
        //     'customer' => array_filter([
        //         "firstname"=> "name",
        //         "lastname"=> "lastname",
        //         "phone"=> "+numer",
        //         "role"=> "Ejecutiva de Ventas"
        //     ]),
        //     'kanvas_prompts' => [
        //         'background' => "background",
        //         'steps' => 'steps',
        //     ],
        //     'task' => [
        //         "vehicle_of_interest" => [
        //             "year" => 2026,
        //             "make" => "Kia",
        //             "model" => "Niro"
        //         ]
        //     ]
        // ];

        try {
            $result = $client->initSession(
                userId: $userId,
                sessionId: $sessionId,
                initialContext: $initialContext,
            );

            $this->info("Session init: {$result['message']}");
        } catch (Throwable $e) {
            $this->error('Session init failed: ' . $e->getMessage());

            return;
        }

        if (! $this->option('trigger')) {
            $this->line('(Pass --trigger to also trigger the call)');

            return;
        }

        $this->line('Triggering call...');
        try {
            $result = $client->triggerCall(
                userId: $userId,
                sessionId: $sessionId,
                phoneNumber: $phone,
                companyId: $voiceBridgeCompanyId,
            );

            $this->info("Call triggered. SID: " . ($result['call_sid'] ?? 'N/A'));
            $this->line("  Status  : " . ($result['status'] ?? 'N/A'));
            $this->line("  Company : " . ($result['company_id'] ?? 'N/A'));
        } catch (Throwable $e) {
            $this->error('Call trigger failed: ' . $e->getMessage());
        }
    }

    private function resolveLeadPhone(Lead $lead): string
    {
        if ($lead->people) {
            $contact = $lead->people->getAllPhones()->first();
            if ($contact && ! empty($contact->value)) {
                return $contact->value;
            }
        }

        return $lead->phone ?? '';
    }
}
