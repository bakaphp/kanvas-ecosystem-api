<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Actions\DescribeCompanyTwilioAction;
use Kanvas\Connectors\Twilio\Actions\ListCompanyPhoneNumbersAction;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Tests\Stubs\Intelligence\FakeTwilioAccountClient;
use Tests\TestCase;
use Twilio\Rest\Client;

class DescribeCompanyTwilioActionTest extends TestCase
{
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Companies::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value);

        parent::tearDown();
    }

    public function testACompanyWithoutCredentialsIsNotConnected(): void
    {
        $twilio = new DescribeCompanyTwilioAction($this->company)->execute();

        $this->assertFalse($twilio['connected']);
        $this->assertSame('Twilio is not connected for this company.', $twilio['error']);
        $this->assertSame([], $twilio['problems']);
    }

    public function testAHealthyAccountHasNoProblems(): void
    {
        $twilio = $this->describe(new FakeTwilioAccountClient());

        $this->assertTrue($twilio['connected']);
        $this->assertSame('Sally Dealer', $twilio['account_name']);
        $this->assertSame('fake', $twilio['account_sid_suffix']);
        $this->assertSame('Full', $twilio['type']);
        $this->assertSame(25.5, $twilio['balance']);
        $this->assertSame('USD', $twilio['currency']);
        $this->assertSame(1, $twilio['number_count']);
        $this->assertSame([], $twilio['problems']);
    }

    public function testASuspendedTrialAccountWithNoBalanceSaysWhy(): void
    {
        $twilio = $this->describe(new FakeTwilioAccountClient(status: 'suspended', type: 'Trial', balance: '0.00'));

        $this->assertSame(
            ['status' => 'error', 'trial' => 'warning', 'balance' => 'error'],
            array_column($twilio['problems'], 'severity', 'key')
        );
    }

    public function testAnUnknownBalanceIsNotReportedAsEmpty(): void
    {
        $twilio = $this->describe(new FakeTwilioAccountClient(balance: null));

        $this->assertNull($twilio['balance']);
        $this->assertSame([], $twilio['problems']);
    }

    public function testADefaultFromNumberFromAnotherAccountIsFlagged(): void
    {
        $this->company->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, '+12722917870');

        $twilio = $this->describe(new FakeTwilioAccountClient());

        $this->assertSame('+12722917870', $twilio['from_number']);
        $this->assertSame(['from_number' => 'warning'], array_column($twilio['problems'], 'severity', 'key'));
    }

    public function testTheDefaultFromNumberOnTheAccountIsFine(): void
    {
        $this->company->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, FakeTwilioAccountClient::NUMBER);

        $this->assertSame([], $this->describe(new FakeTwilioAccountClient())['problems']);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(FakeTwilioAccountClient $client): array
    {
        $account = new class ($this->company, $client) extends ListCompanyPhoneNumbersAction {
            public function __construct(Companies $company, private FakeTwilioAccountClient $client)
            {
                parent::__construct($company);
            }

            protected function twilio(): Client
            {
                return $this->client;
            }
        };

        return new class ($this->company, $account) extends DescribeCompanyTwilioAction {
            public function __construct(Companies $company, private ListCompanyPhoneNumbersAction $fake)
            {
                parent::__construct($company);
            }

            protected function account(): ListCompanyPhoneNumbersAction
            {
                return $this->fake;
            }
        }->execute();
    }
}
