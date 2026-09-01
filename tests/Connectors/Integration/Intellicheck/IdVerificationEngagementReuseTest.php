<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Connectors\Intellicheck\Activities\GenerateIdVerificationActivity;
use Kanvas\Connectors\Intellicheck\Activities\IdVerificationReportActivity;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The folder the UI renders is a root message — `LeadChannelFilesService` groups on
 * `parent_id = 0 OR NULL`. A report that creates its own root shows up as a second folder next to the
 * one holding the licence images, which is the bug these tests pin shut.
 */
final class IdVerificationEngagementReuseTest extends TestCase
{
    public function testAnExistingSubmittedEngagementIsReusedInsteadOfCreatingASecondOne(): void
    {
        $lead = $this->makeLead();
        $people = $lead->people;

        $first = $this->createEngagement($lead, $people);
        $this->assertNotNull($first);

        $resolved = $this->invokeActionResolveEngagement($lead, $people, null);

        $this->assertNotNull($resolved);
        $this->assertSame($first->getId(), $resolved->getId(), 'a second engagement would be a second folder');
    }

    public function testThreadingUnderAParentKeepsTheReportInTheSameFolder(): void
    {
        $lead = $this->makeLead();
        $people = $lead->people;

        $parent = $this->createEngagement($lead, $people);
        $this->assertNotNull($parent);

        $child = $this->invokeActionResolveEngagement($lead, $people, $parent);

        $this->assertNotNull($child);
        $this->assertNotSame($parent->getId(), $child->getId());
        $this->assertSame(
            $parent->message_id,
            $child->message->parent_id,
            'the child message must hang off the parent, or it renders as its own folder'
        );
        $this->assertSame(
            $parent->entity_uuid,
            $child->entity_uuid,
            'a fresh entity_uuid would break stageHistory grouping'
        );
    }

    /**
     * `findEngagementForLead` filters only by lead + stage, so on a lead with participants it returns
     * whichever row is newest — which is how a co-buyer's report ended up on the main buyer's message.
     */
    public function testTheEngagementLookupIsScopedToTheVerifiedPerson(): void
    {
        $lead = $this->makeLead();
        $mainBuyer = $lead->people;
        $coBuyer = $this->makePerson($lead);

        $mainEngagement = $this->createEngagement($lead, $mainBuyer);
        $coBuyerEngagement = $this->createEngagement($lead, $coBuyer);

        $this->assertNotNull($mainEngagement);
        $this->assertNotNull($coBuyerEngagement);
        $this->assertNotSame($mainEngagement->getId(), $coBuyerEngagement->getId());

        $this->assertSame(
            $mainBuyer->getId(),
            $this->findForPeople($lead, $mainBuyer)?->people_id
        );
        $this->assertSame(
            $coBuyer->getId(),
            $this->findForPeople($lead, $coBuyer)?->people_id
        );
    }

    public function testTheActivityRejectsAnEngagementBelongingToAnotherLead(): void
    {
        $lead = $this->makeLead();
        $otherLead = $this->makeLead();

        $foreign = $this->createEngagement($otherLead, $otherLead->people);
        $this->assertNotNull($foreign);

        $this->assertNull(
            $this->invokeActivityResolveEngagement($lead, ['eid' => $foreign->getId()]),
            'a stale or copy-pasted eid must not attach this report to another lead'
        );
    }

    public function testTheActivityResolvesAnEngagementByIdAndByUuid(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $this->assertSame(
            $engagement->getId(),
            $this->invokeActivityResolveEngagement($lead, ['eid' => $engagement->getId()])?->getId()
        );
        $this->assertSame(
            $engagement->getId(),
            $this->invokeActivityResolveEngagement($lead, ['eid' => $engagement->uuid])?->getId()
        );
    }

    public function testTheActivityReturnsNullWithoutAnEid(): void
    {
        $lead = $this->makeLead();

        $this->assertNull($this->invokeActivityResolveEngagement($lead, []));
        $this->assertNull($this->invokeActivityResolveEngagement($lead, ['eid' => '']));
    }

    public function testAParticipantScanResolvesToTheEngagementsOwnPerson(): void
    {
        $lead = $this->makeLead();
        $coBuyer = $this->makePerson($lead);
        $engagement = $this->createEngagement($lead, $coBuyer);
        $this->assertNotNull($engagement);

        $resolved = $this->invokeActivityResolvePeople($lead, $engagement, []);

        $this->assertNotNull($resolved);
        $this->assertSame($coBuyer->getId(), $resolved->getId());
    }

    public function testAMainBuyerScanResolvesToTheLeadsPerson(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $resolved = $this->invokeActivityResolvePeople($lead, $engagement, []);

        $this->assertNotNull($resolved);
        $this->assertSame($lead->people_id, $resolved->getId());
    }

