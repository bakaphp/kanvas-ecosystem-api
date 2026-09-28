<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Services\AdfXmlParserService;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\NervousSystem\DailyLearning\Services\CycleWindowResolverService;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class PullLeadFromADFAction
{
    public function __construct(
        protected ReceiverWebhookCall $webhookRequest
    ) {
    }

    public function execute(): ?Lead
    {
        $payload = $this->webhookRequest->payload;
        $app = $this->webhookRequest->receiverWebhook->app;
        $company = $this->webhookRequest->receiverWebhook->company;
        $data = AdfXmlParserService::toArray($payload['body-plain'] ?? null);

        if (! isset($data['adf']['prospect'])) {
            return null;
        }

        $contact = $data['adf']['prospect']['customer']['contact'] ?? [];
        $email = AdfXmlParserService::content($contact['email'] ?? null);
        $phone = AdfXmlParserService::content($contact['phone'] ?? null);

        $people = PeoplesRepository::getMatchingEmailPhone(
            $app,
            $company,
            $email,
            $phone,
        );

        if (! $people) {
            return null;
        }

        // created_at is stored in UTC. An explicit offset (CARFAX: -04:00) wins; a requestdate without one
        // is the dealer's wall clock, so it is read in the tenant's timezone rather than as UTC.
        $requestDate = Carbon::parse(
            $data['adf']['prospect']['requestdate'],
            CycleWindowResolverService::resolveTimezone($app, $company)
        )->utc();
        $minutesForMatch = $company->get(ConfigurationEnum::MINUTES_FOR_MATCH_ADF_LEAD->value) ?? 30;

        $lead = Lead::fromApp($app)
            ->fromCompany($company)
            ->where('people_id', $people->id)
            ->whereBetween('created_at', [
                $requestDate->copy()->subMinutes(5)->toDateTimeString(),
                $requestDate->copy()->addMinutes($minutesForMatch)->toDateTimeString(),
            ])
            ->latest()
            ->first();

        $lead?->set(LeadCustomFieldEnum::ADF_LEAD_XML->value, $data);

        return $lead;
    }
}
