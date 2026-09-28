<?php

declare(strict_types=1);

namespace Kanvas\Imports\Enums;

enum ImportDriverEnum: string
{
    case FTP = 'ftp';
    case SFTP = 'sftp';

    public function defaultPort(): int
    {
        return match ($this) {
            self::FTP => 21,
            self::SFTP => 22,
        };
    }
}
