<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Event\Passes\Enums\PassScanSourceEnum;
use Override;

class ParticipantPassScan extends BaseModel
{
    protected $table = 'participant_pass_scans';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'result' => PassScanResultEnum::class,
            'source' => PassScanSourceEnum::class,
            'scanned_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }
}
