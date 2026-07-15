<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Jobs\AttachDriverLicenseImagesJob;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
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

        $action = Action::getBySlug(ConfigurationEnum::ID_VERIFICATION->value, $company);

        CompanyAction::firstOrCreate([
            'actions_id' => $action->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'users_id' => $user->getId(),
            'companies_branches_id' => $company->defaultBranch->getId(),
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
