<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Credit700;

use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Connectors\Credit700\Services\DataPushMultiMappingService;
use Kanvas\Guild\Customers\Models\People;
use SimpleXMLElement;
use Tests\TestCase;

final class DataPushTest extends TestCase
{
    public function testDataPush(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::ACCOUNT->value, 'SalesassistIO');
        $app->set(ConfigurationEnum::PASSWORD->value, '700Credi');
        $app->set(ConfigurationEnum::CLIENT_ID->value, 'TEST_700_CREDIT_CLIENT_ID');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, 'TEST_700_CREDIT_CLIENT_SECRET');

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $creditApplication = new CreditApplicant(
            $people->getName(),
            fake()->streetAddress,
            fake()->city,
            fake()->state,
            fake()->postcode,
            fake()->ssn
        );

        $creditApplicationMessage = [
            'visitor_id' => fake()->uuid(),
            'contact_uuid' => fake()->uuid(),
            'user_uuid' => fake()->uuid(),
            'hashtagVisited' => 'credit-app',
            'engagement_status' => 'submitted',
            'status' => 'submitted',
            'actionLink' => fake()->url(),
            'link' => fake()->url(),
            'link_preview' => fake()->url(),
            'data' => [
                'form' => [
                    'personal' => [
                        'first_name' => fake()->firstName(),
                        'middle_name' => fake()->optional()->firstName(),
                        'last_name' => fake()->lastName(),
                        'dob' => fake()->date('d-F-Y'),
                        'ssn' => fake()->numerify('#########'),
                        'marital_status' => fake()->randomElement(['Single', 'Married', 'Divorced']),
                        'mobile_number' => fake()->numerify('##########'),
                        'home_number' => fake()->optional()->numerify('##########'),
                        'email' => fake()->safeEmail(),
                    ],
                    'housing' => [
                        'address' => fake()->streetAddress(),
                        'address_line2' => fake()->optional()->secondaryAddress(),
                        'state' => [
                            'name' => 'California',
                            'id' => '5321',
                            'code' => 'CA',
                        ],
                        'city' => fake()->city(),
                        'zip_code' => fake()->postcode(),
                        'residence_type' => fake()->randomElement(['Own', 'Rent']),
                        'rent' => fake()->randomFloat(2, 500, 3000),
                        'time_at_address' => (string)fake()->randomFloat(1, 1, 20),
                        'previous_address' => fake()->optional()->streetAddress(),
                        'previous_address_line2' => fake()->optional()->secondaryAddress(),
                        'previous_state' => fake()->optional()->stateAbbr(),
                        'previous_city' => fake()->optional()->city(),
                        'previous_zip_code' => fake()->optional()->postcode(),
                        'previous_time_at_address' => fake()->optional()->randomFloat(1, 1, 10),
                    ],
                    'financial' => [
                        'employment_status' => fake()->randomElement(['Employed', 'Self Employed', 'Unemployed']),
                        'current_employment_title' => fake()->jobTitle(),
                        'current_employer' => fake()->company(),
                        'current_employer_phone' => fake()->numerify('##########'),
                        'years_at_current_employment' => (string)fake()->randomFloat(1, 1, 15),
                        'previous_employment_title' => fake()->optional()->jobTitle(),
                        'previous_employer' => fake()->optional()->company(),
                        'previous_employer_phone' => fake()->optional()->numerify('##########'),
                        'years_at_previous_employment' => fake()->optional()->randomFloat(1, 1, 10),
                        'gross_income' => (string)fake()->randomFloat(2, 30000, 150000),
                        'other_income' => fake()->optional()->randomFloat(2, 1000, 5000),
                        'other_income_source' => fake()->optional()->words(2, true),
                    ],
                    'signature' => [
                        'id' => fake()->numberBetween(1000000, 9999999),
                        'companies_id' => fake()->numberBetween(100, 999),
                        'apps_id' => fake()->numberBetween(1, 10),
                        'users_id' => fake()->numberBetween(1000, 9999),
                        'name' => 'credit-app-signature.png',
                        'path' => 'files/app/storage/cache/data/' . fake()->lexify('??????') . '.png',
                        'url' => fake()->imageUrl(),
                        'size' => (string)fake()->numberBetween(5000, 20000),
                        'file_type' => 'png',
                        'attributes' => [
                            'height' => (string)fake()->numberBetween(150, 200),
                            'orientation' => 'landscape',
                            'unique_name' => 'files/app/storage/cache/data/' . fake()->lexify('??????') . '.png',
                            'verb' => 'credit-app',
                            'visitor_id' => fake()->uuid(),
                            'width' => (string)fake()->numberBetween(600, 800),
                        ],
                        'created_at' => fake()->dateTimeThisYear()->format('Y-m-d H:i:s'),
                    ],
                ],
            ],
            'source' => 'web',
            'text' => 'Credit App',
            'lang' => '',
            'headers' => [
                'User-Agent' => fake()->userAgent(),
            ],
            'verb' => 'credit-app',
        ];

        $creditApplicationEngagementData = EngagementMessage::from($creditApplicationMessage);

        $dataPushMappingService = new DataPushMultiMappingService($app);
        
        $xmlRequest = $dataPushMappingService->pushData($creditApplicationEngagementData);

        $this->assertInstanceOf(SimpleXMLElement::class, $xmlRequest);
    }
}
