<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Enums;

enum OneDriveDocumentTypeEnum: string
{
    case WORD = 'word';
    case EXCEL = 'excel';

    public function extension(): string
    {
        return match ($this) {
            self::WORD => 'docx',
            self::EXCEL => 'xlsx',
        };
    }

    public static function fromFileName(string $name): ?self
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        foreach (self::cases() as $case) {
            if ($case->extension() === $extension) {
                return $case;
            }
        }

        return null;
    }
}
