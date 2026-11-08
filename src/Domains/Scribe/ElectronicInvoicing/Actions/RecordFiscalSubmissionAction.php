<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\EcfProviderInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalSubmissionStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ProviderRejectedException;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\PollEcfResultJob;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Services\FiscalPayloadService;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Kanvas\Scribe\ElectronicInvoicing\Services\FiscalArtifactService;
use Throwable;

/**
 * One send of a fiscal row:
 *  1. the provider's exact wire payload is stored as the request artifact — if that fails, nothing is sent;
 *  2. under the row lock, the row is re-read and must still be sendable (a draft, or a rejection DGII left
 *     reusable, with no other attempt in flight); otherwise this is a no-op and returns null;
 *  3. the attempt row is inserted (and, outside a caller's transaction, committed) before the call.
 *
 * Every attempt goes out under its own uuid as systemRef, so a resend after a reusable rejection is a document
 * the provider has not seen rather than a duplicate it answers with the old rejection.
 *
 * Outcomes:
 *  - provider refused it (never reached the authority): row back to draft, same number, fix and resend;
 *  - transport failure (timeout, 5xx): row `unknown`, resolved only by asking the provider by this attempt's
 *    systemRef — never by sending a new number;
 *  - an answer: applied through ApplyFiscalAnswerAction.
 *
 * The row freezes at the first attempt that may have reached the authority; until then the snapshot follows
 * the document, so a provider-validation fix is reflected in it.
 */
class RecordFiscalSubmissionAction
{
    public function __construct(
        public readonly FiscalDocument $fiscalDocument,
        protected ?EcfProviderInterface $provider = null,
        protected ?FiscalArtifactService $artifacts = null,
    ) {
        $this->artifacts ??= app(FiscalArtifactService::class);
    }

    public function execute(): ?FiscalDocumentSubmission
    {
        $fiscal = $this->fiscalDocument;
        $attempt = new FiscalDocumentSubmission()->generateUuidIfMissing();
        $document = FiscalPayloadService::forFiscal($fiscal, $attempt->uuid);
        $provider = $this->provider ??= new EcfProviderResolver()->resolveFor($fiscal);

        $request = $this->artifacts->store(
            $fiscal,
            (string) json_encode($provider->requestPayload($document)),
            'fiscal-' . $fiscal->number . '-request.json'
        );

        $attempt = $this->startAttempt(
            $fiscal,
            $attempt,
            $document,
            $request
        );

        if ($attempt === null) {
            return null;
        }

        try {
            $answer = $provider->submit($document);
        } catch (ProviderRejectedException $e) {
            return $this->providerRejected(
                $fiscal,
                $attempt,
                [$e->getMessage()],
                null
            );
        } catch (Throwable $e) {
            report($e);

            return $this->unknown($fiscal, $attempt, $e);
        }

        $this->storeResponse($fiscal, $attempt, $answer);

        if (! $answer->reachedAuthority) {
            return $this->providerRejected(
                $fiscal,
                $attempt,
                $answer->messages,
                $answer
            );
        }

        $this->freeze($fiscal);

        new ApplyFiscalAnswerAction(
            fiscalDocument: $fiscal,
            answer: $answer,
            submission: $attempt,
        )->execute();

        return $attempt->refresh();
    }

