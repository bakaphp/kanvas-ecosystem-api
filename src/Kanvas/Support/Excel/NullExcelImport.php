<?php

declare(strict_types=1);

namespace Kanvas\Support\Excel;

use Maatwebsite\Excel\Concerns\ToArray;

// No-op import target for Excel::toArray() calls that only want the raw rows back, not a mapped import.
class NullExcelImport implements ToArray
{
    public function array(array $array): void
    {
    }
}
