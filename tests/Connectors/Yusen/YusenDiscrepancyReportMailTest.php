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
use Kanvas\Notifications\Templates\Blank;
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
        Notification::assertSentTo($this->user, Blank::class);
    }

    public function testSubjectSaysWhenThereIsNothingWrong(): void
    {
        Notification::fake();
        $this->makeManager();

        $this->send($this->report(0));

        Notification::assertSentTo(
            $this->user,
            Blank::class,
            fn (Blank $notification): bool => str_contains(
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

        $this->send(['total_discrepancies' => 40, 'rows' => $rows]);

        Notification::assertSentTo(
            $this->user,
            Blank::class,
            function (Blank $notification): bool {
                $mailed = $notification->getData()['rows'];

                return count($mailed) === 25
                    && $mailed[0]['item'] === 'ITEM40'
                    && $mailed[24]['item'] === 'ITEM16';
            }
        );
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
