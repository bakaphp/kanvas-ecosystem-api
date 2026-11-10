<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Kanvas\Filesystem\Services\FileContentsReaderService;

/** The banking name for the shared reader; tests bind a fake under it. */
class BankStatementFileReaderService extends FileContentsReaderService
{
}
