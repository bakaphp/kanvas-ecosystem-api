<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kiwilan\XmlReader\XmlReader;

class CreateLeadFromADFAction
{
    public function __construct(
        protected ReceiverWebhookCall $webhookRequest
    ) {
    }

    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $app = $this->webhookRequest->receiverWebhook->app;
        $company = $this->webhookRequest->receiverWebhook->company;
        $xml = simplexml_load_string($payload['body-plain']);

        $xml = XmlReader::make($payload['body-plain'], true, true);
        $data = $xml->toArray();
        $people = PeoplesRepository::getMatchingEmailPhone(
            $app,
            $company,
            $data['adf']['prospect']['customer']['contact']['email'] ?? null,
            $data['adf']['prospect']['customer']['contact']['phone']['@content'] ?? null,
        );
        if ($people) {
            $requestDate = Carbon::parse($data['adf']['prospect']['requestdate']);
            $minutesForMatch = $company->get(ConfigurationEnum::MINUTES_FOR_MATCH_ADF_LEAD->value) ?? 30;
            $lead = Lead::where('apps_id', $app->id)
                ->where('companies_id', $company->id)
                ->where('people_id', $people->id)
                ->whereBetween('created_at', [
                    $requestDate->toDateTimeString(),
                    $requestDate->copy()->addMinutes($minutesForMatch)->toDateTimeString(),
                ])
                ->latest()
                ->first();
            $lead->set(LeadCustomFieldEnum::ADF_LEAD_XML->value, $data);
        }

        return [
            'body-plain' => $payload['body-plain'] ?? null,
            'stripped-text' => $payload['stripped-text'] ?? null,
        ];
    }
}
