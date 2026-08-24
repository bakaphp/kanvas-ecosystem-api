<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Activities\AttachIdVerificationFilesToMessageActivity;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class AttachIdVerificationFilesToMessageActivityTest extends TestCase
{
    use HasIntegrationCompany;

    public function testAttachesIdVerificationFilesToTheMessage(): void
    {
        $app = app(Apps::class);
        $this->setUpInternalIntegration();

        $front = $this->makeFilesystem('front.jpg');
        $back = $this->makeFilesystem('back.jpg');
        $message = $this->makeMessage($this->idVerificationPayload($front, $back));

        $result = $this->activity()->execute($message, $app, []);

        $this->assertTrue($result['result']);
        $this->assertNotNull($message->getFileByName('drivers_license_front'));
        $this->assertNotNull($message->getFileByName('drivers_license_back'));
        $this->assertSame(
            $front->getId(),
            $message->getFileByName('drivers_license_front')->filesystem_id
        );
        $this->assertSame(
            $back->getId(),
            $message->getFileByName('drivers_license_back')->filesystem_id
        );
    }

    public function testDoesNotDuplicateFilesWhenRunTwice(): void
    {
        $app = app(Apps::class);
        $this->setUpInternalIntegration();

        $front = $this->makeFilesystem('front.jpg');
        $back = $this->makeFilesystem('back.jpg');
        $message = $this->makeMessage($this->idVerificationPayload($front, $back));

        $this->activity()->execute($message, $app, []);
        $this->activity()->execute($message, $app, []);

        $attachedCount = $message->files()
            ->whereIn('filesystem_entities.field_name', ['drivers_license_front', 'drivers_license_back'])
            ->count();

        $this->assertSame(2, $attachedCount);
    }

    public function testSkipsMessagesWithoutFilesOnThePayload(): void
    {
        $app = app(Apps::class);
        $this->setUpInternalIntegration();

        $message = $this->makeMessage([
            'text' => 'ID Verification',
            'verb' => 'id-verification',
            'data' => [],
        ]);

        $result = $this->activity()->execute($message, $app, []);

        $this->assertFalse($result['result']);
        $this->assertSame(0, $message->files()->count());
    }

    private function activity(): AttachIdVerificationFilesToMessageActivity
    {
        return new AttachIdVerificationFilesToMessageActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
    }

    private function setUpInternalIntegration(): void
    {
        $user = auth()->user();

        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::INTERNAL,
            'Kanvas\\Connectors\\Internal\\Handlers\\InternalHandler',
            $user->getCurrentCompany(),
            $user
        );
    }

    private function makeMessage(array $content): Message
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['message' => $content]);
    }

    private function makeFilesystem(string $name): Filesystem
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $filesystem = new Filesystem();
        $filesystem->apps_id = $app->getId();
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

    private function idVerificationPayload(Filesystem $front, Filesystem $back): array
    {
        return [
            'text' => 'ID Verification',
            'verb' => 'id-verification',
            'hashtagVisited' => 'id-verification',
            'status' => 'opened',
            'data' => [
                [
                    'type' => [
                        'id' => 0,
                        'name' => 'Drivers License',
                        'description' => 'Please upload the front and back of your Driver’s License',
                    ],
                    'files' => [
                        $this->payloadFile($front),
                        $this->payloadFile($back),
                    ],
                ],
            ],
        ];
    }

    private function payloadFile(Filesystem $filesystem): array
    {
        return [
            'id' => $filesystem->getId(),
            'companies_id' => $filesystem->companies_id,
            'apps_id' => $filesystem->apps_id,
            'users_id' => $filesystem->users_id,
            'name' => $filesystem->name,
            'path' => $filesystem->path,
            'url' => $filesystem->url,
            'size' => $filesystem->size,
            'file_type' => 'jpg',
            'attributes' => [
                'verb' => 'id-verification',
                'visitor_id' => 'b943c5e9-bbef-4b84-9bbc-716fc6d873e1',
            ],
        ];
    }
}
