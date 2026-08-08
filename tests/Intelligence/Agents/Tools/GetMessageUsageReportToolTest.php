<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetMessageUsageReportTool;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class GetMessageUsageReportToolTest extends TestCase
{
    private function seedUsage(Companies $company): void
    {
        $app = app(Apps::class);

        $sms = MessageType::factory()->create(['apps_id' => $app->getId(), 'verb' => 'twilio-sms']);
        $email = MessageType::factory()->create(['apps_id' => $app->getId(), 'verb' => 'mailgun-email']);

        $make = function (MessageType $type, array $payload) use ($app, $company): void {
            Message::factory()->create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'message_types_id' => $type->getId(),
                'message' => $payload,
            ]);
        };

        // 2 human SMS, 1 AI SMS, 1 inbound SMS, 1 human email
        $make($sms, ['content' => 'hi', 'from_me' => true]);
        $make($sms, ['content' => 'hi', 'from_me' => true]);
        $make($sms, ['content' => 'hi', 'from_me' => true, 'from_ia' => true]);
        $make($sms, ['content' => 'hi', 'from_me' => false]);
        $make($email, ['content' => 'hi', 'from_me' => true]);
    }

    private function tool(Companies $company): GetMessageUsageReportTool
    {
        return new GetMessageUsageReportTool()->withContext(app(Apps::class), $company, auth()->user());
    }

    /**
     * @param  array<int, array<string, mixed>>  $breakdown
     * @return array<string, int>
     */
    private function nameCountMap(array $breakdown): array
    {
        $map = [];
        foreach ($breakdown as $row) {
            $map[$row['name']] = $row['count'];
        }

        return $map;
    }

    public function testReportsTotalsAndHumanVsAiSplit(): void
    {
        $company = Companies::factory()->create();
        $this->seedUsage($company);

        $result = $this->tool($company)('last_7_days', 'all');

        $this->assertSame('success', $result['status']);
        $this->assertSame(5, $result['totals']['messages']);

        $bySender = $this->nameCountMap($result['totals']['by_sender']);
        $this->assertSame(3, $bySender[MessageSenderTypeEnum::USER->label()]);
        $this->assertSame(1, $bySender[MessageSenderTypeEnum::AGENT->label()]);
        $this->assertSame(1, $bySender[MessageSenderTypeEnum::CONTACT->label()]);
    }

    public function testChannelFilterNarrowsToSmsOrEmail(): void
    {
        $company = Companies::factory()->create();
        $this->seedUsage($company);

        $this->assertSame(4, $this->tool($company)('last_7_days', 'sms')['totals']['messages']);
        $this->assertSame(1, $this->tool($company)('last_7_days', 'email')['totals']['messages']);
    }

    public function testInvalidChannelReturnsError(): void
    {
        // Error path validates before any query — reuse the current company (no isolation needed).
        $result = $this->tool(auth()->user()->getCurrentCompany())('last_7_days', 'carrier-pigeon');

        $this->assertSame('error', $result['status']);
    }
}
