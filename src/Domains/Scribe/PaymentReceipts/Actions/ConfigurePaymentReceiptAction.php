<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentReceipts\Actions;

use Baka\Support\Str;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Enums\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\PaymentReceipts\Enums\ConfigurationEnum;
use Kanvas\Scribe\PaymentReceipts\Services\PaymentReceiptSettingsService;
use Kanvas\Users\Models\Users;

/**
 * Sets what every payment receipt of the company prints: the name under "Recibido por", the signature and the
 * seal. A field left null keeps its current value; an empty name clears it.
 */
class ConfigurePaymentReceiptAction
{
    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly ?string $receivedBy = null,
        protected readonly ?UploadedFile $signature = null,
        protected readonly ?UploadedFile $seal = null,
        protected readonly PaymentReceiptSettingsService $settings = new PaymentReceiptSettingsService(),
    ) {
    }

    /**
     * @return array{received_by: string|null, signature_url: string|null, seal_url: string|null}
     */
    public function execute(): array
    {
        $this->assertImage($this->signature);
        $this->assertImage($this->seal);

        if ($this->receivedBy !== null) {
            $this->saveReceivedBy(Str::trimToNull($this->receivedBy));
        }

        $this->attachImage($this->signature, ConfigurationEnum::SIGNATURE_FILE);
        $this->attachImage($this->seal, ConfigurationEnum::SEAL_FILE);

        return $this->settings->current($this->company);
    }

    private function saveReceivedBy(?string $name): void
    {
        if ($name === null) {
            $this->company->del(ConfigurationEnum::RECEIVED_BY->value);

            return;
        }

        $this->company->set(ConfigurationEnum::RECEIVED_BY->value, $name);
    }

    private function assertImage(?UploadedFile $file): void
    {
        $allowed = AllowedFileExtensionEnum::ONLY_IMAGES->getAllowedExtensions();

        if ($file !== null && ! in_array($file->extension(), $allowed, true)) {
            throw new ValidationException('Invalid file format ' . $file->extension());
        }
    }

    private function attachImage(?UploadedFile $file, ConfigurationEnum $fieldName): void
    {
        if ($file === null) {
            return;
        }

        $filesystem = new FilesystemServices($this->app, $this->company)->upload($file, $this->user);

        new AttachFilesystemAction($filesystem, $this->company)->execute($fieldName->value);
    }
}
