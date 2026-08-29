<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Services;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

class DriverLicenseCombinedPdfService
{
    public const string COMBINED_FIELD_NAME = 'drivers_license_combined';

    private const array SIDES = [
        'drivers_license_front' => 'Driver License - Front',
        'drivers_license_back' => 'Driver License - Back',
    ];

    public function __construct(
        private readonly Message $message,
    ) {
    }

    /**
     * Idempotent: returns null when the combined PDF is already there or no side has
     * landed yet. A failure is swallowed — the merged document is a convenience artifact
     * and must never break the ID verification flow that produced it.
     */
    public function attach(
        bool $isIdValid,
        bool $isExpired,
        string $verificationMessage,
        ?string $status,
    ): ?Filesystem {
        if ($this->message->getFileByName(self::COMBINED_FIELD_NAME) !== null) {
            return null;
        }

        $pages = [];

        foreach (self::SIDES as $fieldName => $caption) {
            $side = $this->message->getFileByName($fieldName)?->filesystem;

            if ($side === null) {
                continue;
            }

            $pages[] = [
                'url' => $side->url,
                'caption' => $caption,
            ];
        }

        if ($pages === []) {
            return null;
        }

        try {
            $pdf = PdfService::imagesToPdf(
                $this->message->app,
                $this->message->user,
                $pages,
                company: $this->message->company,
                fileName: 'drivers_license_' . $this->message->getId() . '_' . uniqid() . '.pdf',
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $this->message->addFile($pdf, self::COMBINED_FIELD_NAME);

        $pdf->set('id_verify', (int) $isIdValid);
        $pdf->set('id_expired', (int) $isExpired);
        $pdf->set('id_verification_msg', $verificationMessage);

        if ($status !== null) {
            $pdf->set('id_verification_status', $status);
        }

        return $pdf;
    }
}
