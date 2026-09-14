<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class ImportVendorApproversCommandTest extends TestCase
{
    use DatabaseTransactions;

    // Organization/OrganizationApprover live on 'crm' and approvers can be linked to new Users on
    // 'mysql' — without declaring both, DatabaseTransactions only rolls back 'mysql'. See tests/CLAUDE.md.
    protected array $connectionsToTransact = ['mysql', 'crm'];

    private const string COMMAND = 'scribe:import-vendor-approvers';

    private Apps $kanvasApp;
    private Companies $company;
    private ?string $tempPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    protected function tearDown(): void
    {
        if ($this->tempPath !== null && file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_links_an_approver_on_an_already_matching_organization(): void
    {
        $vendor = $this->seedOrganization('Import Command Match Test Corp');

        $this->artisan(self::COMMAND, [
            'apps_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
            'file' => $this->writeSheet([
                ['Import Command Match Test Corp', 'match-approver@example.test'],
            ]),
        ])->assertSuccessful();

        $vendor->refresh();
        $this->assertSame('match-approver@example.test', $vendor->get(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value));
        $this->assertSame(['match-approver@example.test'], OrganizationApprover::emailsFor($vendor));
    }

    public function test_creates_the_organization_when_nothing_matches_so_the_whole_sheet_ends_up_linked(): void
    {
        $vendorName = 'Brand New Import Vendor ' . uniqid();

        $this->artisan(self::COMMAND, [
            'apps_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
            'file' => $this->writeSheet([
                [$vendorName, 'new-vendor-approver@example.test'],
            ]),
        ])->assertSuccessful();

        $organization = Organization::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('name', $vendorName)
            ->first();

        $this->assertNotNull($organization, 'A vendor with no existing match should get an Organization created.');
        $this->assertSame(['new-vendor-approver@example.test'], OrganizationApprover::emailsFor($organization));
    }

    public function test_skips_an_ambiguous_vendor_without_creating_or_linking_anything(): void
    {
        $tok = uniqid();
        $this->seedOrganization("Ambiguous Import Corp {$tok} North");
        $this->seedOrganization("Ambiguous Import Corp {$tok} South");

        $this->artisan(self::COMMAND, [
            'apps_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
            'file' => $this->writeSheet([
                ["Ambiguous Import Corp {$tok}", 'ambiguous-approver@example.test'],
            ]),
        ])->assertSuccessful();

        $this->assertSame(
            0,
            Organization::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('name', "Ambiguous Import Corp {$tok}")
                ->count(),
            'An ambiguous vendor must not get a new Organization created — it needs manual resolution.',
        );
    }

    public function test_skips_a_row_with_no_approver_email(): void
    {
        $vendorName = 'No Email Import Vendor ' . uniqid();

        $this->artisan(self::COMMAND, [
            'apps_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
            'file' => $this->writeSheet([
                [$vendorName, ''],
            ]),
        ])->assertSuccessful();

        $this->assertSame(
            0,
            Organization::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('name', $vendorName)
                ->count(),
        );
    }

    private function seedOrganization(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    /**
     * @param list<array{0: string, 1: string}> $rows
     */
    private function writeSheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Vendor Name');
        $sheet->setCellValue('B1', 'Approver Email');

        foreach ($rows as $index => [$vendorName, $approverEmail]) {
            $rowNumber = $index + 2;
            $sheet->setCellValue("A{$rowNumber}", $vendorName);
            $sheet->setCellValue("B{$rowNumber}", $approverEmail);
        }

        $this->tempPath = sys_get_temp_dir() . '/vendor_approvers_test_' . uniqid() . '.xlsx';
        new Xlsx($spreadsheet)->save($this->tempPath);

        return $this->tempPath;
    }
}
