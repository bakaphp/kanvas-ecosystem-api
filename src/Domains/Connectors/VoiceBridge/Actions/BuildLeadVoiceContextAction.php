<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;

class BuildLeadVoiceContextAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly Agent $agent,
    ) {
    }

    /**
     * Build the initial_context payload for VoiceBridge from a Lead.
     * Follows the API spec:
     * {
     *   company_id, customer: {firstname, lastname, phone, email, role},
     *   kanvas_prompts: {background, steps},
     *   task: {vehicle_of_interest: {...}, ...leadContext}
     * }
     */
    public function execute(): array
    {
        $app = $this->lead->app;
        $people = $this->lead->people;

        $phone = Str::normalizePhoneNumber(
            $people->getCellPhones()->first()?->value
            ?? $people->getAllPhones()->first()?->value
            ?? ''
        );

        $owner = $this->lead->owner;
        $ownerName = trim(($owner?->firstname ?? '') . ' ' . ($owner?->lastname ?? ''));

        $customer = array_filter([
            'firstname' => $people->firstname ?? null,
            'lastname' => $people->lastname ?? null,
            'phone' => $phone ?: null,
            'email' => $people->getEmails()->first()?->value ?? null,
            'role' => $ownerName ?: null,
        ]);

        $background = is_array($this->agent->role['background'] ?? null)
            ? implode(' ', $this->agent->role['background'])
            : ($this->agent->role['background'] ?? '');

        $steps = is_array($this->agent->role['steps'] ?? null)
            ? implode(' ', $this->agent->role['steps'])
            : ($this->agent->role['steps'] ?? '');

        $leadContext = $this->lead->get(IntelligenceConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? [];

        return [
            'company_id' => (string) $app->get(ConfigurationEnum::COMPANY_ID->value),
            'customer' => $customer,
            'kanvas_prompts' => [
                'background' => $background,
                'steps' => $steps,
            ],
            'task' => $leadContext,
        ];
    }
}
