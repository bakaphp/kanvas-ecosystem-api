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
        $this->assertArrayHasKey('custom_fields', $leadStructure);
        $this->assertArrayHasKey('people', $leadStructure);
        $this->assertArrayHasKey('firstname', $leadStructure['people']);
        $this->assertArrayHasKey('lastname', $leadStructure['people']);
        $this->assertArrayHasKey('contacts', $leadStructure['people']);
        $this->assertEquals($name, $leadStructure['people']['firstname']);
        $this->assertEquals($lastname, $leadStructure['people']['lastname']);
        $this->assertEquals($phone, $leadStructure['people']['contacts'][0]['value']);
        $this->assertEquals($email, $leadStructure['people']['contacts'][1]['value']);
        $this->assertEquals('lpr2230', $leadStructure['custom_fields']['member']);
    }

    public function testExtraLeadParser(): void
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
            'CRE_Estimated_Property_Value' => '1',
            'CRE_Estimated_1st_Mortgage' => '1',
            'CRE_Loan_Purpose' => '1',
            'CRE_Amount_of_Loan_Request' => '1',
            'amount_requested' => '1',
            'business_name' => '1',
            'compay' => '1',
        ]);

        $parseTemplate = new ConvertJsonTemplateToLeadStructureAction(
            json_decode($leadTemplate, true),
            json_decode($leadReceived, true)
        );

        $leadStructure = $parseTemplate->execute();

        $this->assertIsArray($leadStructure);
        $this->assertArrayHasKey('custom_fields', $leadStructure);
        $this->assertArrayHasKey('people', $leadStructure);
        $this->assertArrayHasKey('firstname', $leadStructure['people']);
        $this->assertArrayHasKey('lastname', $leadStructure['people']);
        $this->assertArrayHasKey('contacts', $leadStructure['people']);
        $this->assertEquals($name, $leadStructure['people']['firstname']);
        $this->assertEquals($lastname, $leadStructure['people']['lastname']);
        $this->assertEquals($phone, $leadStructure['people']['contacts'][0]['value']);
        $this->assertEquals($email, $leadStructure['people']['contacts'][1]['value']);
        $this->assertEquals('lpr2230', $leadStructure['custom_fields']['member']);
        $this->assertEquals('1', $leadStructure['custom_fields']['CRE_Estimated_1st_Mortgage']);
    }

    public function testExtraLeaSpacingParser(): void
    {
        $leadTemplate = '
       {
            "First Name": {
                "name": "firstname",
                "type": "string"
            },
            "Last Name": {
                "name": "lastname",
                "type": "string"
            },
            "Phone": {
                "name": "phone",
                "type": "string"
            },
            "Email": {
                "name": "email",
                "type": "string"
            },
            "Company": {
                "name": "Company",
                "type": "customField"
            },
            "City": {
                "name": "city",
                "type": "customField"
            },
            "State": {
                "name": "state",
                "type": "customField"
            },
            "Zip Code": {
                "name": "zip",
                "type": "customField"
            },
            "Type of Incorporation": {
                "name": "type_of_incorporation",
                "type": "customField"
            },
            "Industry": {
                "name": "industry",
                "type": "customField"
            },
            "Business Founded": {
                "name": "business_founded",
                "type": "customField"
            },
            "SubID2": {
                "name": "SubID2",
                "type": "regex",
                "pattern": "/^[^;]*;([^;]+)/"
            },
            "SubID": {
                "name": "SubID_ID",
                "type": "customField",
                "pattern" : "/^[^;]*;([^;]+)/",
                "note": "For now this will also create the parsed version SUB_ID and the main one SubID, maybe we should fix this in the future?"
            },
            "Test": {
                "name": "Another1",
                "type": "customField",
                "pattern" : "/^[^;]*;([^;]+)/"
            },
            "Credit Score": {
                "name": "Credit_Score",
                "type": "customField"
            },
            "Amount Requested": {
                "name": "amount_requested",
                "type": "customField"
            },
            "Annual Revenue": {
                "name": "annual_revenue",
                "type": "decimal"
            }
        }';

        $name = fake()->name;
        $phone = fake()->phoneNumber;
        $email = fake()->email;
        $lastname = fake()->lastName;
        $url = fake()->url;

        $leadReceived = json_encode([
            'First Name' => $name,
            'Last Name' => $lastname,
            'Phone' => $phone,
            'Email' => $email,
            'Company' => 'TEST, LLC',
            'City' => 'aa BB',
            'State' => 'PA',
            'Zip Code' => '19053',
            'Type of Incorporation' => 'soleProprietorship',
            'Industry' => 'real_estate',
            'Business Founded' => '2004-05-01T00:00:00',
            'SubID' => '272da453-ed2c-4fa7-9ec0-c3efc6f55c87;cf3e6255ba55da60765e9d108',
            'SubID2' => '272da453-ed2c-4fa7-9ec0-c3efc6f55c87;cf3e6255ba55da60765e9d108',
            'Test' => '272da453-ed2c-4fa7-9ec0-c3efc6f55c87;cf3e6255ba55da60765e9d108',
            'Credit Score' => 'Excellent (720+)',
            'Amount Requested' => '1150000',
            'Annual Revenue' => '70000',
        ]);

        $parseTemplate = new ConvertJsonTemplateToLeadStructureAction(
            json_decode($leadTemplate, true),
            json_decode($leadReceived, true)
        );

        $leadStructure = $parseTemplate->execute();

        $this->assertIsArray($leadStructure);
        $this->assertArrayHasKey('custom_fields', $leadStructure);
        $this->assertArrayHasKey('people', $leadStructure);
        $this->assertArrayHasKey('firstname', $leadStructure['people']);
        $this->assertArrayHasKey('lastname', $leadStructure['people']);
        $this->assertArrayHasKey('contacts', $leadStructure['people']);
        $this->assertEquals($name, $leadStructure['people']['firstname']);
        $this->assertEquals($lastname, $leadStructure['people']['lastname']);
        $this->assertEquals($phone, $leadStructure['people']['contacts'][0]['value']);
        $this->assertEquals($email, $leadStructure['people']['contacts'][1]['value']);
        $this->assertEquals('Excellent (720+)', $leadStructure['custom_fields']['Credit_Score']);
        $this->assertEquals('1150000', $leadStructure['custom_fields']['Amount Requested']);
        $this->assertEquals('272da453-ed2c-4fa7-9ec0-c3efc6f55c87;cf3e6255ba55da60765e9d108', $leadStructure['custom_fields']['SubID']);
        $this->assertEquals('cf3e6255ba55da60765e9d108', $leadStructure['custom_fields']['SubID_ID']);
        $this->assertEquals('cf3e6255ba55da60765e9d108', $leadStructure['SubID2']);
    }

    public function testExtraLeaDefaultValueParser(): void
    {
        $leadTemplate = '
       {
            "First Name": {
                "name": "firstname",
                "type": "string"
            },
            "Last Name": {
                "name": "lastname",
                "type": "string"
            },
            "Phone": {
                "name": "phone",
                "type": "string"
            },
            "Member": {
                "name": "member",
                "type": "customField",
                "default": "lpr2230"
            },
            "Email": {
                "name": "email",
                "type": "string"
            },
            "Company": {
                "name": "Company",
                "type": "customField"
            },
            "City": {
                "name": "city",
                "type": "customField"
            },
            "State": {
                "name": "state",
                "type": "customField"
            },
            "Zip Code": {
                "name": "zip",
                "type": "customField"
            },
            "Type of Incorporation": {
                "name": "type_of_incorporation",
                "type": "customField"
            },
            "Industry": {
                "name": "industry",
                "type": "customField"
            },
            "Business Founded": {
                "name": "business_founded",
                "type": "customField"
            },
            "SubID": {
                "name": "sub_id",
                "type": "customField"
            },
            "Credit Score": {
                "name": "Credit_Score",
                "type": "customField"
            },
            "Amount Requested": {
                "name": "amount_requested",
                "type": "customField"
            },
            "Annual Revenue": {
                "name": "annual_revenue",
                "type": "decimal"
            }
        }';

        $name = fake()->name;
        $phone = fake()->phoneNumber;
        $email = fake()->email;
        $lastname = fake()->lastName;
        $url = fake()->url;

        $leadReceived = json_encode([
            'First Name' => $name,
            'Last Name' => $lastname,
            'Phone' => $phone,
            'Email' => $email,
            'Company' => 'TEST, LLC',
            'City' => 'aa BB',
            'State' => 'PA',
            'Zip Code' => '19053',
            'Type of Incorporation' => 'soleProprietorship',
            'Industry' => 'real_estate',
            'Business Founded' => '2004-05-01T00:00:00',
            'SubID' => '272da453-ed2c-4fa7-9ec0-c3efc6f55c87;cf3e6255ba55da60765e9d108',
            'Credit Score' => 'Excellent (720+)',
            'Amount Requested' => '1150000',
            'Annual Revenue' => '70000',
        ]);

        $parseTemplate = new ConvertJsonTemplateToLeadStructureAction(
            json_decode($leadTemplate, true),
            json_decode($leadReceived, true)
        );

        $leadStructure = $parseTemplate->execute();

        $this->assertIsArray($leadStructure);
        $this->assertArrayHasKey('custom_fields', $leadStructure);
        $this->assertArrayHasKey('people', $leadStructure);
        $this->assertArrayHasKey('firstname', $leadStructure['people']);
        $this->assertArrayHasKey('lastname', $leadStructure['people']);
        $this->assertArrayHasKey('contacts', $leadStructure['people']);
        $this->assertEquals($name, $leadStructure['people']['firstname']);
        $this->assertEquals($lastname, $leadStructure['people']['lastname']);
        $this->assertEquals($phone, $leadStructure['people']['contacts'][0]['value']);
        $this->assertEquals($email, $leadStructure['people']['contacts'][1]['value']);
        $this->assertEquals('Excellent (720+)', $leadStructure['custom_fields']['Credit_Score']);
        $this->assertEquals('1150000', $leadStructure['custom_fields']['Amount Requested']);
        $this->assertEquals('lpr2230', $leadStructure['custom_fields']['member']);
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
            "Member": {
                "name": "member",
                "type": "customField",
                "default": "lpr2230"
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
            'request_header' => [
                'request_id' => fake()->uuid,
                'request_date' => fake()->iso8601,
                'is_test_lead' => false,
            ],
            'business' => [
                'business_name' => fake()->company,
                'self_reported_cash_flow' => [
                    'annual_revenue' => fake()->numberBetween(100000, 500000),
                ],
                'address' => [
                    'zip' => fake()->postcode,
                ],
                'naics' => fake()->randomNumber(6, true),
                'business_inception' => fake()->date('m-d-Y'),
                'use_of_proceeds' => 'Purchasing equipment',
            ],
            'owners' => [
                [
                    'full_name' => fake()->name,
                    'first_name' => fake()->firstName,
                    'last_name' => fake()->lastName,
                    'email' => fake()->email,
                    'home_address' => [
                        'address_1' => fake()->streetAddress,
                        'address_2' => null,
                        'city' => fake()->city,
                        'state' => fake()->state,
                        'zip' => fake()->postcode,
                    ],
                    'phone_number' => fake()->phoneNumber,
                ],
            ],
            'application_data' => [
                'loan_amount' => fake()->numberBetween(50000, 150000),
                'credit_score' => fake()->numberBetween(1, 850),
                'entity_type' => 'LLC',
                'filter_id' => fake()->uuid,
                'campaign_id' => fake()->uuid,
            ],
        ]);

        $parseTemplate = new ConvertJsonTemplateToLeadStructureAction(
            json_decode($leadTemplate, true),
            json_decode($leadReceived, true)
        );

        $leadStructure = $parseTemplate->execute();

        $this->assertIsArray($leadStructure);
        $this->assertArrayHasKey('custom_fields', $leadStructure);
        $this->assertArrayHasKey('people', $leadStructure);
        $this->assertArrayHasKey('company', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('annual_revenue', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('business_founded', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('industry', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('zip', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('amount_requested', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('nerdwallet_id', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('credit_score', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('industry', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('SubID', $leadStructure['custom_fields']);
        $this->assertArrayHasKey('firstname', $leadStructure['people']);
        $this->assertArrayHasKey('lastname', $leadStructure['people']);
        $this->assertArrayHasKey('contacts', $leadStructure['people']);
        $this->assertArrayHasKey('member', $leadStructure['custom_fields']);
        $this->assertEquals('lpr2230', $leadStructure['custom_fields']['member']);
    }
}
