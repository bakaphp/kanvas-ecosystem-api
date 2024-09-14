<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class FilesystemImport extends Data
{
    public function __construct(
        public Apps $app,
        public Users $users,
        public Companies $companies,
        public Regions $regions,
        public CompaniesBranches $companiesBranches,
        public Filesystem $filesystem,
        public FilesystemMapper $filesystemMapper
    ) {
    }
}
