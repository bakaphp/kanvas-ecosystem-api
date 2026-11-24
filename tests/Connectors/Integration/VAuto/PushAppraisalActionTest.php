<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VAuto;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VAuto\Actions\PushAppraisalAction;
use Kanvas\Connectors\VAuto\Client;
use Kanvas\Connectors\VAuto\Enums\CustomFieldEnum;
use Kanvas\Connectors\VAuto\Exceptions\TradeInNotAppraisableException;
use Kanvas\Connectors\VAuto\Exceptions\VAutoException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Mockery;
use SoapClient;
use Tests\Connectors\Traits\HasVAutoConfiguration;
use Tests\TestCase;

final class PushAppraisalActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasVAutoConfiguration;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    private const array COMPLETE_FORM = [
        'vin' => '1HGCM82633A004352',
        'year' => '2019',
        'make' => 'Honda',
        'model' => 'Accord',
    ];

    private Companies $company;
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->vAutoCompany();
        $this->lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $this->company->getId())
            ->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPushesTheTradeInAndStoresTheAppraisalOnMessageAndLead(): void
    {
        $message = $this->tradeInMessage(self::COMPLETE_FORM + [
            'trim' => 'EX-L',
            'body_style' => 'Sedan',
            'mileage' => '45,210',
            'payoff_amount' => '$12,500.50',
        ]);
        $sent = null;

        $soap = $this->soapClientReturning(
            $this->appraisalResponse(9001, 'https://vauto.test/appraisal/9001'),
            function (array $payload) use (&$sent): bool {
                $sent = $payload['CreateAppraisal']['request'];

                return true;
            }
        );

        $result = $this->push($message, $soap);

        $this->assertSame([
            'appraisal_id' => '9001',
            'deep_link' => 'https://vauto.test/appraisal/9001',
        ], $result);

        $this->assertSame(self::VAUTO_ENTITY_ID, $sent['EntityContext']['EntityId']);
        $this->assertSame(Client::DEFAULT_USER_NAME, $sent['EntityContext']['User']['UserName']);
        $this->assertSame(0, $sent['ShouldDeDupOnVin']);

        $appraisal = $sent['Appraisal'];
        $this->assertSame('1HGCM82633A004352', $appraisal['Vin']);
        $this->assertSame('2019', $appraisal['ModelYear']);
        $this->assertSame('Honda', $appraisal['Make']);
        $this->assertSame('Accord', $appraisal['Model']);
        $this->assertSame('EX-L', $appraisal['Series']);
        $this->assertSame(45210, $appraisal['Odometer']);
        $this->assertSame('12500.50', $appraisal['LienPayoff']);
        $this->assertSame('Payoff Amount : 12500.50', $appraisal['VehicleDescription']);
        $this->assertSame($this->lead->people->firstname, $appraisal['Customer']['FirstName']);
        $this->assertSame($this->lead->people->lastname, $appraisal['Customer']['LastName']);

        $this->assertSame('9001', (string) $message->get(CustomFieldEnum::APPRAISAL_ID->value));
        $this->assertSame('https://vauto.test/appraisal/9001', $message->get(CustomFieldEnum::APPRAISAL_DEEP_LINK->value));
        $this->assertSame('9001', (string) $this->lead->get(CustomFieldEnum::APPRAISAL_ID->value));
        $this->assertSame('https://vauto.test/appraisal/9001', $this->lead->get(CustomFieldEnum::APPRAISAL_DEEP_LINK->value));
    }

    public function testWithoutAPayoffTheDescriptionAndLienAreEmpty(): void
    {
        $sent = null;

        $soap = $this->soapClientReturning(
            $this->appraisalResponse(1),
            function (array $payload) use (&$sent): bool {
                $sent = $payload['CreateAppraisal']['request']['Appraisal'];

                return true;
            }
        );

        $this->push($this->tradeInMessage(self::COMPLETE_FORM), $soap);

        $this->assertSame(0, $sent['LienPayoff']);
        $this->assertSame('', $sent['VehicleDescription']);
    }

    public function testAMessageAlreadyAppraisedIsNotPushedTwice(): void
    {
        $message = $this->tradeInMessage(self::COMPLETE_FORM);
        $message->set(CustomFieldEnum::APPRAISAL_ID->value, '555');
        $message->set(CustomFieldEnum::APPRAISAL_DEEP_LINK->value, 'https://vauto.test/appraisal/555');

        $result = $this->push($message, $this->soapClientNeverCalled());

        $this->assertSame('555', $result['appraisal_id']);
        $this->assertSame('https://vauto.test/appraisal/555', $result['deep_link']);
        $this->assertArrayHasKey('skipped', $result);
    }

    public function testAFormWithoutVinOrYearMakeModelIsNotAppraisable(): void
    {
        $this->expectException(TradeInNotAppraisableException::class);

        $this->push(
            $this->tradeInMessage(['mileage' => '1000', 'payoff_amount' => '500']),
            $this->soapClientNeverCalled()
        );
    }

    public function testAResponseWithoutAnAppraisalIdIsAFault(): void
    {
        $message = $this->tradeInMessage(self::COMPLETE_FORM);

        $this->expectException(VAutoException::class);
        $this->expectExceptionMessage('no AppraisalId');

        $this->push($message, $this->soapClientReturning((object) ['CreateAppraisalResult' => (object) []]));

        $this->assertNull($message->get(CustomFieldEnum::APPRAISAL_ID->value));
    }

    private function push(Message $message, SoapClient $soap): array
    {
        return new PushAppraisalAction($this->lead, $message, new Client($this->company, $soap))->execute();
    }

    private function tradeInMessage(array $form): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'message' => [
                    'verb' => 'trade-walk',
                    'status' => 'submitted',
                    'data' => ['form' => $form],
                ],
            ]);
    }
}
