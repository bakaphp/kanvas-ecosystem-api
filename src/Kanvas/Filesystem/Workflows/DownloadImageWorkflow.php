<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Workflows;

use Kanvas\Filesystem\Models\Filesystem;
use Workflow\ActivityStub;
use Workflow\Workflow;
use Kanvas\Filesystem\Workflows\Activities\DownloadImageActivity;
use Baka\Contracts\AppInterface;
class DownloadImageWorkflow extends Workflow
{
    public function execute(AppInterface $app, Filesystem $filesystem)
    {
        return ActivityStub::make(DownloadImageActivity::class, $filesystem, $app, []);
    }
}
