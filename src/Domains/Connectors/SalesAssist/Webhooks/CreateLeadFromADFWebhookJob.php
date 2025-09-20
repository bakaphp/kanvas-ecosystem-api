<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Webhooks;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kiwilan\XmlReader\XmlReader;
use Override;

class CreateLeadFromADFWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        $xml = simplexml_load_string($payload['body-plain']);

        $xml = XmlReader::make($payload['body-plain'], true, true);
        $data = $xml->toArray();
        $people = PeoplesRepository::getByValue(
            $data['adf']['prospect']['customer']['contact']['email'] ?? '',
            $this->receiver->company,
            $this->receiver->app
        );
        $people->leads->latest()->first()?->set(LeadCustomFieldEnum::ADF_LEAD_XML->value, $data);

        return [
            'body-plain' => $payload['body-plain'] ?? null,
            'stripped-text' => $payload['stripped-text'] ?? null,
        ];
    }
}
