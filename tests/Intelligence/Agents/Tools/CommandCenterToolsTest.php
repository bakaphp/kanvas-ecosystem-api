<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ExportBouncesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ExportChangesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetCleanupReportTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetCompanyBreakdownTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListBouncesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListChangesTool;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CommandCenterToolsTest extends TestCase
{
    use DatabaseTransactions;

    private const string FROM = '2031-03-01';
    private const string TO = '2031-03-31';

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    protected function tearDown(): void
    {
        DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('event_type', 'people.enriched')
            ->whereBetween('occurred_at', [self::FROM . ' 00:00:00', self::TO . ' 23:59:59'])
            ->delete();

        parent::tearDown();
    }

    public function test_get_cleanup_report_returns_headline_metrics_without_by_company(): void
    {
        $result = new GetCleanupReportTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke();

        $this->assertArrayHasKey('totalPeople', $result);
        $this->assertArrayHasKey('verifiedPeople', $result);
        $this->assertArrayHasKey('bouncingPeople', $result);
        $this->assertArrayNotHasKey('byCompany', $result);
        $this->assertSame($this->currentCompany->name, $result['company']);
    }

    public function test_get_company_breakdown_returns_by_company_and_by_tenant(): void
    {
        $result = new GetCompanyBreakdownTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke(limit: 5);

        $this->assertArrayHasKey('byCompany', $result);
        $this->assertIsArray($result['byCompany']);
        $this->assertCount(1, $result['byTenant']);
        $this->assertSame($this->currentCompany->name, $result['byTenant'][0]['crm']);
    }

    public function test_list_changes_returns_a_sampled_shape(): void
    {
        $person = $this->makePerson('Listchangesuniq', 'Personcc');
        $this->seedEnriched((int) $person->getId(), 'Banco Popular', [
            'email_changed' => ['from' => 'old@x.com', 'to' => 'new@y.com'],
        ]);

        $result = new ListChangesTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke();

        $mine = collect($result['changes'])->firstWhere('person', 'Listchangesuniq Personcc');
        $this->assertNotNull($mine);
        $this->assertSame('email', $mine['type']);
        $this->assertSame('new@y.com', $mine['to']);
    }

    public function test_list_bounces_returns_bad_emails(): void
    {
        $person = $this->makePerson('Listbounceuniq', '');
        $email = 'bad-' . uniqid() . '@x.test';
        $this->addBadEmail($person, $email, ContactValidationStatusEnum::HARD_BOUNCE);

        $result = new ListBouncesTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke();

        $mine = collect($result['bounces'])->firstWhere('person', 'Listbounceuniq');
        $this->assertNotNull($mine);
        $this->assertSame($email, $mine['email']);
        $this->assertSame('hard_bounce', $mine['status']);
    }

    public function test_export_bounces_returns_a_file_reference(): void
    {
        $this->fakeCsvUpload();

        $person = $this->makePerson('Exportbounceuniq', '');
        $email = 'exp-' . uniqid() . '@x.test';
        $this->addBadEmail($person, $email, ContactValidationStatusEnum::HARD_BOUNCE);

        $result = new ExportBouncesTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke();

        $this->assertStringStartsWith('https://fake.test/bounces', $result['file_url']);
        $this->assertGreaterThanOrEqual(1, $result['row_count']);
        $this->assertSame([$this->currentCompany->name], $result['companies']);
    }

    public function test_export_changes_returns_a_file_reference(): void
    {
        $this->fakeCsvUpload();

        $person = $this->makePerson('Exportchangeuniq', 'Personcc');
        $this->seedEnriched((int) $person->getId(), 'Grupo Ramos', [
            'title' => ['from' => 'A', 'to' => 'B'],
        ]);

        $result = new ExportChangesTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke(from: self::FROM, to: self::TO);

        $this->assertStringStartsWith('https://fake.test/changes', $result['file_url']);
        $this->assertSame(1, $result['row_count']);
    }

    private function fakeCsvUpload(): void
    {
        $this->instance(CsvExportService::class, new class () extends CsvExportService {
            protected function store(Apps $app, Companies $company, Users $user, string $filename, string $content): string
            {
                return 'https://fake.test/' . $filename;
            }
        });
    }

    private function makePerson(string $first, string $last): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create(['firstname' => $first, 'lastname' => $last]);
    }

    private function addBadEmail(People $person, string $email, ContactValidationStatusEnum $status): void
    {
        $person->addEmail($email, 0, 0);
        $person->contacts()
            ->where('value', $email)
            ->firstOrFail()
            ->update(['validation_status' => $status->value]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function seedEnriched(int $entityId, string $companyName, array $changes): void
    {
        new AppendEventAction(
            new EventData(
                app: $this->currentApp,
                company: $this->currentCompany,
                sourceDomain: 'Guild',
                eventType: 'people.enriched',
                status: EventStatusEnum::INFO,
                sourceEntityType: People::class,
                sourceEntityId: $entityId,
                actorType: 'System',
                payload: [
                    'source' => 'apollo',
                    'company' => $companyName,
                    'changed_fields' => array_keys($changes),
                    'changes' => $changes,
                ],
                occurredAt: Carbon::parse(self::FROM)->addDays(5),
            ),
        )->execute();
    }
}
