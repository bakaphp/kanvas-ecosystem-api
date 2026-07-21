<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Connectors\Salesforce\Webhooks\SalesforceOutboundMessageWebhookJob;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class SalesforceOutboundMessageWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    public function testParsesLeadOutboundMessageAndCreatesLead(): void
    {
        $receiver = $this->makeReceiver('Lead');
        $xml = $this->leadOutboundMessageXml('00Qxx0000004C92AAE', 'Jane', 'Doe');

        $result = $this->dispatchWebhookJob($receiver, $xml);

        $this->assertSame('Lead', $result['salesforce_object']);
        $this->assertSame(1, $result['processed']);

        $lead = Lead::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SALESFORCE_LEAD_ID->value,
            '00Qxx0000004C92AAE',
            $receiver->company,
        );

        $this->assertNotNull($lead);
        $this->assertSame('Jane', $lead->firstname);
        $this->assertSame('Doe', $lead->lastname);
    }

    public function testParsesOpportunityOutboundMessageAndCreatesDeal(): void
    {
        $receiver = $this->makeReceiver('Opportunity');
        $xml = $this->opportunityOutboundMessageXml('006xx000004TmXtAAK', 'Big Opportunity');

        $result = $this->dispatchWebhookJob($receiver, $xml);

        $this->assertSame('Opportunity', $result['salesforce_object']);
        $this->assertSame(1, $result['processed']);

        $deal = Deal::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID->value,
            '006xx000004TmXtAAK',
            $receiver->company,
        );

        $this->assertNotNull($deal);
        $this->assertSame('Big Opportunity', $deal->title);
    }

    public function testInvalidXmlIsHandledGracefully(): void
    {
        $receiver = $this->makeReceiver('Lead');

        $result = $this->dispatchWebhookJob($receiver, 'not xml at all');

        $this->assertSame('Unable to parse Salesforce Outbound Message XML', $result['message']);
    }

    private function makeReceiver(string $salesforceObject): ReceiverWebhook
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => SalesforceOutboundMessageWebhookJob::class],
            ['name' => 'SalesforceOutboundMessageWebhookJob'],
        );

        return ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => ['salesforce_object' => $salesforceObject],
            ]);
    }

    private function dispatchWebhookJob(ReceiverWebhook $receiver, string $rawXml): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $receiver->uuid,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/xml'],
            $rawXml,
        );

        $webhookRequest = new ProcessWebhookAttemptAction($receiver, $request)->execute();

        $job = new SalesforceOutboundMessageWebhookJob($webhookRequest);

        return $job->handle();
    }

    private function leadOutboundMessageXml(string $id, string $firstName, string $lastName): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <notifications xmlns="http://soap.sforce.com/2005/09/outbound">
      <OrganizationId>00Dxx0000001gEREAY</OrganizationId>
      <ActionId>04kxx00000000ZeEAI</ActionId>
      <Notification>
        <Id>04lxx0000008yZDEAY</Id>
        <sObject xsi:type="sf:Lead" xmlns:sf="urn:sobject.enterprise.soap.sforce.com" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
          <sf:Id>{$id}</sf:Id>
          <sf:FirstName>{$firstName}</sf:FirstName>
          <sf:LastName>{$lastName}</sf:LastName>
          <sf:Email>jane.doe@example.com</sf:Email>
          <sf:Status>Open</sf:Status>
        </sObject>
      </Notification>
    </notifications>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function opportunityOutboundMessageXml(string $id, string $name): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <notifications xmlns="http://soap.sforce.com/2005/09/outbound">
      <OrganizationId>00Dxx0000001gEREAY</OrganizationId>
      <ActionId>04kxx00000000ZeEAI</ActionId>
      <Notification>
        <Id>04lxx0000008yZDEAY</Id>
        <sObject xsi:type="sf:Opportunity" xmlns:sf="urn:sobject.enterprise.soap.sforce.com" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
          <sf:Id>{$id}</sf:Id>
          <sf:Name>{$name}</sf:Name>
          <sf:StageName>Prospecting</sf:StageName>
        </sObject>
      </Notification>
    </notifications>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }
}
