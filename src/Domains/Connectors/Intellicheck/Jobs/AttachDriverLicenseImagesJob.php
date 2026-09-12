<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseCombinedPdfService;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Messages\Models\Message;

/**
 * @deprecated Exists only to wait for the `people.driver_license_images` base64 to be written by an
 *             external caller, which is a race `after-id-verification` created and nothing else has.
 *             `generate-id-verification` takes the images from the engagement's message (receiver) or
 *             the `images` param (mobile), both available at call time. Delete this job with the old
 *             verb. See `VerifyPeopleIdAction::customFieldImages()`.
 */
class AttachDriverLicenseImagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public function __construct(
        public readonly Apps $app,
        public readonly People $people,
        public readonly Message $message,
        public readonly string $status,
        public readonly string $verificationMessage,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $images = $this->people->get('driver_license_images');

        if (! is_array($images) || (empty($images['front']) && empty($images['back']))) {
            // Base64 not written yet — wait for the next attempt, give up quietly on the last.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);
            }

            return;
        }

        $isIdValid = in_array($this->status, ['green', 'flag'], true);
        $isExpired = $this->status === 'flag';
        $filesystemService = new FilesystemServices($this->people->app, $this->people->company);

        foreach (['back' => 'drivers_license_back', 'front' => 'drivers_license_front'] as $side => $field) {
            if (empty($images[$side]) || $this->message->getFileByName($field) !== null) {
                continue;
            }

            $file = $filesystemService->createFileSystemFromBase64(
                $images[$side],
                $field . '.jpg',
                $this->people->user
            );
            $this->message->addFile($file, $field);

            $file->set('id_verify', (int) $isIdValid);
            $file->set('id_expired', (int) $isExpired);
            $file->set('id_verification_msg', $this->verificationMessage);
            $file->set('id_verification_status', $this->status);
        }

        $frontDone = empty($images['front']) || $this->message->getFileByName('drivers_license_front') !== null;
        $backDone = empty($images['back']) || $this->message->getFileByName('drivers_license_back') !== null;

        if ($frontDone && $backDone) {
            new DriverLicenseCombinedPdfService($this->message)->attach(
                $isIdValid,
                $isExpired,
                $this->verificationMessage,
                $this->status,
            );

            $this->people->del('driver_license_images');
            $this->people->del('get_docs_drivers_license');
            $this->people->del('intellicheckResponse');
        }
    }
}
