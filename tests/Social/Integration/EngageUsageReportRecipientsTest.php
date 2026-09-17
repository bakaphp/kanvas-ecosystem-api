<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Bouncer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Analytics\Actions\SendEngageUsageReportAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Analytics\Enums\AnalyticsBucketEnum;
use Kanvas\Analytics\Notifications\EngageUsageReportNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Drives the auth user and edits its membership through the default `mysql` handle: the recipient
 * query reads on `mysql`, and a row written on `ecosystem` (same database, separate connection) is
 * invisible to that transacted handle and never rolled back.
 */
class EngageUsageReportRecipientsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm'];

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;
        $this->company = Companies::getById($user->getCurrentCompany()->getId());

        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp));
        Bouncer::assign(RolesEnums::MANAGER->value)->to($this->user);

        $this->seedActivity();
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp));
        Bouncer::retract(RolesEnums::MANAGER->value)->from($this->user);

        parent::tearDown();
    }

    public function testSendsToAnActiveManager(): void
    {
        Notification::fake();

        $this->assertSame(1, $this->send());

        Notification::assertSentTo($this->user, EngageUsageReportNotification::class);
    }

    /**
     * @param  array<string, int>  $membership
     */
    #[DataProvider('inactiveMemberships')]
    public function testDoesNotSendToAManagerNoLongerActiveInTheCompany(array $membership): void
    {
        DB::table('users_associated_apps')
            ->where('users_id', $this->user->getId())
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->update($membership);

        Notification::fake();

        $this->assertSame(0, $this->send());

        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{0: array<string, int>}>
     */
    public static function inactiveMemberships(): array
    {
        return [
            'removed' => [['is_deleted' => 1]],
            'deactivated' => [['is_active' => 0]],
            'banned' => [['banned' => 1]],
        ];
    }

    private function send(): int
    {
        return new SendEngageUsageReportAction(
            app: $this->kanvasApp,
            company: $this->company,
            request: new AnalyticsRequest(
                from: Carbon::now()->subDays(7)->startOfDay(),
                to: Carbon::now()->endOfDay(),
                bucket: AnalyticsBucketEnum::DAY,
                timezone: 'UTC',
            ),
        )->execute();
    }

    /**
     * The action skips a company with no activity before it resolves recipients, so each test
     * needs one counted message to reach the recipient lookup at all.
     */
    private function seedActivity(): void
    {
        $type = MessageType::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'verb' => 'sms-' . fake()->unique()->lexify('????'),
        ]);

        $lead = Lead::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'leads_owner_id' => $this->user->getId(),
            'users_id' => $this->user->getId(),
        ]);

        Message::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'people_id' => $lead->people_id,
            'message_types_id' => $type->id,
            'message' => ['from_me' => true],
        ]);
    }
}
