<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentSubmission;

/**
 * Finds the fiscal row a provider is answering about. Always the exact row it was sent as — never the entity's
 * current one — so an answer about a superseded number lands in that number's history only.
 */
final class EcfDocumentLookup
{
    /**
     * A systemRef is the attempt's `system_ref`, or the uuid of an attempt sent before that column existed; a
     * row sent before attempts carried their own was sent under the fiscal row's uuid, so that still resolves.
     */
    public static function bySystemRef(AppInterface $app, CompanyInterface $company, string $systemRef): ?FiscalDocument
    {
        $attempt = self::attemptBySystemRef($app, $company, $systemRef);

        if ($attempt !== null) {
            return $attempt->fiscalDocument;
        }

        /** @var FiscalDocument|null $document */
        $document = FiscalDocument::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('uuid', $systemRef)
            ->first();

        return $document;
    }

    public static function attemptBySystemRef(AppInterface $app, CompanyInterface $company, string $systemRef): ?FiscalDocumentSubmission
    {
        if ($systemRef === '') {
            return null;
        }

        /** @var FiscalDocumentSubmission|null $attempt */
        $attempt = FiscalDocumentSubmission::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where(fn (Builder $query) => $query->where('system_ref', $systemRef)->orWhere('uuid', $systemRef))
            ->first();

        return $attempt;
    }

    /**
     * Received documents carry the vendor's e-NCF and vendors number their own sequences, so only our issued
     * rows match by number.
     */
    public static function byProviderReference(
        AppInterface $app,
        CompanyInterface $company,
        ?string $providerReference,
        ?string $encf
    ): ?FiscalDocument {
        if ($providerReference !== null) {
            /** @var FiscalDocumentSubmission|null $submission */
            $submission = FiscalDocumentSubmission::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->where('provider_ref', $providerReference)
                ->latest('id')
                ->first();

            if ($submission !== null) {
                return $submission->fiscalDocument;
            }
        }

        if ($encf === null) {
            return null;
        }

        /** @var FiscalDocument|null $document */
        $document = FiscalDocument::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('direction', FiscalDirectionEnum::ISSUED->value)
            ->where('number', $encf)
            ->latest('id')
            ->first();

        return $document;
    }
}