    public function testImagesAreReadFromTheImagesKey(): void
    {
        $resolved = $this->invokeResolveImages([
            'images' => ['front' => 'FRONT', 'back' => 'BACK', 'face' => 'FACE'],
        ]);

        $this->assertSame(['front' => 'FRONT', 'back' => 'BACK', 'face' => 'FACE'], $resolved);
    }

    /**
     * A selfie has no caller-side source — Intellicheck is the only thing that produces one, inside
     * `facial.data.photoFace`.
     */
    public function testTheSelfieFallsBackToTheIntellicheckPayload(): void
    {
        $resolved = $this->invokeResolveImages([
            'intellicheck' => ['facial' => ['data' => ['photoFace' => 'FROM_PAYLOAD']]],
        ]);

        $this->assertSame('FROM_PAYLOAD', $resolved['face']);
        $this->assertNull($resolved['front']);
        $this->assertNull($resolved['back']);
    }

    public function testAnEmptyOrMissingImagesKeyResolvesToNulls(): void
    {
        $this->assertSame(['front' => null, 'back' => null, 'face' => null], $this->invokeResolveImages([]));
        $this->assertSame(
            ['front' => null, 'back' => null, 'face' => null],
            $this->invokeResolveImages(['images' => ['front' => '', 'face' => null]])
        );
    }

    /**
     * Mobile sends no engagement reference, so a missing `eid` has to stay a normal call — the action
     * resolves the person's existing engagement instead.
     */
    public function testAMissingEidIsNotAnErrorAndResolvesTheExistingEngagement(): void
    {
        $lead = $this->makeLead();
        $people = $lead->people;

        $existing = $this->createEngagement($lead, $people);
        $this->assertNotNull($existing);

        $this->assertNull($this->invokeActivityResolveEngagement($lead, []));
        $this->assertSame(
            $existing->getId(),
            $this->invokeActionResolveEngagement($lead, $people, null)?->getId()
        );
    }

    /**
     * Mobile uploads the licence first and sends uuids, so the same `images` key has to accept both a
     * uuid and base64 — a uuid must be linked, never re-uploaded.
     */
    public function testAFilesystemUuidIsLinkedInsteadOfUploaded(): void
    {
        $lead = $this->makeLead();
        $file = $this->makeFilesystem('drivers_license_front.jpg');

        $resolved = $this->invokeResolveFile($lead, (string) $file->uuid);

        $this->assertNotNull($resolved);
        $this->assertSame($file->getId(), $resolved->getId());
    }

    public function testAUuidThatDoesNotResolveSkipsThatSide(): void
    {
        $lead = $this->makeLead();

        $this->assertNull($this->invokeResolveFile($lead, Str::uuid()->toString()));
    }

    /**
     * The receiver's images arrive on the engagement's own message, and the folder renders the last
     * submitted child's files only — so a side left on the parent disappears from the UI.
     */
    public function testTheParentMessagesLicenceIsRelinkedOntoTheChild(): void
    {
        $lead = $this->makeLead();
        $parent = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($parent);

        $front = $this->makeFilesystem('drivers_license_front.jpg');
        $parent->message->addFile($front, 'drivers_license_front');

        $resolved = $this->invokeResolveImageFields($lead, null, $parent);

        $this->assertSame((string) $front->uuid, $resolved['drivers_license_front']);
        $this->assertNull($resolved['drivers_license_back'], 'a side the parent lacks stays unset');
    }

    public function testWhatTheCallerSendsWinsOverTheParentMessage(): void
    {
        $lead = $this->makeLead();
        $parent = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($parent);

        $parent->message->addFile($this->makeFilesystem('drivers_license_front.jpg'), 'drivers_license_front');

        $resolved = $this->invokeResolveImageFields($lead, ['front' => 'FROM_CALLER'], $parent);

        $this->assertSame('FROM_CALLER', $resolved['drivers_license_front']);
    }

    /**
     * `generate-id-verification` must never fall back to the custom field: that base64 is written by an
     * external caller and arrives late, which is the wait `AttachDriverLicenseImagesJob` and the old
     * `sleep(20)` existed for. The pre-folder-fix callers have nothing else, so they still get it.
     */
    public function testTheCustomFieldIsReadOnlyWithoutTheReuseFlag(): void
    {
        $lead = $this->makeLead();
        $lead->people->set('driver_license_images', ['front' => 'FROM_CUSTOM_FIELD']);

        $this->assertNull(
            $this->invokeResolveImageFields($lead, ['front' => null], null)['drivers_license_front'],
            'the new path opts out of the deprecated source, even when a side is null'
        );
        $this->assertSame(
            'FROM_CUSTOM_FIELD',
            $this->invokeResolveImageFields($lead, null, null, reuse: false)['drivers_license_front']
        );
    }

