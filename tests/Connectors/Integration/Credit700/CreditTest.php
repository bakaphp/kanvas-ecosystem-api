<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Credit700;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Connectors\Credit700\Services\CreditScoreService;
use Kanvas\Guild\Customers\Models\People;
use Mockery;
use Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CreditTest extends TestCase
{
    public function testCreditScore(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::ACCOUNT->value, 'test_account');
        $app->set(ConfigurationEnum::PASSWORD->value, 'test_password');
        $app->set(ConfigurationEnum::CLIENT_ID->value, 'test_client_id');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, 'test_client_secret');

        // Mock the Credit700 Client using overload (mocks `new Client()`)
        $mockClient = Mockery::mock('overload:' . Client::class);
        $mockClient->shouldReceive('post')
            ->with('/Request', Mockery::type('array'))
            ->andReturn([
                'bureau_xml_data' => [
                    'TU_Report' => [
                        'ScoreRange' => '750',
                        'Score' => '750',
                    ],
                ],
                'custom_report_url' => [
                    'iframe' => [
                        '@attributes' => [
                            'src' => 'https://example.com/report',
                        ],
                    ],
                ],
                'pdf_report' => [
                    'tu_pdf_report' => null,
                ],
            ]);
        $mockClient->shouldReceive('signUrl')
            ->andReturn('https://example.com/signed-report');

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $creditApplication = new CreditApplicant(
            $people->getName(),
            fake()->streetAddress,
            fake()->city,
            fake()->state,
            fake()->postcode,
            fake()->ssn
        );

        $creditScoreAction = new CreditScoreService($app);
        $creditScore = $creditScoreAction->getCreditScore($creditApplication, $user);

        $this->assertArrayHasKey('scores', $creditScore);
        $this->assertArrayHasKey('TU', $creditScore['scores']);
        $this->assertTrue($creditScore['pull_credit_pass']);
    }
}
