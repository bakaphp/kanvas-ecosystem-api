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
        $xml = XmlReader::make($payload['body-plain'], true, true);

        $data = $xml->toArray();

        if (! isset($data['adf']['prospect'])) {
            return null;
        }

        // Extract email - handle both string and array formats
        $emailData = $data['adf']['prospect']['customer']['contact']['email'] ?? null;
        $email = is_array($emailData) ? ($emailData['@content'] ?? null) : $emailData;

        // Extract phone - handle both string and array formats
        $phoneData = $data['adf']['prospect']['customer']['contact']['phone'] ?? null;
        $phone = is_array($phoneData) ? ($phoneData['@content'] ?? null) : $phoneData;

        $people = PeoplesRepository::getMatchingEmailPhone(
            $app,
            $company,
            $email,
            $phone,
        );

        if (! $people) {
            return null;
        }

        $requestDate = Carbon::parse($data['adf']['prospect']['requestdate']);
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

        return $lead ?? null;
    }
}
