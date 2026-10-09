<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Insurance\DataTransferObject\Insurer as InsurerData;
use Kanvas\Insurance\Models\Insurer;

class UpdateInsurerAction
{
    public function __construct(
        protected readonly Insurer $insurer,
        protected readonly InsurerData $data,
    ) {
    }

    public function execute(): Insurer
    {
        return DB::connection('commerce')->transaction(function (): Insurer {
            $this->insurer->name = $this->data->name;
            $this->insurer->provider = $this->data->provider;
            $this->insurer->logo = $this->data->logo;
            $this->insurer->status = $this->data->status;
            $this->insurer->saveOrFail();

            return $this->insurer;
        });
    }
}
