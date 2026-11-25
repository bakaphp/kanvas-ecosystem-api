<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentReceipts\Services;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\PaymentReceipts\Enums\ConfigurationEnum;

class PaymentReceiptSettingsService
{
    /**
     * @return array{received_by: string|null, signature_url: string|null, seal_url: string|null}
     */
    public function current(Companies $company): array
    {
        return [
            'received_by' => Str::trimToNull((string) $company->get(ConfigurationEnum::RECEIVED_BY->value)),
            'signature_url' => $company->getFileByName(ConfigurationEnum::SIGNATURE_FILE->value)?->filesystem?->url,
            'seal_url' => $company->getFileByName(ConfigurationEnum::SEAL_FILE->value)?->filesystem?->url,
        ];
    }
}
