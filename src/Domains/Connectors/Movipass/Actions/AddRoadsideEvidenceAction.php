<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideEvidencePhaseEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Support\RoadsideEvidenceRules;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * Technician evidence (spec v6.0 §9.3): photos before and after the work, and the operative comment.
 * Nothing here deletes or replaces: a correction is one more record, which is why the counts in
 * RoadsideEvidenceRules only ever grow. The "before" set opens once the PIN is validated and the
 * "after" set only once the "before" minimum is met, matching the APK's blocking screens.
 *
 * Camera-only capture is enforced by the APK; the server keeps the `source` it reports so an upload
 * from elsewhere is visible in the record.
 */
class AddRoadsideEvidenceAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $technician,
        private readonly ?RoadsideEvidencePhaseEnum $phase = null,
        private readonly array $fileUuids = [],
        private readonly ?string $comment = null,
        private readonly ?string $source = null,
    ) {
    }

    public function execute(): array
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);

        if ((int) ($assistanceCase['mechanic']['user_id'] ?? 0) !== $this->technician->getId()) {
            throw new ValidationException('You are not assigned to this order');
        }

        if (RoadsideCaseStateEnum::tryFrom((string) ($assistanceCase['state'] ?? ''))?->isTerminal()) {
            throw new ValidationException('The case is closed; evidence can no longer be added');
        }

        $now = Carbon::now()->toISOString();
        $uuids = array_values(array_filter(array_map(static fn ($uuid) => Str::trimToNull((string) $uuid), $this->fileUuids)));

        if ($this->phase !== null && $uuids !== []) {
            $this->guardPhase($assistanceCase);

            new AttachRoadsideAssistancePhotosAction()->execute(
                $this->order,
                $uuids,
                $this->order->app,
                'assistance_evidence_' . $this->phase->value,
            );

            foreach ($uuids as $uuid) {
                $assistanceCase['evidence'][] = [
                    'phase' => $this->phase->value,
                    'file_uuid' => $uuid,
                    'source' => $this->source ?? 'camera',
                    'technician_id' => $this->technician->getId(),
                    'at' => $now,
                ];
            }
        }

        $comment = Str::trimToNull($this->comment);

        if ($comment !== null) {
            $assistanceCase['operative_comments'][] = [
                'comment' => $comment,
                'technician_id' => $this->technician->getId(),
                'at' => $now,
            ];
        }

        if ($uuids === [] && $comment === null) {
            throw new ValidationException('Send at least one photo or the operative comment');
        }

        $this->saveAssistanceCase($this->order, $assistanceCase);

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'evidence',
            action: 'evidence_added',
            actor: $this->technician,
            after: [
                'phase' => $this->phase?->value,
                'photos' => count($uuids),
                'comment' => $comment !== null,
            ],
            channel: RoadsideAuditChannelEnum::APK,
        );

        return RoadsideEvidenceRules::summary($assistanceCase);
    }

    private function guardPhase(array $assistanceCase): void
    {
        if (empty($assistanceCase['pin_validated_at'])) {
            throw new ValidationException('Evidence opens once the PIN is validated');
        }

        if ($this->phase !== RoadsideEvidencePhaseEnum::AFTER) {
            return;
        }

        $required = RoadsideEvidenceRules::requiredForCase($assistanceCase);

        if (RoadsideEvidenceRules::countFor($assistanceCase, RoadsideEvidencePhaseEnum::BEFORE) < $required) {
            throw new ValidationException(sprintf('Take the %d "before" photos first', $required));
        }
    }
}