    /**
     * Reusing an engagement moves where a report's files land, so it stays opt-in: the callers that
     * predate the folder fix — `VinSolution\Workflow\PushCoBuyerActivity`, which has no coverage —
     * must keep creating their own engagement.
     */
    public function testWithoutTheFlagAnExistingEngagementIsNotReused(): void
    {
        $lead = $this->makeLead();
        $people = $lead->people;

        $existing = $this->createEngagement($lead, $people);
        $this->assertNotNull($existing);

        $resolved = $this->invokeActionResolveEngagement($lead, $people, null, reuse: false);

        $this->assertNotNull($resolved);
        $this->assertNotSame(
            $existing->getId(),
            $resolved->getId(),
            'the legacy callers must keep the always-create behaviour'
        );
    }

    private function invokeResolveImageFields(
        Lead $lead,
        ?array $images,
        ?Engagement $parent,
        bool $reuse = true
    ): array {
        $action = new VerifyPeopleIdAction($lead->people, $lead);

        return new ReflectionMethod(VerifyPeopleIdAction::class, 'resolveImageFields')
            ->invoke($action, $images, $parent, $reuse);
    }

    private function invokeResolveFile(Lead $lead, string $image): ?Filesystem
    {
        $action = new VerifyPeopleIdAction($lead->people, $lead);

        return new ReflectionMethod(VerifyPeopleIdAction::class, 'resolveFile')
            ->invoke($action, $image, 'drivers_license_front.jpg');
    }

    private function makeFilesystem(string $name): Filesystem
    {
        $user = auth()->user();

        $filesystem = new Filesystem();
        $filesystem->apps_id = app(Apps::class)->getId();
        $filesystem->companies_id = $user->getCurrentCompany()->getId();
        $filesystem->users_id = $user->getId();
        $filesystem->name = $name;
        $filesystem->path = 'files/id-verification/' . uniqid() . '-' . $name;
        $filesystem->url = 'https://cdn.salesassist.io/files/id-verification/' . uniqid() . '-' . $name;
        $filesystem->file_type = 'jpg';
        $filesystem->size = '1024';
        $filesystem->is_deleted = 0;
        $filesystem->saveOrFail();

        return $filesystem;
    }

    private function invokeResolveImages(array $params): array
    {
        return new ReflectionMethod(GenerateIdVerificationActivity::class, 'resolveImages')
            ->invoke($this->activity(), $params);
    }

    private function activity(): GenerateIdVerificationActivity
    {
        return new ReflectionClass(GenerateIdVerificationActivity::class)->newInstanceWithoutConstructor();
    }

    private function invokeActivityResolveEngagement(Lead $lead, array $params): ?Engagement
    {
        return new ReflectionMethod(GenerateIdVerificationActivity::class, 'resolveEngagement')
            ->invoke($this->activity(), $lead, $params);
    }

    private function invokeActivityResolvePeople(Lead $lead, Engagement $engagement, array $params): ?People
    {
        return new ReflectionMethod(GenerateIdVerificationActivity::class, 'resolvePeople')
            ->invoke($this->activity(), $lead, $engagement, $params);
    }

    private function invokeActionResolveEngagement(
        Lead $lead,
        People $people,
        ?Engagement $parent,
        bool $reuse = true
    ): ?Engagement {
        $action = new VerifyPeopleIdAction($people, $lead);

        return new ReflectionMethod(VerifyPeopleIdAction::class, 'resolveEngagement')
            ->invoke($action, $parent, $reuse);
    }

    private function createEngagement(Lead $lead, People $people): ?Engagement
    {
        $activity = new ReflectionClass(IdVerificationReportActivity::class)->newInstanceWithoutConstructor();

        return new ReflectionMethod(IdVerificationReportActivity::class, 'createIdVerificationEngagement')
            ->invoke($activity, $lead, $people);
    }

    private function findForPeople(Lead $lead, People $people): ?Engagement
    {
        return EngagementRepository::findEngagementForLeadPeople(
            $lead,
            $people,
            ConfigurationEnum::ID_VERIFICATION->value,
            ActionStatusEnum::SUBMITTED->value
        );
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

        $lead->leads_owner_id = $user->getId();
        $lead->users_id = $user->getId();
        $lead->saveQuietly();
        $lead->refresh();

        $lead->company->set('company_manager', []);

        new SyncEmailTemplateAction($app, $user)->execute(overWrite: false);

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

        $branch = $company->defaultBranch ?? $company->branch()->firstOrFail();

        CompanyAction::firstOrCreate([
            'actions_id' => $action->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'users_id' => $user->getId(),
            'companies_branches_id' => $branch->getId(),
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

    private function makePerson(Lead $lead): People
    {
        return People::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withUserId(auth()->user()->getId())
            ->create([
                'firstname' => 'Co',
                'lastname' => 'Buyer',
            ]);
    }
}
