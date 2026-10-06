<?php

declare(strict_types=1);

namespace Kanvas\Users\Models\Concerns;

trait HasDisplayLabel
{
    public function displayLabel(): ?string
    {
        $name = trim(($this->firstname ?? '') . ' ' . ($this->lastname ?? ''));

        return $name !== '' ? $name : ($this->displayname ?: $this->email);
    }
}
