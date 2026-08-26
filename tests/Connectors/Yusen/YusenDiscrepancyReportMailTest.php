<?php

declare(strict_types=1);

namespace Tests\Connectors\Yusen;

use Bouncer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Yusen\Actions\SendYusenDiscrepancyReportAction;
use Kanvas\Connectors\Yusen\Notifications\YusenDiscrepancyReportNotification;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Recipients come from the Managers role, not a configured list of ids, so these drive the role
 * assignment rather than a custom field.
 */
class YusenDiscrepancyReportMailTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $kanvasApp;
    private Users $user;
    private Companies $kanvasCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;
        $this->kanvasCompany = Companies::getById($user->getCurrentCompany()->getId());

        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp));
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp));
        Bouncer::retract(RolesEnums::MANAGER->value)->from($this->user);

        parent::tearDown();
    }

    public function testStaysSilentWhenTheCompanyHasNoManagers(): void
    {
        Notification::fake();

        $this->assertSame([], $this->send($this->report(3)));

        Notification::assertNothingSent();
    }

    public function testNotifiesTheCompanyManagers(): void
    {
        Notification::fake();
        $this->makeManager();

        $notified = $this->send($this->report(3));

        $this->assertSame([$this->user->getId()], $notified);
        Notification::assertSentTo($this->user, YusenDiscrepancyReportNotification::class);
    }

    public function testSubjectSaysWhenThereIsNothingWrong(): void
    {
        Notification::fake();
        $this->makeManager();

        $this->send($this->report(0));

        Notification::assertSentTo(
            $this->user,
            YusenDiscrepancyReportNotification::class,
            fn (YusenDiscrepancyReportNotification $notification): bool => str_contains(
                (string) ($notification->getData()['subject'] ?? ''),
                'no discrepancies'
            )
        );
    }

    public function testLeadsWithTheBiggestGapsAndCapsTheList(): void
    {
        Notification::fake();
        $this->makeManager();

        $rows = [];

        for ($i = 1; $i <= 40; $i++) {
            $rows[] = [
                'item' => 'ITEM' . $i,
                'source' => 'kanvas',
                'type' => 'QUANTITY_MISMATCH',
                'yusen_quantity' => (float) $i,
                'compared_quantity' => 0.0,
                'difference' => (float) $i,
            ];
        }

        $this->send([
            'total_discrepancies' => 40,
            'by_source' => ['kanvas' => 40],
            'rows' => $rows,
        ]);

        Notification::assertSentTo(
            $this->user,
            YusenDiscrepancyReportNotification::class,
            function (YusenDiscrepancyReportNotification $notification): bool {
                $data = $notification->getData();

                return count($data['items']) === 25
                    && $data['total_items_in_report'] === 40
                    && $data['items'][0]['item'] === 'ITEM40'
                    && $data['items'][24]['item'] === 'ITEM16';
            }
        );
    }

    public function testCollapsesTheSameItemFromSeveralSourcesIntoOneRow(): void
    {
        Notification::fake();
        $this->makeManager();

        // The raw report is per-source, so an item both systems disagree about appears twice.
        // The mail must show it once with a column per source, not two identical-looking lines.
        $this->send([
            'total_discrepancies' => 2,
            'by_source' => ['kanvas' => 1, 'netsuite' => 1],
            'rows' => [
                [
                    'item' => '9990000000045',
                    'description' => 'Marker Cool Gray No.3',
                    'warehouse_code' => 'WHSE1',
                    'source' => 'kanvas',
                    'type' => 'QUANTITY_MISMATCH',
                    'yusen_quantity' => 1000.0,
                    'compared_quantity' => 0.0,
                    'difference' => 1000.0,
                ],
                [
                    'item' => '9990000000045',
                    'description' => 'Marker Cool Gray No.3',
                    'warehouse_code' => 'WHSE1',
                    'source' => 'netsuite',
                    'type' => 'QUANTITY_MISMATCH',
                    'yusen_quantity' => 1000.0,
                    'compared_quantity' => 25.0,
                    'difference' => 975.0,
                ],
            ],
        ]);

        Notification::assertSentTo(
            $this->user,
            YusenDiscrepancyReportNotification::class,
            function (YusenDiscrepancyReportNotification $notification): bool {
                $data = $notification->getData();
                $item = $data['items'][0];

                return count($data['items']) === 1
                    && $data['sources'] === ['kanvas', 'netsuite']
                    && $item['yusen_quantity'] === 1000.0
                    && $item['by_source']['kanvas']['difference'] === 1000.0
                    && $item['by_source']['netsuite']['quantity'] === 25.0;
            }
        );
    }

    public function testRendersTheEmailBody(): void
    {
        $this->makeManager();

        $notification = new YusenDiscrepancyReportNotification(
            $this->kanvasCompany,
            [
                'subject' => 'Yusen inventory: 1 discrepancies',
                'company_name' => $this->kanvasCompany->name,
                'file_name' => 'item-balance.xml',
                'generated_at' => '2026-08-21T07:00:03+00:00',
                'total_items' => 96,
                'total_quantity' => 3036.0,
                'total_discrepancies' => 1,
                'multi_record_items' => 1,
                'by_type' => ['QUANTITY_MISMATCH' => 1],
                'source_errors' => [],
                'sources' => ['kanvas', 'netsuite'],
                'total_items_in_report' => 1,
                'items' => [[
                    'item' => '9990000000045',
                    'description' => 'Marker Cool Gray No.3',
                    'warehouse_code' => 'WHSE1',
                    'yusen_quantity' => 1000.0,
                    'by_source' => [
                        'kanvas' => ['type' => 'QUANTITY_MISMATCH', 'quantity' => 25.0, 'difference' => 975.0],
                    ],
                    'worst' => 975.0,
                ]],
            ]
        );

        $html = $notification->getEmailContent();

        $this->assertStringContainsString('Marker Cool Gray No.3', $html);
        $this->assertStringContainsString('9990000000045', $html);
        $this->assertStringContainsString('1,000', $html);
        $this->assertStringContainsString('+975', $html);
        // NetSuite has no row for this item, so it agreed within tolerance.
        $this->assertStringContainsString('agrees', $html);
        $this->assertStringContainsString('lot record', $html);
    }

    private function makeManager(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp));
        Bouncer::assign(RolesEnums::MANAGER->value)->to($this->user);
    }

    /**
     * @return array<int, int>
     */
    private function send(array $report): array
    {
        return new SendYusenDiscrepancyReportAction(
            $this->kanvasApp,
            $this->kanvasCompany,
            $report,
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function report(int $discrepancies): array
    {
        return [
            'file_name' => 'item-balance.xml',
            'total_items' => 4,
            'total_quantity' => 3036.0,
            'total_discrepancies' => $discrepancies,
            'by_source' => ['kanvas' => $discrepancies],
            'by_type' => ['QUANTITY_MISMATCH' => $discrepancies],
            'rows' => [],
        ];
    }
}
