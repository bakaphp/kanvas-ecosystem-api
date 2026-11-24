<?php

declare(strict_types=1);

namespace Tests\Intelligence\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Leads\Services\ContactHistoryService;
use Tests\TestCase;

final class ContactHistoryServiceTest extends TestCase
{
    public function testRecordsNewestFirstAndCapsEntries(): void
    {
        $lead = $this->createLead();
        $service = new ContactHistoryService();

        for ($i = 1; $i <= ContactHistoryService::MAX_ENTRIES + 2; $i++) {
            $service->record(
                entity: $lead,
                channel: 'voice',
                direction: 'outbound',
                summary: "Call {$i}",
            );
        }

        $stored = $lead->get(ConfigurationEnum::LEAD_CONTACT_HISTORY->value);
        $this->assertCount(ContactHistoryService::MAX_ENTRIES, $stored);
        $this->assertSame('Call ' . (ContactHistoryService::MAX_ENTRIES + 2), $stored[0]['summary']);
        $this->assertSame('voice', $stored[0]['channel']);
        $this->assertSame('outbound', $stored[0]['direction']);
        $this->assertNotEmpty($stored[0]['at']);
    }

    public function testBlankSummaryIsNotRecorded(): void
    {
        $lead = $this->createLead();

        new ContactHistoryService()->record(
            entity: $lead,
            channel: 'voice',
            direction: 'inbound',
            summary: '   ',
        );

        $this->assertSame([], new ContactHistoryService()->forLead($lead));
    }

    public function testForLeadIncludesHistoryRecordedBeforePromotion(): void
    {
        $lead = $this->createLead();
        $service = new ContactHistoryService();

        $service->record(
            entity: $lead->people,
            channel: 'voice',
            direction: 'inbound',
            summary: 'Asked about a Civic',
        );
        $service->record(
            entity: $lead,
            channel: 'sms',
            direction: 'outbound',
            summary: 'Sent the Civic price',
        );

        $history = $service->forLead($lead);

        $this->assertCount(2, $history);
        $this->assertSame(
            ['Sent the Civic price', 'Asked about a Civic'],
            array_column($history, 'summary')
        );
    }

    public function testPromptLinesKeepRuntimeHeaderAndAddOneOtherwise(): void
    {
        $lines = new ContactHistoryService()->toPromptLines([
            ['channel' => 'voice', 'direction' => 'outbound', 'summary' => '[voice outbound 2026-11-03 14:15 UTC, voicemail] Voicemail left.', 'at' => '2026-11-03T14:15:00+00:00'],
            ['channel' => 'sms', 'direction' => 'inbound', 'summary' => 'Wants a test drive', 'at' => '2026-11-02T10:00:00+00:00'],
        ]);

        $this->assertSame(
            "- [voice outbound 2026-11-03 14:15 UTC, voicemail] Voicemail left.\n"
            . '- [sms inbound, 2026-11-02T10:00:00+00:00] Wants a test drive',
            $lines
        );
    }

    public function testLegacyStringContextInfoIsReadAsArray(): void
    {
        $lead = $this->createLead();
        $lead->set(ConfigurationEnum::LEAD_CONTEXT_INFO->value, 'Interest: 2021 Civic');

        $this->assertSame(['voice_context' => 'Interest: 2021 Civic'], $lead->getAiContextInfo());

        $lead->set(ConfigurationEnum::LEAD_CONTEXT_INFO->value, ['lead_intent' => ['lead_intent' => 'buy']]);

        $this->assertSame(['lead_intent' => ['lead_intent' => 'buy']], $lead->getAiContextInfo());
    }

    private function createLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
    }
}
