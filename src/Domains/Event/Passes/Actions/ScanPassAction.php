<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Enums\PassFormatEnum;
use Kanvas\Exceptions\ValidationException;

class ScanPassAction
{
    public function __construct(
        protected AppInterface $apps,
        protected Companies $company,
        protected string $pin,
        protected PassFormatEnum $format = PassFormatEnum::NUMERIC_PIN
    ) {
    }

    public function execute(): ParticipantPass
    {
        $pass = new FindPassByCodeAction(
            $this->apps,
            $this->company,
            $this->pin
        )->execute();

        if (! $pass) {
            throw new ValidationException('Invalid PIN code.');
        }

        if (now()->gt($pass->expiration_date)) {
            throw new ValidationException('PIN code has expired.');
        }

        if ($pass->used_date !== null) {
            throw new ValidationException('PIN code has already been used.');
        }

        return $pass;
    }
}