    /**
     * @param array{file_id: int, sha256: string} $request
     */
    private function startAttempt(
        FiscalDocument $fiscal,
        FiscalDocumentSubmission $attempt,
        FiscalPayloadInterface $document,
        array $request
    ): ?FiscalDocumentSubmission {
        return DB::connection('accounting')->transaction(function () use ($fiscal, $attempt, $document, $request): ?FiscalDocumentSubmission {
            /** @var FiscalDocument $locked */
            $locked = FiscalDocument::query()->whereKey($fiscal->getKey())->lockForUpdate()->firstOrFail();

            $sendable = $locked->status === FiscalDocumentStatusEnum::DRAFT || $locked->isReusable();
            $inFlight = $locked->submissions()->where('status', FiscalSubmissionStatusEnum::SENDING->value)->exists();

            if (! $sendable || $inFlight) {
                $fiscal->setRawAttributes($locked->getAttributes(), true);

                return null;
            }

            $attempt->fill([
                'apps_id' => $locked->apps_id,
                'companies_id' => $locked->companies_id,
                'fiscal_document_id' => $locked->getKey(),
                'attempt' => (int) $locked->submissions()->max('attempt') + 1,
                'channel' => 'sync',
                'status' => FiscalSubmissionStatusEnum::SENDING,
                'provider' => $locked->provider,
                'sent_at' => Carbon::now(),
                'request_file_id' => $request['file_id'],
                'request_sha256' => $request['sha256'],
            ]);
            $attempt->save();

            if ($locked->frozen_at === null) {
                $locked->fill($fiscal->regime()->snapshot($document));
            }

            $locked->status = FiscalDocumentStatusEnum::SENDING;
            $locked->authority_number_consumed = null;
            $locked->mergeAuthorityData(['action_required' => null, 'submission_failed' => null, 'submission_error' => null]);
            $locked->save();

            $fiscal->setRawAttributes($locked->getAttributes(), true);

            return $attempt;
        });
    }

    /**
     * @param string[] $messages
     */
    private function providerRejected(
        FiscalDocument $fiscal,
        FiscalDocumentSubmission $attempt,
        array $messages,
        ?EcfSubmission $answer
    ): FiscalDocumentSubmission {
        $attempt->status = FiscalSubmissionStatusEnum::PROVIDER_REJECTED;
        $attempt->messages = $messages;
        $attempt->provider_ref = $answer?->providerReference;
        $attempt->responded_at = Carbon::now();
        $attempt->save();

        $fiscal->status = FiscalDocumentStatusEnum::DRAFT;
        $fiscal->mergeAuthorityData(['messages' => $messages, 'action_required' => 'fix_and_resubmit_ecf']);
        $fiscal->save();

        return $attempt;
    }

    public static function markUnknown(FiscalDocument $fiscal, FiscalDocumentSubmission $attempt, string $reason): void
    {
        $attempt->status = FiscalSubmissionStatusEnum::UNKNOWN;
        $attempt->messages = [$reason];
        $attempt->save();

        $fiscal->frozen_at ??= Carbon::now();
        $fiscal->status = FiscalDocumentStatusEnum::UNKNOWN;
        $fiscal->mergeAuthorityData(['submission_error' => $reason]);
        $fiscal->save();

        if ($fiscal->isCurrent()) {
            PollEcfResultJob::schedule($fiscal, 1);
        }
    }

    private function unknown(FiscalDocument $fiscal, FiscalDocumentSubmission $attempt, Throwable $e): FiscalDocumentSubmission
    {
        self::markUnknown($fiscal, $attempt, $e->getMessage());

        return $attempt;
    }

    /**
     * The answer is already in hand; a storage failure must not lose it, so it is reported and the hash kept.
     */
    private function storeResponse(FiscalDocument $fiscal, FiscalDocumentSubmission $attempt, EcfSubmission $answer): void
    {
        $raw = (string) json_encode($answer->rawResponse ?? $answer->except('pdfBase64')->toArray());
        $attempt->response_sha256 = hash('sha256', $raw);

        try {
            $attempt->response_file_id = $this->artifacts->store(
                $fiscal,
                $raw,
                'fiscal-' . $fiscal->number . '-response-' . $attempt->attempt . '.json'
            )['file_id'];
        } catch (Throwable $e) {
            report($e);
        }

        $attempt->save();
    }

    private function freeze(FiscalDocument $fiscal): void
    {
        if ($fiscal->frozen_at === null) {
            $fiscal->frozen_at = Carbon::now();
            $fiscal->save();
        }
    }
}
