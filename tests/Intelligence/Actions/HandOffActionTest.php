<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadHandOffNotification;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Actions\HandOffAction;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Notifications\HandOffNotification;
use PHPUnit\Framework\Attributes\Group;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class HandOffActionTest extends TestCase
{
    private ?Lead $lead = null;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        LeadType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Warm',
            ],
            [
                'description' => 'Warm Lead Type',
                'is_active' => true,
                'uuid' => Str::uuid(),
            ]
        );
    }

    public function testHandOffDefaultsToHuman(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $result = new HandOffAction($lead, $app)->execute();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Handoff processed successfully', $result['message']);

        $lead->refresh();
        $this->assertEquals(1, $lead->get(ConfigurationEnum::AGENT_HAND_OFF->value));
        $this->assertEquals('human', $lead->get(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value));
    }

    public function testHandOffWithServiceType(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $result = new HandOffAction($lead, $app, [
            'handoff_type' => 'service',
        ])->execute();

        $this->assertTrue($result['success']);

        $lead->refresh();
        $this->assertEquals('service', $lead->get(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value));

        $serviceType = LeadType::where('apps_id', $app->getId())
            ->where('companies_id', $lead->companies_id)
            ->where('name', 'Service')
            ->where('is_deleted', 0)
            ->first();

        $this->assertNotNull($serviceType);
        $this->assertEquals($serviceType->getId(), $lead->leads_types_id);
    }

    public function testHandOffWithConversationSummary(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $result = new HandOffAction($lead, $app, [
            'handoff_type' => 'human',
            'conversation_summary' => 'Customer wants financing options.',
        ])->execute();

        $this->assertTrue($result['success']);
    }

    public function testHandOffDeduplication(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $params = [
            'handoff_type' => 'human',
            'conversation_summary' => 'Dedup test',
        ];

        $result1 = new HandOffAction($lead, $app, $params)->execute();
        $this->assertTrue($result1['success']);
        $this->assertArrayNotHasKey('duplicate', $result1);

        $result2 = new HandOffAction($lead, $app, $params)->execute();
        $this->assertTrue($result2['success']);
        $this->assertTrue($result2['duplicate']);
    }

    public function testHandOffSetsCustomFields(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        new HandOffAction($lead, $app, [
            'handoff_type' => 'human',
        ])->execute();

        $lead->refresh();
        $this->assertEquals(1, $lead->get(ConfigurationEnum::AGENT_HAND_OFF->value));
        $this->assertEquals('human', $lead->get(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value));
    }

    public function testHandOffIsDeduplicatedRegardlessOfParams(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $result1 = new HandOffAction($lead, $app, [
            'handoff_type' => 'human',
            'conversation_summary' => 'Customer asked for a human.',
        ])->execute();

        $this->assertArrayNotHasKey('duplicate', $result1);

        // The old dedup key hashed $params, so a different LLM summary — or a different
        // handoff_type — re-notified the owner and every manager for the same lead.
        $result2 = new HandOffAction($lead, $app, [
            'handoff_type' => 'service',
            'conversation_summary' => 'Totally different prose from the model.',
        ])->execute();

        $this->assertTrue($result2['duplicate']);
    }

    public function testHandOffDoesNotNotifyTwiceWhenTheFirstPassFailsMidway(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();

        Notification::fake();

        // HandOffActivity sets tries = 3; a retry must never re-send.
        $retry = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();

        $this->assertTrue($retry['duplicate']);
        Notification::assertNothingSent();
    }

    #[Group('serial')]
    public function testOnlySmsSettingDropsMailAndPushOnEveryHandOffType(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);
        $company = $lead->company;

        $company->set('ai_human_handoff_only_sms', 1);
        $company->set('ai_human_handoff_only_mail', 1);

        try {
            new HandOffAction($lead, $app, ['handoff_type' => 'service'])->execute();

            Notification::assertSentTo(
                $lead->owner ?? $lead->user,
                HandOffNotification::class,
                function (HandOffNotification $notification): bool {
                    $this->assertSame(['sms'], $notification->channels);

                    return true;
                }
            );
        } finally {
            $company->del('ai_human_handoff_only_sms');
            $company->del('ai_human_handoff_only_mail');
        }
    }

    #[Group('serial')]
    public function testCompanyConfigRaisesTheNotificationCeiling(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);
        $company = $lead->company;

        $company->set('ai_handoff_max_notifications', 3);

        try {
            $first = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();
            $this->assertSame(1, $first['notifications_sent']);
            $this->assertSame(3, $first['max_notifications']);

            $second = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();
            $this->assertArrayNotHasKey('duplicate', $second);
            $this->assertSame(2, $second['notifications_sent']);

            $third = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();
            $this->assertSame(3, $third['notifications_sent']);

            $fourth = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();
            $this->assertTrue($fourth['duplicate']);
            $this->assertSame(3, $fourth['notifications_sent']);
        } finally {
            $company->del('ai_handoff_max_notifications');
        }
    }

    #[Group('serial')]
    public function testZeroCeilingIsAKillSwitch(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);
        $company = $lead->company;

        $company->set('ai_handoff_max_notifications', 0);

        try {
            $result = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();

            $this->assertTrue($result['duplicate']);
            Notification::assertNothingSent();
        } finally {
            $company->del('ai_handoff_max_notifications');
        }
    }

    #[Group('serial')]
    public function testCeilingFallsBackToAppConfigThenOne(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $this->assertSame(
            1,
            new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute()['max_notifications']
        );

        $app->set('ai_handoff_max_notifications', 2);

        try {
            $second = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();

            $this->assertArrayNotHasKey('duplicate', $second);
            $this->assertSame(2, $second['max_notifications']);
        } finally {
            $app->del('ai_handoff_max_notifications');
        }
    }

    public function testConcurrentClaimsCannotBothWinTheSameSlot(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        // Simulate the parallel lane (a retry, or the workflow firing alongside the tool) taking
        // slot 1 between this action's count read and its own claim. The unique key must reject
        // the second write rather than let both notify.
        $claimed = LeadHandOffNotification::query()->insertOrIgnore([
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'leads_id' => $lead->getKey(),
            'sequence' => 1,
            'handoff_type' => 'human',
            'created_at' => now(),
        ]);
        $this->assertSame(1, $claimed);

        $raced = LeadHandOffNotification::query()->insertOrIgnore([
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'leads_id' => $lead->getKey(),
            'sequence' => 1,
            'handoff_type' => 'human',
            'created_at' => now(),
        ]);
        $this->assertSame(0, $raced, 'UNIQUE(leads_id, sequence) did not reject the duplicate claim');

        $result = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();

        $this->assertTrue($result['duplicate']);
        Notification::assertNothingSent();
        $this->assertSame(
            1,
            LeadHandOffNotification::query()->where('leads_id', $lead->getKey())->count()
        );
    }

    #[Group('serial')]
    public function testEachSentNotificationLeavesExactlyOneClaimRow(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);
        $company = $lead->company;

        $company->set('ai_handoff_max_notifications', 2);

        try {
            new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();
            new HandOffAction($lead, $app, ['handoff_type' => 'service'])->execute();
            new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();

            $claims = LeadHandOffNotification::query()
                ->where('leads_id', $lead->getKey())
                ->orderBy('sequence')
                ->get();

            $this->assertCount(2, $claims);
            $this->assertSame([1, 2], $claims->pluck('sequence')->all());
            $this->assertSame(['human', 'service'], $claims->pluck('handoff_type')->all());
        } finally {
            $company->del('ai_handoff_max_notifications');
        }
    }

    #[Group('serial')]
    public function testALegacyDedupMarkerConsumesTheFirstSlot(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);
        $company = $lead->company;

        // A lead the old param-hashed code already notified once.
        $lead->set('handoff_dedup_' . md5('legacy payload'), time());
        $company->set('ai_handoff_max_notifications', 3);

        try {
            $first = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();
            $this->assertSame(2, $first['notifications_sent'], 'legacy marker should count as slot 1');

            $second = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();
            $this->assertSame(3, $second['notifications_sent']);

            $third = new HandOffAction($lead, $app, ['handoff_type' => 'human'])->execute();
            $this->assertTrue($third['duplicate'], 'ceiling counts the legacy notification, not just new rows');

            // The counter undercounts a legacy lead by one, but claimNotificationSlot() walks past
            // sequences already taken, so the ceiling still caps total notifications at 3.
            $this->assertSame(
                [2, 3],
                LeadHandOffNotification::query()
                    ->where('leads_id', $lead->getKey())
                    ->orderBy('sequence')
                    ->pluck('sequence')
                    ->all()
            );
        } finally {
            $company->del('ai_handoff_max_notifications');
        }
    }

    private function createTestLead(): Lead
    {
        if ($this->lead !== null) {
            return $this->lead;
        }

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $branch = $company->defaultBranch;

        $contactData = [
            [
                'value' => fake()->phoneNumber(),
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'weight' => 100,
            ],
        ];

        $peopleDto = new PeopleDto(
            app: $app,
            branch: $branch,
            user: $user,
            firstname: 'HandOff',
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: 'Test',
        );

        $people = new CreatePeopleAction($peopleDto)->execute();

        $leadType = LeadType::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('name', 'Warm')
            ->firstOrFail();

        $leadData = new LeadData(
            app: $app,
            branch: $branch,
            user: $user,
            title: 'HandOff Test Lead',
            pipeline_stage_id: 0,
            people: new PeopleDto(
                $app,
                $branch,
                $user,
                (string) $people->firstname,
                Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                Address::collect([], DataCollection::class),
                (string) $people->lastname,
                $people->id,
            ),
            leads_owner_id: $user->getId(),
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: 0,
        );

        $this->lead = new CreateLeadAction($leadData)->execute();

        return $this->lead;
    }
}
