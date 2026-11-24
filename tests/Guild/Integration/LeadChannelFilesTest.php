<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleRelationship;
use Kanvas\Guild\Leads\Enums\IdVerificationFilesModeEnum;
use Kanvas\Guild\Leads\Exceptions\MissingParticipantPeopleIdException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Guild\Leads\Services\LeadChannelFilesService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Channels\Models\Channel as ChannelModel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The showroom ID verification of a co-buyer used to come back as a generic "Message Files" group with
 * participant_name = null: People::getByIdOrFail() does not exist, CompanyAction has no `actions`
 * relation, and the people_id was read off the parent message instead of the engagement.
 */
final class LeadChannelFilesTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'action_engine'];

    private const array ALL_LICENSE_FILES = [
        'drivers_license_front',
        'drivers_license_back',
        'drivers_license_combined',
        'face_image',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->clearMode();
    }

    protected function tearDown(): void
    {
        $this->clearMode();

        parent::tearDown();
    }

    /**
     * Company settings go to Redis first and are upserted on `ecosystem`, neither of which the test
     * transaction rolls back, so a mode left behind would leak into the next test and the next run.
     */
    private function clearMode(): void
    {
        auth()->user()->getCurrentCompany()->del(IdVerificationFilesModeEnum::COMPANY_KEY);
    }

    public function testIdVerificationGroupIsAttributedToTheParticipant(): void
    {
        $lead = $this->makeLead();
        $coBuyer = $this->makePeople($lead, 'Matthew Cook');
        $message = $this->makeIdVerificationMessage($lead);
        $this->makeEngagement($lead, $message, $coBuyer->getId());

        $group = $this->groupFor($lead, $message);

        $this->assertSame('id-verification', $group['verb']);
        $this->assertSame('ID Verification (Matthew Cook)', $group['action']);
        $this->assertSame('Matthew Cook', $group['participant_name']);
        $this->assertSame('submitted', $group['status']);
        $this->assertSame('id-verification', $group['files'][0]['field_name']);
    }

    public function testFileCarriesTheUuidOfItsPivotRowForDeAttach(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeIdVerificationMessage($lead);

        $file = $this->groupFor($lead, $message)['files'][0];
        $pivot = $message->getFiles()->firstOrFail();

        $this->assertSame($pivot->uuid, $file['attachment_uuid']);
        $this->assertSame((string) $pivot->id, $file['id']);
    }

    public function testPivotRowWithoutUuidReportsNullNotAnEmptyString(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeIdVerificationMessage($lead);
        FilesystemEntities::where('id', $message->getFiles()->firstOrFail()->id)->update(['uuid' => null]);

        $file = $this->groupFor($lead, $message)['files'][0];

        $this->assertNull($file['attachment_uuid']);
    }

    /**
     * Prod lead 459416: the contact channels (email-*, twilio-*, ai-assist-*) were created before the
     * engagement channel was re-created, so socialChannels()->first() returned an empty email channel
     * and channel_files came back with zero groups while the engagement files sat on the uuid channel.
     */
    public function testReadsTheEngagementChannelNotTheFirstChannel(): void
    {
        $lead = $this->makeLead();
        $lead->socialChannels()->update(['is_deleted' => 1]);
        $this->makeChannel($lead, 'email-rene-dot-mathis-at-gmail-dot-com');
        $engagementChannel = $this->makeChannel($lead, (string) $lead->uuid);

        $message = $this->makeIdVerificationMessage($lead, channel: $engagementChannel);
        $this->makeEngagement($lead, $message, (int) $lead->people_id);

        $this->assertNotSame($engagementChannel->getId(), $lead->socialChannels()->first()->getId());

        $group = $this->groupFor($lead, $message);

        $this->assertSame('ID Verification', $group['action']);
        $this->assertCount(1, $group['files']);
    }

    public function testMainBuyerEngagementHasNoParticipantSuffix(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeIdVerificationMessage($lead);
        $this->makeEngagement($lead, $message, (int) $lead->people_id);

        $group = $this->groupFor($lead, $message);

        $this->assertSame('id-verification', $group['verb']);
        $this->assertSame('ID Verification', $group['action']);
        $this->assertNull($group['participant_name']);
    }

    /**
     * The showroom flow writes people_id on the message as well; legacy engagement rows carry
     * people_id = 0, so the custom field has to win over that zero.
     */
    public function testFallsBackToTheMessagePeopleIdCustomField(): void
    {
        $lead = $this->makeLead();
        $coBuyer = $this->makePeople($lead, 'Sarah Cobb');
        $message = $this->makeIdVerificationMessage($lead);
        $message->set('people_id', $coBuyer->getId());
        $this->makeEngagement($lead, $message, 0);

        $group = $this->groupFor($lead, $message);

        $this->assertSame('ID Verification (Sarah Cobb)', $group['action']);
        $this->assertSame('Sarah Cobb', $group['participant_name']);
    }

    /**
     * The group is keyed on the thread parent but its files, engagement and labels come from the last
     * submitted child, so a people_id written only on the parent still has to be picked up.
     */
    public function testReadsThePeopleIdOffTheThreadParentWhenTheChildCarriesTheEngagement(): void
    {
        $lead = $this->makeLead();
        $coBuyer = $this->makePeople($lead, 'Dana Reyes');
        $parent = $this->makeIdVerificationMessage($lead);
        $parent->set('people_id', $coBuyer->getId());

        $child = $this->makeIdVerificationMessage($lead, $parent);
        $this->makeEngagement($lead, $child, 0);

        $group = $this->groupFor($lead, $parent);

        $this->assertSame('id-verification', $group['verb']);
        $this->assertSame('ID Verification (Dana Reyes)', $group['action']);
        $this->assertSame('Dana Reyes', $group['participant_name']);
    }

    public function testMessageWithoutEngagementKeepsGenericLabels(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeIdVerificationMessage($lead);

        $group = $this->groupFor($lead, $message);

        $this->assertSame('message', $group['verb']);
        $this->assertSame('Message Files', $group['action']);
        $this->assertNull($group['participant_name']);
    }

    public function testThrowsWhenAParticipantPeopleIdDoesNotResolve(): void
    {
        $lead = $this->makeLead();
        $orphanPeopleId = 999999999;

        LeadParticipant::create([
            'leads_id' => $lead->getId(),
            'peoples_id' => $orphanPeopleId,
            'participants_types_id' => 0,
        ]);

        $message = $this->makeIdVerificationMessage($lead);
        $this->makeEngagement($lead, $message, $orphanPeopleId);

        $this->expectException(MissingParticipantPeopleIdException::class);

        new LeadChannelFilesService($lead)->getChannelFiles();
    }

    public function testANonParticipantPeopleIdDegradesToNull(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeIdVerificationMessage($lead);
        $this->makeEngagement($lead, $message, 999999998);

        $group = $this->groupFor($lead, $message);

        $this->assertSame('ID Verification', $group['action']);
        $this->assertNull($group['participant_name']);
    }

    #[Group('serial')]
    public function testImagesModeHidesTheCombinedPdf(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeLicenseMessage($lead, self::ALL_LICENSE_FILES);
        $lead->company->set(IdVerificationFilesModeEnum::COMPANY_KEY, 'images');

        $this->assertSame(
            ['drivers_license_back', 'drivers_license_front', 'face_image', 'id-verification'],
            $this->fieldNamesOf($this->groupFor($lead, $message))
        );
    }

    #[Group('serial')]
    public function testPdfModeHidesTheSingleImages(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeLicenseMessage($lead, self::ALL_LICENSE_FILES);
        $lead->company->set(IdVerificationFilesModeEnum::COMPANY_KEY, 'pdf');

        $this->assertSame(
            ['drivers_license_combined', 'face_image', 'id-verification'],
            $this->fieldNamesOf($this->groupFor($lead, $message))
        );
    }

    #[Group('serial')]
    public function testBothUnsetAndInvalidModesChangeNothing(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeLicenseMessage($lead, self::ALL_LICENSE_FILES);
        $everything = [
            'drivers_license_back',
            'drivers_license_combined',
            'drivers_license_front',
            'face_image',
            'id-verification',
        ];

        $this->assertSame($everything, $this->fieldNamesOf($this->groupFor($lead, $message)), 'unset');

        foreach (['both', 'not-a-mode'] as $mode) {
            $lead->company->set(IdVerificationFilesModeEnum::COMPANY_KEY, $mode);

            $this->assertSame($everything, $this->fieldNamesOf($this->groupFor($lead, $message)), $mode);
        }
    }

    #[Group('serial')]
    public function testModeNeverEmptiesTheLicenseDocument(): void
    {
        $lead = $this->makeLead();
        $onlySides = $this->makeLicenseMessage($lead, ['drivers_license_front', 'drivers_license_back']);
        $onlyCombined = $this->makeLicenseMessage($lead, ['drivers_license_combined']);

        $lead->company->set(IdVerificationFilesModeEnum::COMPANY_KEY, 'pdf');
        $this->assertSame(
            ['drivers_license_back', 'drivers_license_front', 'id-verification'],
            $this->fieldNamesOf($this->groupFor($lead, $onlySides))
        );

        $lead->company->set(IdVerificationFilesModeEnum::COMPANY_KEY, 'images');
        $this->assertSame(
            ['drivers_license_combined', 'id-verification'],
            $this->fieldNamesOf($this->groupFor($lead, $onlyCombined))
        );
    }

    #[Group('serial')]
    public function testSelfieAndReportSurviveEveryMode(): void
    {
        $lead = $this->makeLead();
        $message = $this->makeLicenseMessage($lead, self::ALL_LICENSE_FILES);

        foreach (['images', 'pdf', 'both'] as $mode) {
            $lead->company->set(IdVerificationFilesModeEnum::COMPANY_KEY, $mode);
            $names = $this->fieldNamesOf($this->groupFor($lead, $message));

            $this->assertContains('face_image', $names, $mode);
            $this->assertContains('id-verification', $names, $mode);
        }
    }

    /**
     * AttachMessageFilesToLeadActivity copies message files onto the lead, so the mode has to reach
     * the lead's own group and the co-buyer's, not only the message folders.
     */
    #[Group('serial')]
    public function testModeAppliesToLeadAndParticipantGroups(): void
    {
        $lead = $this->makeLead();
        $coBuyer = $this->makePeople($lead, 'Ines Ward');
        LeadParticipant::create([
            'leads_id' => $lead->getId(),
            'peoples_id' => $coBuyer->getId(),
            'participants_types_id' => PeopleRelationship::create([
                'apps_id' => (int) $lead->apps_id,
                'companies_id' => (int) $lead->companies_id,
                'name' => 'Co-buyer',
            ])->getId(),
        ]);

        foreach ([$lead, $coBuyer] as $entity) {
            foreach (['drivers_license_front', 'drivers_license_back', 'drivers_license_combined'] as $field) {
                $entity->addFile($this->makeFilesystem($lead, $field . '.jpg'), $field);
            }
        }

        $lead->company->set(IdVerificationFilesModeEnum::COMPANY_KEY, 'pdf');
        $groups = new LeadChannelFilesService($lead)->getChannelFiles()['groups'];

        $verbs = array_column($groups, 'verb');
        $this->assertContains('upload', $verbs);
        $this->assertContains('co-buyer-upload', $verbs);

        foreach ($groups as $group) {
            $this->assertSame(['drivers_license_combined'], $this->fieldNamesOf($group), $group['verb']);
        }
    }

    private function groupFor(Lead $lead, Message $message): array
    {
        $groups = new LeadChannelFilesService($lead)->getChannelFiles()['groups'];

        foreach ($groups as $group) {
            if ($group['uuid'] === $message->uuid) {
                return $group;
            }
        }

        $this->fail('no group came back for message ' . $message->getId());
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        //the engagement observer fans a submitted notification out to the lead stakeholders on save
        $company->set('disable_all_notifications', true);

        $pipeline = Pipeline::firstOrCreate([
            'slug' => ConfigurationEnum::ID_VERIFICATION->value,
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'users_id' => $user->getId(),
            'name' => 'ID Verification',
            'weight' => 0,
        ]);

        PipelineStage::firstOrCreate([
            'pipelines_id' => $pipeline->getId(),
            'slug' => 'submitted',
        ], [
            'name' => 'Submitted',
            'weight' => 1,
        ]);

        $action = Action::firstOrCreate([
            'slug' => ConfigurationEnum::ID_VERIFICATION->value,
        ], [
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'pipelines_id' => $pipeline->getId(),
            'name' => 'ID Verification',
        ]);

        CompanyAction::firstOrCreate([
            'actions_id' => $action->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'users_id' => $user->getId(),
            'companies_branches_id' => ($company->defaultBranch ?? $company->branch()->firstOrFail())->getId(),
            'pipelines_id' => $pipeline->getId(),
            'name' => 'ID Verification',
        ]);

        new CreateChannelAction(new Channel(
            apps: $app,
            companies: $company,
            users: $user,
            entity_id: $lead->getId(),
            entity_namespace: Lead::class,
            name: (string) $lead->uuid,
            slug: (string) $lead->uuid,
            description: (string) $lead->uuid,
        ))->execute();

        return $lead;
    }

    private function makePeople(Lead $lead, string $name): People
    {
        return People::factory()
            ->withAppId((int) $lead->apps_id)
            ->withCompanyId((int) $lead->companies_id)
            ->withUserId((int) $lead->users_id)
            ->create(['name' => $name]);
    }

    /**
     * Same shape the showroom flow builds in DriverLicenseVerificationService::createEngagement — the
     * system module link matters because the engagement observer reads notification copy off
     * message->entity().
     */
    private function makeIdVerificationMessage(
        Lead $lead,
        ?Message $parent = null,
        ?ChannelModel $channel = null
    ): Message {
        $app = app(Apps::class);
        $user = auth()->user();

        $messageType = MessageType::firstOrCreate([
            'apps_id' => $app->getId(),
            'languages_id' => 1,
            'verb' => ConfigurationEnum::ID_VERIFICATION->value,
        ], [
            'name' => 'ID Verification',
        ]);

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $lead->company,
                user: $user,
                type: $messageType,
                message: [
                    'engagement_status' => 'submitted',
                    'hashtagVisited' => ConfigurationEnum::ID_VERIFICATION->value,
                    'text' => 'ID Verification Showroom',
                    'source' => 'workflow',
                    'status' => 'submitted',
                    'verb' => ConfigurationEnum::ID_VERIFICATION->value,
                ],
                parent_id: $parent?->getId(),
            ),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        )->execute();

        $message->addFile($this->makeFilesystem($lead), 'id-verification');
        ($channel ?? $lead->socialChannels()->firstOrFail())->addMessage($message, $user);

        return $message;
    }

    /**
     * Straight on the model: CreateChannelAction dedups on slug with no is_deleted filter, so it would
     * hand back the soft-deleted channel instead of a second row with the same slug, which is what
     * prod holds for this lead.
     */
    private function makeChannel(Lead $lead, string $slug): ChannelModel
    {
        return ChannelModel::create([
            'apps_id' => (int) $lead->apps_id,
            'companies_id' => (int) $lead->companies_id,
            'users_id' => (int) $lead->users_id,
            'slug' => $slug,
            'entity_id' => (string) $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => $slug,
            'description' => $slug,
        ]);
    }

    /**
     * The report PDF every ID verification folder carries, plus the given license files by field_name.
     *
     * @param  array<int, string>  $fieldNames
     */
    private function makeLicenseMessage(Lead $lead, array $fieldNames): Message
    {
        $message = $this->makeIdVerificationMessage($lead);

        foreach ($fieldNames as $fieldName) {
            $message->addFile($this->makeFilesystem($lead, $fieldName . '.jpg'), $fieldName);
        }

        return $message;
    }

    /** @return array<int, string> */
    private function fieldNamesOf(array $group): array
    {
        $names = array_column($group['files'], 'field_name');
        sort($names);

        return $names;
    }

    private function makeFilesystem(Lead $lead, string $name = 'id-verification-report.pdf'): Filesystem
    {
        $filesystem = new Filesystem();
        $filesystem->apps_id = (int) $lead->apps_id;
        $filesystem->companies_id = (int) $lead->companies_id;
        $filesystem->users_id = (int) $lead->users_id;
        $filesystem->name = $name;
        $filesystem->path = 'files/id-verification/' . uniqid() . '.pdf';
        $filesystem->url = 'https://cdn.salesassist.io/files/id-verification/' . uniqid() . '.pdf';
        $filesystem->file_type = 'pdf';
        $filesystem->size = '50433';
        $filesystem->is_deleted = 0;
        $filesystem->saveOrFail();

        return $filesystem;
    }

    private function makeEngagement(Lead $lead, Message $message, int $peopleId): Engagement
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $pipeline = Pipeline::query()
            ->where('slug', ConfigurationEnum::ID_VERIFICATION->value)
            ->fromApp($app)
            ->fromCompany($company)
            ->firstOrFail();

        $action = Action::query()->where('slug', ConfigurationEnum::ID_VERIFICATION->value)->firstOrFail();

        $engagement = new Engagement();
        $engagement->apps_id = $app->getId();
        $engagement->companies_id = $company->getId();
        $engagement->users_id = (int) $lead->users_id;
        $engagement->leads_id = $lead->getId();
        $engagement->people_id = $peopleId;
        $engagement->companies_actions_id = CompanyAction::query()
            ->where('actions_id', $action->getId())
            ->fromApp($app)
            ->fromCompany($company)
            ->firstOrFail()
            ->getId();
        $engagement->message_id = $message->getId();
        $engagement->slug = ConfigurationEnum::ID_VERIFICATION->value;
        $engagement->entity_uuid = (string) Str::uuid();
        $engagement->pipelines_stages_id = $pipeline->stages()->where('slug', 'submitted')->firstOrFail()->getId();
        $engagement->saveOrFail();

        return $engagement;
    }
}
