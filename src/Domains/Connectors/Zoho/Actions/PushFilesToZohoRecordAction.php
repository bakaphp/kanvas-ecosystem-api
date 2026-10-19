<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\Filesystem;
use Throwable;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

class PushFilesToZohoRecordAction
{
    public function __construct(
        protected ZohoRecordsModule $module,
        protected string $recordId,
    ) {
    }

    /**
     * @param iterable<Filesystem> $files
     * @param int[] $skipIds filesystem ids already on the Zoho record
     *
     * @return array{uploaded: int[], failed: array<int, string>}
     */
    public function execute(iterable $files, array $skipIds = []): array
    {
        $uploaded = [];
        $failed = [];

        foreach ($files as $file) {
            if (in_array($file->getId(), $skipIds)) {
                continue;
            }

            try {
                $this->module->uploadAttachment($this->recordId, $file->name, SafeUrlFetcher::fetch($file->url));

                $uploaded[] = $file->getId();
            } catch (Throwable $e) {
                $failed[$file->getId()] = $e->getMessage();
            }
        }

        return [
            'uploaded' => $uploaded,
            'failed' => $failed,
        ];
    }
}
