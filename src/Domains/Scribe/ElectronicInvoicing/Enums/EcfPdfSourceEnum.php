<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

use Kanvas\Companies\Models\Companies;

/**
 * Which legal PDF an accepted e-CF carries as `ecf_pdf`. WebPOS hands back a printed copy, so it stays the
 * default there until our render is checked (decision 7); Alanube's PDF is a URL we do not fetch, so ours is
 * its default.
 */
enum EcfPdfSourceEnum: string
{
    case PROVIDER = 'provider';
    case KANVAS = 'kanvas';

    public static function forCompany(Companies $company, ?string $providerKey): self
    {
        $configured = self::tryFrom(strtolower(trim((string) $company->get(ConfigurationEnum::ECF_PDF_SOURCE->value))));

        if ($configured !== null) {
            return $configured;
        }

        return $providerKey === EcfProviderEnum::ALANUBE->value ? self::KANVAS : self::PROVIDER;
    }
}
