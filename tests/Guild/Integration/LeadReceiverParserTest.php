<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Kanvas\Guild\Leads\Actions\ConvertJsonTemplateToLeadStructureAction;
use Tests\TestCase;

final class LeadReceiverParserTest extends TestCase
{
    public function testSimpleLeadParser(): void
    {
        $leadTemplate = '
        {
            "Member": {
                "name": "member",
                "type": "customField"
            },
            "firstname": {
                "name": "firstname",
                "type": "string"
            },
            "lastname": {
                "name": "lastname",
                "type": "string"
            },
            "phone": {
                "name": "phone",
                "type": "string"
            },
            "email": {
                "name": "email",
                "type": "string"
            },
            "zip": {
                "name": "zip",
                "type": "customField"
            }
        }';

        $name = fake()->name;
        $phone = fake()->phoneNumber;
        $email = fake()->email;
        $lastname = fake()->lastName;
        $url = fake()->url;

        $leadReceived = json_encode([
            'firstname' => $name,
            'lastname' => $lastname,
            'phone' => $phone,
            'email' => $email,
            'Member' => 'lpr2230',
            'URL' => $url,
            'credit_score' => 'Poor',
            'SMS_Opt_Out' => '1',
        ]);

        $parseTemplate = new ConvertJsonTemplateToLeadStructureAction(
            json_decode($leadTemplate, true),
            json_decode($leadReceived, true)
        );

        $leadStructure = $parseTemplate->execute();

        $this->assertIsArray($leadStructure);
        $this->assertArrayHasKey('modelInfo', $leadStructure);
        $this->assertArrayHasKey('custom_fields', $leadStructure['modelInfo']);
        $this->assertArrayHasKey('people', $leadStructure['modelInfo']);
        $this->assertArrayHasKey('firstname', $leadStructure['modelInfo']['people']);
        $this->assertArrayHasKey('lastname', $leadStructure['modelInfo']['people']);
        $this->assertArrayHasKey('contacts', $leadStructure['modelInfo']['people']);
        $this->assertEquals($name, $leadStructure['modelInfo']['people']['firstname']);
        $this->assertEquals($lastname, $leadStructure['modelInfo']['people']['lastname']);
        $this->assertEquals($phone, $leadStructure['modelInfo']['people']['contacts'][0]['value']);
        $this->assertEquals($email, $leadStructure['modelInfo']['people']['contacts'][1]['value']);
        $this->assertEquals('lpr2230', $leadStructure['modelInfo']['custom_fields']['member']);
    }

    public function testComplexLearParser(): void
    {
        $leadTemplate = '
        {
            "request_header.request_id": {
                "name": "CPL_ID",
                "type": "customField"
            },
            "business.business_name": {
                "name": "company",
                "type": "customField"
            },
            "business.self_reported_cash_flow.annual_revenue": {
                "name": "annual_revenue",
                "type": "customField"
            },
            "business.business_inception": {
                "name": "business_founded",
                "type": "customField"
            },
            "business.use_of_proceeds": {
                "name": "industry",
                "type": "customField"
            },
            "business.address.zip": {
                "name": "zip",
                "type": "customField"
            },
            "application_data.loan_amount": {
                "name": "amount_requested",
                "type": "customField"
            },
            "application_data.filter_id": {
                "name": "nerdwallet_id",
                "type": "customField"
            },
            "application_data.credit_score": {
                "name": "credit_score",
                "type": "customField"
            },
            "application_data.entity_type": {
                "name": "industry",
                "type": "customField"
            },
            "application_data.campaign_id": {
                "name": "SubID",
                "type": "customField"
            },
            "owners.0.email": {
                "name": "email",
                "type": "string"
            },
            "owners.0.phone_number": {
                "name": "phone",
                "type": "string"
            },
            "owners.0.first_name": {
                "name": "firstname",
                "type": "string"
            },
            "owners.0.last_name": {
                "name": "lastname",
                "type": "string"
            },
            "owners.0.home_address.state": {
                "name": "state",
                "type": "customField"
            },
            "owners.0.home_address.address_1": {
                "name": "address",
                "type": "customField"
            },
            "owners.0.home_address.city": {
                "name": "city",
                "type": "customField"
            },
            "owners": {
                "name": "person",
                "json": {
                    "name": "owners.0.full_name",
                    "contacts": [
                        {
                            "contacts_types_id": 1,
                            "value": "owners.0.email"
                        },
                        {
                            "contacts_types_id": 2,
                            "value": "owners.0.phone_number"
                        }
                    ]
                },
                "type": "function",
                "function": "setPeople"
            }
        }';

        $leadReceived = json_encode([
            "request_header" => [
                "request_id" => fake()->uuid,
                "request_date" => fake()->iso8601,
                "is_test_lead" => false
            ],
            "business" => [
                "business_name" => fake()->company,
                "self_reported_cash_flow" => [
                    "annual_revenue" => fake()->numberBetween(100000, 500000)
                ],
                "address" => [
                    "zip" => fake()->postcode
                ],
                "naics" => fake()->randomNumber(6, true),
                "business_inception" => fake()->date('m-d-Y'),
                "use_of_proceeds" => "Purchasing equipment"
            ],
            "owners" => [
                [
                    "full_name" => fake()->name,
                    "first_name" => fake()->firstName,
                    "last_name" => fake()->lastName,
                    "email" => fake()->email,
                    "home_address" => [
                        "address_1" => fake()->streetAddress,
                        "address_2" => null,
                        "city" => fake()->city,
                        "state" => fake()->state,
                        "zip" => fake()->postcode
                    ],
                    "phone_number" => fake()->phoneNumber
                ]
            ],
            "application_data" => [
                "loan_amount" => fake()->numberBetween(50000, 150000),
                "credit_score" => fake()->numberBetween(1, 850),
                "entity_type" => "LLC",
                "filter_id" => fake()->uuid,
                "campaign_id" => fake()->uuid
            ]
        ]);

        $parseTemplate = new ConvertJsonTemplateToLeadStructureAction(
            json_decode($leadTemplate, true),
            json_decode($leadReceived, true)
        );

        $leadStructure = $parseTemplate->execute();
     
        $this->assertIsArray($leadStructure);
        $this->assertArrayHasKey('modelInfo', $leadStructure);
        $this->assertArrayHasKey('custom_fields', $leadStructure['modelInfo']);
        $this->assertArrayHasKey('people', $leadStructure['modelInfo']);
        $this->assertArrayHasKey('company', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('annual_revenue', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('business_founded', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('industry', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('zip', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('amount_requested', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('nerdwallet_id', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('credit_score', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('industry', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('SubID', $leadStructure['modelInfo']['custom_fields']);
        $this->assertArrayHasKey('firstname', $leadStructure['modelInfo']['people']);
        $this->assertArrayHasKey('lastname', $leadStructure['modelInfo']['people']);
        $this->assertArrayHasKey('contacts', $leadStructure['modelInfo']['people']);
    }
}
