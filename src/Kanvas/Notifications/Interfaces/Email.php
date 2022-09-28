<?php
declare(strict_types=1);

namespace Kanvas\Notifications\Interfaces;

interface Email {
    public function generateHtml(): string;
    public function getDataMail(): array;
}