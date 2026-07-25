<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Connectors\Salesforce\Jobs\SalesforceBackfillImportJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use stdClass;
use Tests\TestCase;

final class SalesforceBackfillImportJobTest extends TestCase
{
    use DatabaseTransactions;

    public function testImportsAllAccountRecords(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $records = [
            ['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp'],
            ['Id' => '001xx0000000002AAA', 'Name' => 'Globex Corp'],
        ];

        new SalesforceBackfillImportJob($app, $company, 'Account', $records)->handle();

        // Matched by the Salesforce id custom field, not by `name` — the full Salesforce suite
        // runs many test classes against the same cached company/user in one process, and other
        // files reuse "Acme Corp" as a fixture name too; `where('name', ...)->first()` can return
        // a different, unrelated row left over from an earlier test class in the same run.
        $acme = Organization::getByCustomFieldTransactionSafe(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xx0000000001AAA', $company);
        $globex = Organization::getByCustomFieldTransactionSafe(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xx0000000002AAA', $company);

        $this->assertNotNull($acme);
        $this->assertNotNull($globex);
        $this->assertSame('Acme Corp', $acme->name);
        $this->assertSame('Globex Corp', $globex->name);
    }

    public function testImportsAllContactRecordsAndLinksToAccount(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        new SalesforceBackfillImportJob($app, $company, 'Account', [
            ['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp'],
        ])->handle();

        $records = [
            ['Id' => '003xx0000000001AAA', 'FirstName' => 'John', 'LastName' => 'Appleseed', 'AccountId' => '001xx0000000001AAA'],
            ['Id' => '003xx0000000002AAA', 'FirstName' => 'Jane', 'LastName' => 'Doe'],
        ];

        new SalesforceBackfillImportJob($app, $company, 'Contact', $records)->handle();

        // Matched by the Salesforce id custom field, not by `firstname`/`name` — see the identical
        // comment in testImportsAllAccountRecords for why matching by a human-readable fixture
        // value risks picking up an unrelated row from another test class in the same run.
        $john = People::getByCustomFieldTransactionSafe(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, '003xx0000000001AAA', $company);
        $jane = People::getByCustomFieldTransactionSafe(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, '003xx0000000002AAA', $company);

        $this->assertNotNull($john);
        $this->assertNotNull($jane);
        $this->assertSame('John', $john->firstname);
        $this->assertSame('Jane', $jane->firstname);

        $organization = Organization::getByCustomFieldTransactionSafe(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xx0000000001AAA', $company);
        $this->assertTrue($organization->peoples()->where('peoples.id', $john->getId())->exists());
    }

    public function testOneBadRecordDoesNotStopTheRestFromProcessing(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $records = [
            // Non-stringable Id blows up the `(string) $record['Id']` cast inside the per-record
            // try/catch — this must fail only this record, not the whole batch.
            ['Id' => new stdClass(), 'Name' => 'Broken Record'],
            ['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp'],
        ];

        new SalesforceBackfillImportJob($app, $company, 'Account', $records)->handle();

        $this->assertNotNull(
            Organization::getByCustomFieldTransactionSafe(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, '001xx0000000001AAA', $company)
        );
        $this->assertFalse(
            Organization::query()->fromApp($app)->fromCompany($company)->where('name', 'Broken Record')->exists()
        );
    }

    public function testUnsupportedObjectTypeIsANoOp(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        new SalesforceBackfillImportJob($app, $company, 'Opportunity', [
            ['Id' => '006xx0000000001AAA', 'Name' => 'Big Deal'],
        ])->handle();

        $this->assertFalse(
            Organization::query()->fromApp($app)->fromCompany($company)->where('name', 'Big Deal')->exists()
        );
    }
}
