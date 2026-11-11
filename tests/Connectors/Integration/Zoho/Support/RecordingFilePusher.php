<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho\Support;

use Kanvas\Connectors\Zoho\Actions\PushFilesToZohoRecordAction;

final class RecordingFilePusher extends PushFilesToZohoRecordAction
{
    public array $skipIdsSeen = [];

    public function execute(iterable $files, array $skipIds = [], array $fileNames = []): array
    {
        $this->skipIdsSeen = $skipIds;
        $uploaded = [];

        foreach ($files as $file) {
            if (! in_array($file->getId(), $skipIds)) {
                $uploaded[] = $file->getId();
            }
        }

        return ['uploaded' => $uploaded, 'failed' => []];
    }
}
