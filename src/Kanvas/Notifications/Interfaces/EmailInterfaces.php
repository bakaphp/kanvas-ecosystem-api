<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Interfaces;

interface EmailInterfaces
{
    public function getData(): array;

    public function message(): string;
}
