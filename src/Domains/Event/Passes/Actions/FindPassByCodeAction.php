<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Hash;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Participants\Models\ParticipantPass;

class FindPassByCodeAction
{
    public function __construct(
        protected AppInterface $apps,
        protected Companies $company,
        protected string $code
    ) {
    }

    public function execute(): ?ParticipantPass
    {
        $code = strtoupper(trim($this->code));

        $lookup = new GenerateLookupAction(
            $this->apps,
            $this->company,
            $code
        )->execute();

        $pass = ParticipantPass::where('pin_lookup', $lookup)
            ->fromApp($this->apps)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if (! $pass || ! Hash::check($code, $pass->pin_hash)) {
            return null;
        }

        return $pass;
    }
}
