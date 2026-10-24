<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Support\RecordingFilePusher;
use Tests\Connectors\Integration\Zoho\Support\StubbedPushDealToZohoAction;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Exception\ApiError;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

final class PushDealToZohoActionTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testCreatesTheZohoDealAndKeepsItsId(): void
    {
        $deal = $this->makeDeal($this->makeZohoCompany());
        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data) => $data['Deal_Name'] === $deal->title && isset($data['Stage'])))
            ->willReturn(new Record(['id' => 'zd-100'], $module));
        $module->expects($this->never())->method('update');

        $result = new StubbedPushDealToZohoAction($deal, $module)->execute();

        $this->assertSame('zd-100', $result['zohoDealId']);
        $this->assertTrue($result['recordExists']);
        $this->assertSame('zd-100', $deal->get(CustomFieldEnum::ZOHO_DEAL_ID->value));
    }

    public function testUpdatesTheZohoDealItAlreadyKnows(): void
    {
        $deal = $this->makeDeal($this->makeZohoCompany());
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-7');
        $module = $this->module();
        $module->method('get')->with('zd-7')->willReturn(new Record(['id' => 'zd-7'], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('zd-7', $this->callback(fn (array $data) => $data['Deal_Name'] === $deal->title))
            ->willReturn(new Record(['id' => 'zd-7'], $module));
        $module->expects($this->never())->method('create');

        $result = new StubbedPushDealToZohoAction($deal, $module)->execute();

        $this->assertSame('zd-7', $result['zohoDealId']);
        $this->assertTrue($result['recordExists']);
    }

    public function testFlagsAZohoDealThatIsGoneWithoutTouchingTheKanvasDeal(): void
    {
        $deal = $this->makeDeal($this->makeZohoCompany());
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-7');
        $module = $this->module();
        $module->method('get')->with('zd-7')->willReturn(new Record([], $module));
        $module->expects($this->never())->method('update');
        $module->expects($this->never())->method('create');

        $result = new StubbedPushDealToZohoAction($deal, $module)->execute();

        $this->assertFalse($result['recordExists']);
        $this->assertNull($result['files']);
        $this->assertSame(0, (int) $deal->fresh()->is_deleted, 'a deal is the funding team\'s record; it is flagged, never closed');
        $this->assertSame('zd-7', $deal->get(CustomFieldEnum::ZOHO_DEAL_ID->value));
    }

    public function testRetriesOnceWithTheTypesZohoAskedFor(): void
    {
        $company = $this->makeZohoCompany();
        $deal = $this->makeDeal($company);
        $deal->set('funding_amount_requested', '$50,000');

        $rejection = new ApiError(new Response(400, [], json_encode([
            'data' => [[
                'code' => 'INVALID_DATA',
                'details' => ['api_name' => 'Amount_Requested', 'expected_data_type' => 'currency'],
            ]],
        ])));

        $module = $this->module();
        $payloads = [];
        $module->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$payloads, $rejection, $module) {
                $payloads[] = $data;

                if (count($payloads) === 1) {
                    throw $rejection;
                }

                return new Record(['id' => 'zd-200'], $module);
            });

        $result = new StubbedPushDealToZohoAction($deal, $module)->execute();

        $this->assertSame('$50,000', $payloads[0]['Amount_Requested']);
        $this->assertSame(50000.0, $payloads[1]['Amount_Requested']);
        $this->assertSame('zd-200', $result['zohoDealId']);
    }

    public function testEachFileReachesZohoOnceAcrossRuns(): void
    {
        $deal = $this->makeDeal($this->makeZohoCompany());
        $deal->addFileFromUrl('https://example.com/zoho/application.pdf', 'application.pdf');
        $deal->addFileFromUrl('https://example.com/zoho/bank-statement.pdf', 'bank-statement.pdf');
        $fileIds = $deal->files()->pluck('filesystem.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertCount(2, $fileIds);

        $module = $this->createStub(ZohoRecordsModule::class);
        $module->method('create')->willReturn(new Record(['id' => 'zd-300'], $module));
        $module->method('get')->willReturn(new Record(['id' => 'zd-300'], $module));
        $module->method('update')->willReturn(new Record(['id' => 'zd-300'], $module));
        $pusher = new RecordingFilePusher($module, 'zd-300');

        $first = new StubbedPushDealToZohoAction($deal, $module, $pusher)->execute();
        $this->assertSame([], $pusher->skipIdsSeen);
        $this->assertSame($fileIds, collect($first['files']['uploaded'])->sort()->values()->all());

        $second = new StubbedPushDealToZohoAction($deal, $module, $pusher)->execute();
        $this->assertSame($fileIds, collect($pusher->skipIdsSeen)->sort()->values()->all());
        $this->assertSame([], $second['files']['uploaded']);
    }

    private function module(): MockObject&ZohoRecordsModule
    {
        return $this->createMock(ZohoRecordsModule::class);
    }
}
