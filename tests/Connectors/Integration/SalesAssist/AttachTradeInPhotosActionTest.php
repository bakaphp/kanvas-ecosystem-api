<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssist;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\AttachTradeInPhotosAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class AttachTradeInPhotosActionTest extends TestCase
{
    public function testAttachesEveryUploadedPhotoUnderItsOwnFieldName(): void
    {
        $odometer = $this->makeFilesystem('odometer.jpg');
        $front = $this->makeFilesystem('front.jpg');
        $back = $this->makeFilesystem('back.jpg');
        $message = $this->makeMessage([
            ['label' => 'Vehicle Identification Number (VIN)', 'value' => '1abcd23efgh456789'],
            ['label' => 'Upload odometer photo', 'value' => (string) $odometer->getId()],
            ['label' => 'Upload vehicle photo', 'value' => $front->getId() . ',' . $back->getId()],
        ]);

        $this->assertSame(3, new AttachTradeInPhotosAction($message)->execute());

        $this->assertSame($odometer->getId(), $message->getFileByName('upload_odometer_photo')->filesystem_id);
        $this->assertSame($front->getId(), $message->getFileByName('upload_vehicle_photo_1')->filesystem_id);
        $this->assertSame($back->getId(), $message->getFileByName('upload_vehicle_photo_2')->filesystem_id);
    }

    public function testIgnoresPhotosFromAnotherCompany(): void
    {
        $foreign = $this->makeFilesystem('foreign.jpg', companyId: 999999);
        $message = $this->makeMessage([
            ['label' => 'Upload vehicle photo', 'value' => (string) $foreign->getId()],
        ]);

        $action = new AttachTradeInPhotosAction($message);

        $this->assertTrue($action->photos()->isEmpty());
        $this->assertSame(0, $action->execute());
    }

    public function testRunningTwiceDoesNotDuplicateFiles(): void
    {
        $photo = $this->makeFilesystem('vehicle.jpg');
        $message = $this->makeMessage([
            ['label' => 'Upload vehicle photo', 'value' => (string) $photo->getId()],
        ]);

        new AttachTradeInPhotosAction($message)->execute();
        new AttachTradeInPhotosAction($message)->execute();

        $this->assertSame(1, $message->files()->where('filesystem_entities.field_name', 'upload_vehicle_photo')->count());
    }

    private function makeMessage(array $data): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create([
                'message' => [
                    'verb' => 'add-trade',
                    'status' => 'submitted',
                    'data' => $data,
                ],
            ]);
    }

    private function makeFilesystem(string $name, ?int $companyId = null): Filesystem
    {
        $user = auth()->user();

        $filesystem = new Filesystem();
        $filesystem->apps_id = app(Apps::class)->getId();
        $filesystem->companies_id = $companyId ?? $user->getCurrentCompany()->getId();
        $filesystem->users_id = $user->getId();
        $filesystem->name = $name;
        $filesystem->path = 'files/trade-in/' . uniqid() . '-' . $name;
        $filesystem->url = 'https://example.com/files/trade-in/' . uniqid() . '-' . $name;
        $filesystem->file_type = 'jpg';
        $filesystem->size = '1024';
        $filesystem->is_deleted = 0;
        $filesystem->saveOrFail();

        return $filesystem;
    }
}
