<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Traits;

use Illuminate\Support\Str;

trait TruncatesTitleTrait
{
    public function setTitleAttribute(?string $value): void
    {
        $this->attributes['title'] = $value !== null && mb_strlen($value) > 255
            ? Str::limit($value, 254, '…')
            : $value;
    }
}
