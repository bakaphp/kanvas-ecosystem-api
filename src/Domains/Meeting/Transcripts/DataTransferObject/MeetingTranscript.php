<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Transcripts\DataTransferObject;

use Kanvas\Meeting\Meetings\Models\Meeting;
use Spatie\LaravelData\Data;

class MeetingTranscript extends Data
{
    public function __construct(
        public readonly Meeting $meeting,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $providerJobId = null,
    ) {
    }
}
