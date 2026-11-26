<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\DataTransferObject;

use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Enums\InviteCodeCheckReasonEnum;

final readonly class InviteCodeCheck
{
    public function __construct(
        public InviteCodeCheckReasonEnum $reason,
        public ?ParticipantPass $pass = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->reason === InviteCodeCheckReasonEnum::VALID;
    }
}
