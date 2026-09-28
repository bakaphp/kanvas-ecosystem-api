<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Kanvas\Analytics\Actions\BuildEngagementLeaderboardAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Analytics\Enums\AnalyticsBucketEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Enums\MessageChannelEnum;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\TestCase;

class EngagementLeaderboardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Messages + app_module_message live on `social`, leads on `crm`, appointments on `event`,
     * receivers on `workflow`. Omitting any of them leaks rows into the next test in this file.
     */
    protected $connectionsToTransact = [null, 'social', 'crm', 'event', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;
    private MessageType $smsType;
    private MessageType $emailType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);

        // A company of its own rather than the shared test company. Every assertion here is a count
        // over everything the leaderboard can see in one company, so any concurrently running worker
        // that writes to the shared one changes the answer — and the AI-agent-user setting below is
        // company-wide, so a worker reading it mid-test loses a rep row entirely.
        $this->company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $this->company->associateApp($this->kanvasApp);

        $this->smsType = MessageType::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'verb' => 'sms-' . fake()->unique()->lexify('????'),
        ]);

        $this->emailType = MessageType::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'verb' => 'email-' . fake()->unique()->lexify('????'),
        ]);
    }

    public function testCreditsASendToItsSenderAndAnInboundReplyToTheLeadOwner(): void
    {
        $owner = $this->createRep();
        $webhookUser = $this->createRep();
        $lead = $this->createLead($owner);

        // Outbound is written with the rep's own users_id...
        $this->createMessage($lead, ['from_me' => true], $owner);
        // ...but inbound carries the receiver webhook's user, which is the bug this guards.
        $this->createMessage($lead, ['from_me' => false], $webhookUser);

        $rows = $this->leaderboard()['rows'];

        $this->assertCount(1, $rows, 'the webhook user must not appear as a rep of its own');
        $this->assertSame($owner->getId(), $rows[0]['users_id']);
        $this->assertSame(1, $rows[0]['rep_sent']);
        $this->assertSame(1, $rows[0]['replies']);
    }

    /**
     * The reason this attribution changed: a rep covering a colleague's lead used to be invisible,
     * with all their volume folded into the owner's row.
     */
    public function testCreditsASendToTheRepWhoTypedItRatherThanTheLeadOwner(): void
    {
        $owner = $this->createRep();
        $coveringRep = $this->createRep();
        $lead = $this->createLead($owner);

        $this->createMessage($lead, ['from_me' => true], $coveringRep);

        $rows = collect($this->leaderboard()['rows'])->keyBy('users_id')->all();

        $this->assertArrayHasKey($coveringRep->getId(), $rows);
        $this->assertSame(1, $rows[$coveringRep->getId()]['rep_sent']);
        $this->assertArrayNotHasKey($owner->getId(), $rows);
    }

    public function testCreditsAiSendsToTheLeadOwnerNotTheAgentsUser(): void
    {
        $owner = $this->createRep();
        $agentUser = $this->createRep();
        $lead = $this->createLead($owner);

        $this->createMessage($lead, ['from_me' => true, 'from_ia' => true], $agentUser);

        $rows = $this->leaderboard()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame($owner->getId(), $rows[0]['users_id']);
        $this->assertSame(1, $rows[0]['ai_sent']);
    }

    /**
     * WaSender stores `receiver->user` on a message the rep typed on their own phone, and RespondIO
     * does the same on every outgoing. Crediting that sender would crown the receiver top rep on
     * every connector-backed company, so those rows fall back to the lead owner.
     */
    public function testFallsBackToTheLeadOwnerWhenAConnectorSendsAsItsReceiverUser(): void
    {
        $owner = $this->createRep();
        $receiverUser = $this->createRep();
        $lead = $this->createLead($owner);

        ReceiverWebhook::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $receiverUser->getId(),
        ]);

        $this->createMessage($lead, ['from_me' => true], $receiverUser);

        $rows = $this->leaderboard()['rows'];

        $this->assertCount(1, $rows, 'the receiver user must not appear as a rep of its own');
        $this->assertSame($owner->getId(), $rows[0]['users_id']);
        $this->assertSame(1, $rows[0]['rep_sent']);
    }

    public function testCreditsResponseTimeToTheRepWhoActuallyReplied(): void
    {
        $owner = $this->createRep();
        $coveringRep = $this->createRep();
        $lead = $this->createLead($owner);

        $this->createRespondedPair($lead, $coveringRep, 90, MessageSenderTypeEnum::USER);

        $rows = collect($this->leaderboard()['rows'])->keyBy('users_id')->all();

        $this->assertSame(90, $rows[$coveringRep->getId()]['median_response_seconds']);
        $this->assertNull($rows[$owner->getId()]['median_response_seconds']);
    }

    public function testExcludesNonCommunicationMessages(): void
    {
        $owner = $this->createRep();
        $lead = $this->createLead($owner);

        $this->createMessage($lead, ['from_me' => true], $owner);
        // No `from_me` key → sender_type stays NULL → a social post, note or system row.
        $this->createMessage($lead, ['message' => 'a social post', 'params' => []], $owner);

        $rows = $this->leaderboard()['rows'];

        $this->assertSame(1, $rows[0]['total_sent']);
    }

    public function testChannelFilterSeparatesSmsFromEmail(): void
    {
        $owner = $this->createRep();
        $lead = $this->createLead($owner);

        $this->createMessage($lead, ['from_me' => true], $owner, $this->smsType);
        $this->createMessage($lead, ['from_me' => true], $owner, $this->emailType);
        $this->createMessage($lead, ['from_me' => true], $owner, $this->emailType);

        $this->assertSame(3, $this->leaderboard()['team']['total_sent']);
        $this->assertSame(1, $this->leaderboard(MessageChannelEnum::SMS)['team']['total_sent']);
        $this->assertSame(2, $this->leaderboard(MessageChannelEnum::EMAIL)['team']['total_sent']);
    }

    public function testSplitsHumanAndAiSends(): void
    {
        $owner = $this->createRep();
        $lead = $this->createLead($owner);

        $this->createMessage($lead, ['from_me' => true], $owner);
        $this->createMessage($lead, ['from_me' => true, 'from_ia' => true], $owner);
        $this->createMessage($lead, ['from_me' => true, 'from_orchestrator' => true], $owner);

        $row = $this->leaderboard()['rows'][0];

        $this->assertSame(3, $row['total_sent']);
        $this->assertSame(1, $row['rep_sent']);
        $this->assertSame(2, $row['ai_sent']);
        $this->assertSame(0.6667, round((float) $this->leaderboard()['team']['ai_share'], 4));
    }

    public function testMedianResponseTimeIgnoresAiReplies(): void
    {
        $owner = $this->createRep();
        $lead = $this->createLead($owner);

        // Human answered after 120s, AI answered after 5s. Only the human pair may count.
        $this->createRespondedPair($lead, $owner, 120, MessageSenderTypeEnum::USER);
        $this->createRespondedPair($lead, $owner, 5, MessageSenderTypeEnum::AGENT);

        $this->assertSame(120, $this->leaderboard()['rows'][0]['median_response_seconds']);
    }

    public function testRepWithSendsButNoInboundHasZeroReplyRateAndNullMedian(): void
    {
        $owner = $this->createRep();
        $lead = $this->createLead($owner);

        $this->createMessage($lead, ['from_me' => true], $owner);

        $row = $this->leaderboard()['rows'][0];

        $this->assertSame(0, $row['replies']);
        $this->assertSame(0.0, $row['reply_rate']);
        $this->assertNull($row['median_response_seconds']);
    }

    public function testExcludesTheAiAgentUserAsItsOwnRow(): void
    {
        $aiUser = $this->createRep();
        $this->company->set(ConfigurationEnum::AI_AGENT_USER_ID->value, $aiUser->getId());

        $aiOwnedLead = $this->createLead($aiUser);
        $this->createMessage($aiOwnedLead, ['from_me' => true, 'from_ia' => true], $aiUser);

        $owner = $this->createRep();
        $lead = $this->createLead($owner);
        $this->createMessage($lead, ['from_me' => true], $owner);

        $rows = $this->leaderboard()['rows'];

        // Company settings are not covered by $connectionsToTransact. The company is this test's
        // own now, so nothing else can inherit it, but the setting is cached per company id —
        // clear it rather than leaving a dangling AI user behind that id.
        $this->company->del(ConfigurationEnum::AI_AGENT_USER_ID->value);

        $this->assertCount(1, $rows);
        $this->assertSame($owner->getId(), $rows[0]['users_id']);
    }

    public function testDoesNotLeakAnotherCompanysMessages(): void
    {
        $owner = $this->createRep();
        $lead = $this->createLead($owner);
        $this->createMessage($lead, ['from_me' => true], $owner);

        $otherCompany = Companies::factory()->create();
        $otherOwner = $this->createRep();
        $otherLead = $this->createLead($otherOwner, $otherCompany);
        $this->createMessage($otherLead, ['from_me' => true], $otherOwner, null, $otherCompany);

        $rows = $this->leaderboard()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame($owner->getId(), $rows[0]['users_id']);
        $this->assertSame(1, $this->leaderboard()['team']['total_sent']);
    }

    public function testCountsAppointmentsBookedForTheLeadOwner(): void
    {
        $owner = $this->createRep();
        $lead = $this->createLead($owner);
        $this->createMessage($lead, ['from_me' => true], $owner);

        $this->createAppointment($owner);
        $this->createAppointment($owner);

        $this->assertSame(2, $this->leaderboard()['rows'][0]['appointments']);
        $this->assertSame(2, $this->leaderboard()['team']['appointments']);
    }

    public function testUsesTheRepsRealNameRatherThanTheirDisplayname(): void
    {
        $owner = $this->createRep();
        $owner->firstname = 'Kevin';
        $owner->lastname = 'Bury';
        $owner->displayname = 'kbury19236';
        $owner->saveOrFail();

        $lead = $this->createLead($owner);
        $this->createMessage($lead, ['from_me' => true], $owner);

        $this->assertSame('Kevin Bury', $this->leaderboard()['rows'][0]['name']);
    }

    public function testFallsBackToDisplaynameWhenThereIsNoRealName(): void
    {
        $owner = $this->createRep();
        $owner->firstname = '';
        $owner->lastname = '';
        $owner->displayname = 'kbury19236';
        $owner->saveOrFail();

        $lead = $this->createLead($owner);
        $this->createMessage($lead, ['from_me' => true], $owner);

        $this->assertSame('kbury19236', $this->leaderboard()['rows'][0]['name']);
    }

    public function testRanksRowsByTotalSentDescending(): void
    {
        $quiet = $this->createRep();
        $busy = $this->createRep();

        $quietLead = $this->createLead($quiet);
        $busyLead = $this->createLead($busy);

        $this->createMessage($quietLead, ['from_me' => true], $quiet);
        foreach (range(1, 3) as $ignored) {
            $this->createMessage($busyLead, ['from_me' => true], $busy);
        }

        $rows = $this->leaderboard()['rows'];

        $this->assertSame($busy->getId(), $rows[0]['users_id']);
        $this->assertSame($quiet->getId(), $rows[1]['users_id']);
        $this->assertSame(2, $this->leaderboard()['team']['reps']);
    }

    public function testEmailTemplateRendersTheLeaderboard(): void
    {
        $owner = $this->createRep();
        $lead = $this->createLead($owner);
        $this->createMessage($lead, ['from_me' => true], $owner);
        $this->createMessage($lead, ['from_me' => false], $owner);

        $result = $this->leaderboard();

        $html = View::make('emails.analytics.engage-usage-report', [
            'company_name' => $this->company->name,
            'range_label' => 'Aug 7 – Aug 13, 2026',
            'from' => '2026-08-07',
            'to' => '2026-08-13',
            'channel_label' => 'SMS & email',
            'rows' => $result['rows'],
            'team' => $result['team'],
        ])->render();

        $this->assertStringContainsString('Engage usage', $html);
        // Blade escapes the name, so a rep faker happened to call O'Connell only matches escaped.
        $this->assertStringContainsString(e($result['rows'][0]['name']), $html);
        $this->assertStringContainsString('Team total', $html);
    }

    public function testEmailTemplateRendersAnEmptyPeriodWithoutErroring(): void
    {
        $html = View::make('emails.analytics.engage-usage-report', [
            'company_name' => $this->company->name,
            'range_label' => 'Aug 7 – Aug 13, 2026',
            'from' => '2026-08-07',
            'to' => '2026-08-13',
            'channel_label' => 'SMS & email',
            'rows' => [],
            'team' => $this->emptyTeam(),
        ])->render();

        $this->assertStringContainsString('No Engage activity', $html);
    }

    /**
     * The team row as it comes back for a period with no activity at all.
     *
     * @return array<string, mixed>
     */
    private function emptyTeam(): array
    {
        return new BuildEngagementLeaderboardAction(
            app: $this->kanvasApp,
            company: $this->company,
            request: new AnalyticsRequest(
                from: Carbon::now()->subYears(5)->startOfDay(),
                to: Carbon::now()->subYears(5)->endOfDay(),
                bucket: AnalyticsBucketEnum::DAY,
                timezone: 'UTC',
            ),
        )->execute()['team'];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, team: array<string, mixed>}
     */
    private function leaderboard(MessageChannelEnum $channel = MessageChannelEnum::ALL): array
    {
        return new BuildEngagementLeaderboardAction(
            app: $this->kanvasApp,
            company: $this->company,
            request: new AnalyticsRequest(
                from: Carbon::now()->subDays(7)->startOfDay(),
                to: Carbon::now()->endOfDay(),
                bucket: AnalyticsBucketEnum::DAY,
                timezone: 'UTC',
            ),
            channel: $channel,
        )->execute();
    }

    /**
     * The base createUser() picks a non-unique fake()->email, and this file registers a dozen-odd
     * reps per run — enough for "Email has already been taken" to hit intermittently. Same
     * registration path, deterministic address.
     */
    private function createRep(): Users
    {
        return new RegisterUsersAction(RegisterInput::from([
            'email' => 'rep-' . uniqid('', true) . '@example.test',
            'password' => fake()->password(8),
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
        ]))->execute();
    }

    private function createLead(Users $owner, ?Companies $company = null): Lead
    {
        return Lead::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => ($company ?? $this->company)->getId(),
            'leads_owner_id' => $owner->getId(),
            'users_id' => $owner->getId(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createMessage(
        Lead $lead,
        array $payload,
        Users $user,
        ?MessageType $type = null,
        ?Companies $company = null,
    ): Message {
        $type ??= $this->smsType;
        $company ??= $this->company;

        $message = Message::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'people_id' => $lead->people_id,
            'message_types_id' => $type->id,
            'message' => $payload,
        ]);

        AppModuleMessage::create([
            'message_id' => $message->id,
            'message_types_id' => $type->id,
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'is_deleted' => 0,
        ]);

        return $message;
    }

    /**
     * An inbound customer message already marked as answered, with the reply landing
     * $seconds later — the shape MarkLeadMessagesAsRespondedAction writes.
     */
    private function createRespondedPair(
        Lead $lead,
        Users $sender,
        int $seconds,
        MessageSenderTypeEnum $responder,
    ): void {
        $inboundAt = Carbon::now()->subHour();

        $reply = $this->createMessage(
            $lead,
            $responder === MessageSenderTypeEnum::AGENT
                ? ['from_me' => true, 'from_ia' => true]
                : ['from_me' => true],
            $sender,
        );
        $reply->forceFill(['created_at' => $inboundAt->copy()->addSeconds($seconds)])->saveQuietly();

        $inbound = $this->createMessage($lead, ['from_me' => false], $sender);
        $inbound->forceFill([
            'created_at' => $inboundAt,
            'is_un_response' => true,
            'response_message_id' => $reply->id,
        ])->saveQuietly();
    }

    /**
     * `events` has FKs onto six lookup tables. A seeded DB already has rows; a fresh CI database
     * does not, and `value('id')` on an empty table returns null which casts to 0 and trips the
     * constraint. Create the row when it is missing rather than assuming a seed.
     *
     * @param  array<string, mixed>  $extra
     */
    private function eventLookupId(string $table, array $extra = []): int
    {
        $existing = (int) DB::connection('event')->table($table)->value('id');

        if ($existing > 0) {
            return $existing;
        }

        return (int) DB::connection('event')->table($table)->insertGetId([
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Test ' . $table,
            ...$extra,
        ]);
    }

    private function createAppointment(Users $owner): void
    {
        $typeId = $this->eventLookupId('event_types');
        $classId = $this->eventLookupId('event_classes');

        DB::connection('event')->table('events')->insert([
            'uuid' => fake()->uuid(),
            'users_id' => $owner->getId(),
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'theme_id' => $this->eventLookupId('themes'),
            'theme_area_id' => $this->eventLookupId('theme_areas'),
            'event_status_id' => $this->eventLookupId('event_statuses'),
            'event_type_id' => $typeId,
            'event_class_id' => $classId,
            'event_category_id' => $this->eventLookupId('event_categories', [
                'event_type_id' => $typeId,
                'event_class_id' => $classId,
                'slug' => 'test-category-' . uniqid('', true),
            ]),
            'name' => 'Test appointment',
            'slug' => 'test-appointment-' . fake()->unique()->uuid(),
            'resources_type' => Lead::class,
            'resources_id' => 1,
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
