<?php

declare(strict_types=1);

namespace Tests\Connectors\Yusen;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Yusen\Actions\SendYusenDiscrepancyReportAction;
use Kanvas\Connectors\Yusen\Enums\ConfigurationEnum;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class YusenDiscrepancyReportMailTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $kanvasApp;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;
    }

    public function testStaysSilentWhenNobodyAskedForTheReport(): void
    {
        Notification::fake();

        $this->user->getCurrentCompany()->set(ConfigurationEnum::REPORT_USERS->value, []);

        $this->assertSame([], $this->send($this->report(3)));

        Notification::assertNothingSent();
    }

    public function testNotifiesEveryConfiguredRecipient(): void
    {
        Notification::fake();

        $this->user->getCurrentCompany()->set(
            ConfigurationEnum::REPORT_USERS->value,
            [$this->user->getId()]
        );

        $notified = $this->send($this->report(3));

        $this->assertSame([$this->user->getId()], $notified);
        Notification::assertSentTo($this->user, Blank::class);
    }

    public function testSkipsARecipientWhoNoLongerExists(): void
    {
        Notification::fake();

        $this->user->getCurrentCompany()->set(
            ConfigurationEnum::REPORT_USERS->value,
            [$this->user->getId(), 99999999]
        );

        // A recipient removed from the company must not stop the rest of the list.
        $this->assertSame([$this->user->getId()], $this->send($this->report(1)));
    }

    public function testSubjectSaysWhenThereIsNothingWrong(): void
    {
        Notification::fake();

        $this->user->getCurrentCompany()->set(
            ConfigurationEnum::REPORT_USERS->value,
            [$this->user->getId()]
        );

        $this->send($this->report(0));

        Notification::assertSentTo(
            $this->user,
            Blank::class,
            function (Blank $notification): bool {
                return str_contains($this->subjectOf($notification), 'no discrepancies');
            }
        );
    }

    public function testLeadsWithTheBiggestGapsAndCapsTheList(): void
    {
        Notification::fake();

        $this->user->getCurrentCompany()->set(
            ConfigurationEnum::REPORT_USERS->value,
            [$this->user->getId()]
        );

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
                $mailed = $this->dataOf($notification)['rows'];

                return count($mailed) === 25
                    && $mailed[0]['item'] === 'ITEM40'
                    && $mailed[24]['item'] === 'ITEM16';
            }
        );
    }

    /**
     * @return array<int, int>
     */
    private function send(array $report): array
    {
        return new SendYusenDiscrepancyReportAction(
            $this->kanvasApp,
            $this->user->getCurrentCompany(),
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

    private function subjectOf(Blank $notification): string
    {
        return (string) ($this->dataOf($notification)['subject'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(Blank $notification): array
    {
        return $notification->getData();
    }
}
