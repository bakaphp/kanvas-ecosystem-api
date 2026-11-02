<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrl;
use Illuminate\Support\Facades\Cache;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Notifications\Support\SignatureHtml;
use Kanvas\Users\Models\Users;

/**
 * A previewed signature design is kept server-side and approved by id, so what gets saved is byte for byte
 * what the person looked at — the model never re-sends the HTML it was shown, and cannot slip a different
 * one in at approval. Drafts are keyed to the person and the company they were made for.
 *
 * Requires HasKanvasContext and ReportsToolOutcome on the host.
 */
trait DraftsEmailSignatures
{
    private const array SIGNATURE_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const int SIGNATURE_DRAFT_TTL_SECONDS = 86400;

    /**
     * @return string|array<string, mixed> the clean HTML, or the payload to return verbatim
     */
    protected function sanitizedSignatureOrError(string $html): string|array
    {
        $imageUrls = [];

        foreach (SignatureHtml::imageFileIds($html) as $filesystemId) {
            $url = $this->signatureImageUrlOrError($filesystemId);

            if (is_array($url)) {
                return $url;
            }

            $imageUrls[$filesystemId] = $url;
        }

        try {
            return SignatureHtml::sanitize($html, $imageUrls);
        } catch (ValidationException $e) {
            return $this->invalidArgs(
                $e->getMessage(),
                ['saved' => false],
                guidance: 'Fix that design and try again; nothing was saved.',
            );
        }
    }

    protected function storeSignatureDraft(Users $caller, string $html): string
    {
        $draftId = bin2hex(random_bytes(5));

        Cache::put($this->signatureDraftKey($caller, $draftId), $html, self::SIGNATURE_DRAFT_TTL_SECONDS);

        return $draftId;
    }

    protected function signatureDraft(Users $caller, string $draftId): ?string
    {
        $html = Cache::get($this->signatureDraftKey($caller, trim($draftId)));

        return is_string($html) ? $html : null;
    }

    private function signatureDraftKey(Users $caller, string $draftId): string
    {
        return sprintf(
            'email-signature-draft:%d:%d:%s',
            $caller->getId(),
            $this->company->getId(),
            $draftId
        );
    }

    /**
     * A file of this tenant that is an image, by the type stored from its bytes at upload. The URL is
     * fetched by the preview renderer and by every recipient's mail client, so it is also held to the SSRF
     * guard.
     *
     * @return string|array<string, mixed>
     */
    private function signatureImageUrlOrError(int $filesystemId): string|array
    {
        try {
            $file = Filesystem::getByIdFromCompanyApp($filesystemId, $this->company, $this->app);
        } catch (ModelNotFoundException) {
            return $this->notFound(
                ['saved' => false, 'filesystem_id' => $filesystemId],
                guidance: 'Use the filesystem_id from the "Attached file on this message" marker of the image they sent.',
            );
        }

        if (! in_array(strtolower((string) $file->file_type), self::SIGNATURE_IMAGE_TYPES, true)) {
            return $this->invalidArgs(
                sprintf('File %d is a %s, not an image.', $filesystemId, $file->file_type),
                ['saved' => false],
                guidance: 'Ask them for a JPG, PNG, GIF or WEBP image.',
            );
        }

        try {
            SafeUrl::assertSafe((string) $file->url);
        } catch (SsrfException) {
            return $this->invalidArgs(
                sprintf('File %d cannot be used in an email.', $filesystemId),
                ['saved' => false],
                guidance: 'Ask them to upload the image again.',
            );
        }

        return (string) $file->url;
    }
}
