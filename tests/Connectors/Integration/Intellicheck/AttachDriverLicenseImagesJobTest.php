<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Jobs\AttachDriverLicenseImagesJob;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseCombinedPdfService;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseVerificationService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class AttachDriverLicenseImagesJobTest extends TestCase
{
    public function testAttachesImagesWrittenAfterEngagementExistsAndClearsFields(): void
    {
        $app = app(Apps::class);
        $lead = $this->makeLead();
        $this->setUpIdVerificationEngagement($lead);

        $people = $this->makeParticipantPerson($lead);
        $message = $this->makeEngagementMessage($lead, $people);

        // Simulate the race: the base64 lands on the person AFTER the engagement exists.
        $image = $this->validImageBase64();
        $people->set('driver_license_images', ['front' => $image, 'back' => $image]);
        $people->set('intellicheckResponse', ['status' => 'flag']);

        new AttachDriverLicenseImagesJob($app, $people, $message, 'flag', 'needs review')->handle();

        $this->assertNotNull($message->getFileByName('drivers_license_front'));
        $this->assertNotNull($message->getFileByName('drivers_license_back'));

        $fresh = $people->fresh();
        $this->assertNull($fresh->get('driver_license_images'), 'DL images must be cleared after attach');
        $this->assertNull($fresh->get('intellicheckResponse'), 'intellicheckResponse must be cleared after attach');
    }

    public function testDoesNotDuplicateFilesWhenRunTwice(): void
    {
        $app = app(Apps::class);
        $lead = $this->makeLead();
        $this->setUpIdVerificationEngagement($lead);

        $people = $this->makeParticipantPerson($lead);
        $message = $this->makeEngagementMessage($lead, $people);

        $image = $this->validImageBase64();
        $people->set('driver_license_images', ['front' => $image, 'back' => $image]);
        new AttachDriverLicenseImagesJob($app, $people, $message, 'flag', 'needs review')->handle();

        // A second run (retry / concurrent flow) must not double-attach.
        $people->set('driver_license_images', ['front' => $image, 'back' => $image]);
        new AttachDriverLicenseImagesJob($app, $people, $message, 'flag', 'needs review')->handle();

        $dlFileCount = $message->files()
            ->whereIn('filesystem_entities.field_name', ['drivers_license_front', 'drivers_license_back'])
            ->count();

        $this->assertSame(2, $dlFileCount, 'front + back attached exactly once');

        $combinedCount = $message->files()
            ->where('filesystem_entities.field_name', DriverLicenseCombinedPdfService::COMBINED_FIELD_NAME)
            ->count();

        $this->assertSame(1, $combinedCount, 'combined PDF generated exactly once');
    }

    public function testGeneratesCombinedPdfWithBothDriverLicenseSides(): void
    {
        $app = app(Apps::class);
        $lead = $this->makeLead();
        $this->setUpIdVerificationEngagement($lead);

        $people = $this->makeParticipantPerson($lead);
        $message = $this->makeEngagementMessage($lead, $people);

        $image = $this->validImageBase64();
        $people->set('driver_license_images', ['front' => $image, 'back' => $image]);

        new AttachDriverLicenseImagesJob($app, $people, $message, 'green', 'passed')->handle();

        $combined = $message->getFileByName(DriverLicenseCombinedPdfService::COMBINED_FIELD_NAME);

        $this->assertNotNull($combined, 'front + back must be merged into a single PDF');
        $this->assertSame('pdf', $combined->filesystem->file_type);
        $this->assertSame('green', $combined->filesystem->get('id_verification_status'));
        $this->assertSame(1, (int) $combined->filesystem->get('id_verify'));
    }

    public function testDoesNotGenerateCombinedPdfWhenNoSideIsAttached(): void
    {
        $lead = $this->makeLead();
        $this->setUpIdVerificationEngagement($lead);

        $people = $this->makeParticipantPerson($lead);
        $message = $this->makeEngagementMessage($lead, $people);

        $this->assertNull(
            new DriverLicenseCombinedPdfService($message)->attach(true, false, 'passed', 'green')
        );
        $this->assertNull($message->getFileByName(DriverLicenseCombinedPdfService::COMBINED_FIELD_NAME));
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->company->set('company_manager', []);

        return $lead;
    }

    private function makeParticipantPerson(Lead $lead): People
    {
        return People::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->create([
                'firstname' => 'Race',
                'lastname' => 'Cobuyer',
            ]);
    }

    private function makeEngagementMessage(Lead $lead, People $people): Message
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $engagement = new DriverLicenseVerificationService($lead->app, $company, $user)
            ->createEngagement($lead, $people);

        return $engagement->message;
    }

    private function setUpIdVerificationEngagement(Lead $lead): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // the engagement status-change fires an OneSignal notification that renders
        // the engagement-status-changed template; seed it (and siblings) from the
        // blade sources so the notification path runs for real on a fresh DB.
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
    }

    private function validImageBase64(): string
    {
        $img = imagecreatetruecolor(120, 80);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));

        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return base64_encode($bytes);
    }
}
