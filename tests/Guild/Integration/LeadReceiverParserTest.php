<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
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

    public function testConcatMappingJoinsPresentFieldsForCustomAndStandardTargets(): void
    {
        $action = new ConvertJsonTemplateToLeadStructureAction(
            [
                'notes' => [
                    'name' => 'agent_notes',
                    'type' => 'concat',
                    'fields' => ['business.years', 'business.empty', 'business.funding'],
                    'separator' => ' | ',
                ],
                'description' => [
                    'name' => 'description',
                    'type' => 'concat',
                    'fields' => ['business.years', 'business.funding'],
                    'separator' => "\n",
                    'target' => 'string',
                ],
            ],
            [
                'business' => [
                    'years' => '10 years',
                    'empty' => '',
                    'funding' => '$50,000',
                ],
            ]
        );

        $result = $action->execute();

        $this->assertSame('10 years | $50,000', $result['custom_fields']['agent_notes']);
        $this->assertSame("10 years\n$50,000", $result['description']);
    }

    public function testTemplateMappingResolvesSingleAndDoubleBracePlaceholders(): void
    {
        $action = new ConvertJsonTemplateToLeadStructureAction(
            [
                'notes' => [
                    'name' => 'agent_notes',
                    'type' => 'template',
                    'template' => '{business.name} | {{ business.region }} | {missing.value}',
                ],
            ],
            ['business' => ['name' => 'Acme', 'region' => 'West']]
        );

        $result = $action->execute();

        $this->assertSame('Acme | West | ', $result['custom_fields']['agent_notes']);
    }

    public function testTemplatePlaceholderMatchesPayloadKeyIgnoringCase(): void
    {
        $result = new ConvertJsonTemplateToLeadStructureAction(
            [
                'Agent Notes' => [
                    'name' => 'agent_notes',
                    'type' => 'template',
                    'template' => 'Best Time to Contact: {Best Time to Contact} | Owner: {OWNER.title}',
                ],
            ],
            [
                'Best Time To Contact' => 'Morning (6 AM-12 PM EST)',
                'owner' => ['Title' => 'CEO'],
            ]
        )->execute();

        $this->assertSame(
            'Best Time to Contact: Morning (6 AM-12 PM EST) | Owner: CEO',
            $result['custom_fields']['agent_notes']
        );
    }

    public function testMappingKeyMatchesPayloadKeyIgnoringCase(): void
    {
        $result = new ConvertJsonTemplateToLeadStructureAction(
            [
                'business name' => [
                    'name' => 'Company',
                    'type' => 'customField',
                ],
                'EMAIL' => [
                    'name' => 'email',
                    'type' => 'string',
                ],
            ],
            [
                'Business Name' => 'Tile18llc',
                'Email' => 'jane@acme.test',
            ]
        )->execute();

        $this->assertSame('Tile18llc', $result['custom_fields']['Company']);
        $this->assertSame(
            [['contacts_types_id' => ContactTypeEnum::EMAIL->value, 'value' => 'jane@acme.test']],
            $result['people']['contacts']
        );
    }

    public function testExactCaseKeyWinsOverCaseInsensitiveSibling(): void
    {
        $result = new ConvertJsonTemplateToLeadStructureAction(
            [
                'notes' => [
                    'name' => 'agent_notes',
                    'type' => 'template',
                    'template' => '{Case}',
                ],
            ],
            [
                'case' => 'lower',
                'Case' => 'exact',
            ]
        )->execute();

        $this->assertSame('exact', $result['custom_fields']['agent_notes']);
    }

    public function testTemplatePlaceholderDescendingIntoScalarIsEmpty(): void
    {
        $result = new ConvertJsonTemplateToLeadStructureAction(
            [
                'notes' => [
                    'name' => 'agent_notes',
                    'type' => 'template',
                    'template' => '[{owner.title}]',
                ],
            ],
            ['owner' => 'CEO']
        )->execute();

        $this->assertSame('[]', $result['custom_fields']['agent_notes']);
    }

    public function testTargetOverridesTheInferredDestination(): void
    {
        $result = new ConvertJsonTemplateToLeadStructureAction(
            [
                'summary' => [
                    'name' => 'summary',
                    'type' => 'template',
                    'template' => '{a}',
                    'target' => 'string',
                ],
                'description' => [
                    'name' => 'description',
                    'type' => 'template',
                    'template' => '{a}',
                    'target' => 'customField',
                ],
            ],
            ['a' => 'value']
        )->execute();

        $this->assertSame('value', $result['summary']);
        $this->assertArrayNotHasKey('summary', $result['custom_fields']);
        $this->assertSame('value', $result['custom_fields']['description']);
        $this->assertArrayNotHasKey('description', $result);
    }

    public function testExistingMappingTypesRemainUnchanged(): void
    {
        $action = new ConvertJsonTemplateToLeadStructureAction(
            [
                'first_name' => ['name' => 'firstname', 'type' => 'string'],
                'raw_custom' => ['name' => 'raw_custom', 'type' => 'customField'],
                'reference' => ['name' => 'reference_id', 'type' => 'regex', 'pattern' => '/^prefix-(.+)$/'],
            ],
            [
                'first_name' => 'Ada',
                'raw_custom' => 'raw value',
                'reference' => 'prefix-12345',
            ]
        );

        $result = $action->execute();

        $this->assertSame('Ada', $result['people']['firstname']);
        $this->assertSame('Ada', $result['firstname']);
        $this->assertSame('raw value', $result['custom_fields']['raw_custom']);
        $this->assertSame('12345', $result['reference_id']);
    }

    public function testReceiverMappingWithMultipleConcatAndTemplateFields(): void
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
            "Email": {
                "name": "email",
                "type": "string"
            },
            "Phone": {
                "name": "phone",
                "type": "string"
            },
            "Business Name": {
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
            "Industry": {
                "name": "industry",
                "type": "customField"
            },
            "Credit Score": {
                "name": "Credit_Score",
                "type": "customField"
            },
            "Member": {
                "name": "member",
                "type": "customField",
                "default": "99999"
            },
            "Lead Source": {
                "name": "Lead_Source",
                "type": "customField",
                "default": "our website"
            },
            "Agent Notes": {
                "name": "agent_notes",
                "type": "template",
                "template": "Best Time to Contact: {Best Time to Contact} | Time Funds Needed: {Time Funds Needed} | Amount: {{ Amount Requested }}"
            },
            "Lead Description": {
                "name": "description",
                "type": "template",
                "template": "{Business Name} ({Industry}) - Credit: {Credit Score}"
            },
            "Lead Title": {
                "name": "title",
                "type": "template",
                "target": "string",
                "template": "{Business Name} - {Amount Requested}"
            },
            "Full Name": {
                "name": "full_name",
                "type": "concat",
                "fields": ["First Name", "Middle Name", "Last Name"]
            },
            "Location": {
                "name": "location",
                "type": "concat",
                "fields": ["City", "State", "Zip Code"],
                "separator": ", "
            },
            "Owner Summary": {
                "name": "owner_summary",
                "type": "template",
                "template": "{owner.title}, owns {owner.ownership}%"
            },
            "Empty Concat": {
                "name": "empty_concat",
                "type": "concat",
                "fields": ["Missing One", "Missing Two"],
                "separator": " | "
            }
        }';

        $leadReceived = [
            'First Name' => 'Jane',
            'Last Name' => 'Doe',
            'Email' => 'jane@acme.test',
            'Phone' => '8095551234',
            'Business Name' => 'Acme, LLC',
            'City' => 'Philadelphia',
            'State' => 'PA',
            'Industry' => 'real_estate',
            'Credit Score' => 'Excellent (720+)',
            'Best Time to Contact' => 'Morning (6 AM-12 PM EST)',
            'Time Funds Needed' => 'Within 7 days',
            'Amount Requested' => '150000',
            'owner' => ['title' => 'CEO', 'ownership' => 60],
        ];

        $leadStructure = new ConvertJsonTemplateToLeadStructureAction(
            json_decode($leadTemplate, true),
            $leadReceived
        )->execute();
        $customFields = $leadStructure['custom_fields'];

        $this->assertSame('Jane', $leadStructure['people']['firstname']);
        $this->assertSame('Doe', $leadStructure['people']['lastname']);
        $this->assertSame(
            [
                ['contacts_types_id' => ContactTypeEnum::EMAIL->value, 'value' => 'jane@acme.test'],
                ['contacts_types_id' => ContactTypeEnum::PHONE->value, 'value' => '8095551234'],
            ],
            $leadStructure['people']['contacts']
        );

        $this->assertSame('Acme, LLC', $customFields['Company']);
        $this->assertSame('Philadelphia', $customFields['city']);
        $this->assertSame('PA', $customFields['state']);
        $this->assertSame('real_estate', $customFields['industry']);
        $this->assertSame('Excellent (720+)', $customFields['Credit_Score']);
        $this->assertSame('99999', $customFields['member']);
        $this->assertSame('our website', $customFields['Lead_Source']);

        $this->assertSame(
            'Best Time to Contact: Morning (6 AM-12 PM EST) | Time Funds Needed: Within 7 days | Amount: 150000',
            $customFields['agent_notes']
        );
        $this->assertSame('Acme, LLC (real_estate) - Credit: Excellent (720+)', $leadStructure['description']);
        $this->assertArrayNotHasKey('description', $customFields);
        $this->assertSame('Acme, LLC - 150000', $leadStructure['title']);
        $this->assertArrayNotHasKey('title', $customFields);
        $this->assertSame('Jane Doe', $customFields['full_name']);
        $this->assertSame('Philadelphia, PA', $customFields['location']);
        $this->assertSame('CEO, owns 60%', $customFields['owner_summary']);
        $this->assertSame('', $customFields['empty_concat']);
    }
}
