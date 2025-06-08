<?php

declare(strict_types=1);

namespace Baka\Validations;

use Baka\Support\Str;
use DateTime;
use Exception;

class Date
{
    /**
     * Is validate date?
     */
    public static function isValid(?string $date, string $format = 'Y-m-d'): bool
    {
        if ($date === null || empty($date)) {
            return false;
        }

        $format = trim($format);

        $d = DateTime::createFromFormat($format, $date);

        return $d && $d->format($format) === $date;
    }

    public static function explodeFileStringBasedOnDelimiter(string $value): array
    {
        $delimiter = match (true) {
            Str::contains($value, '|') => '|',
            Str::contains($value, ',') => ',',
            Str::contains($value, ';') => ';',
            default => '|',
        };

        $fileLinks = explode($delimiter, $value);

        return array_map(function ($fileLink) {
            $fileLink = trim($fileLink);
            $cleanedUrl = Str::before($fileLink, '?');

            return [
                'url' => $fileLink,
                'name' => basename($cleanedUrl),
            ];
        }, $fileLinks);
    }

    public static function isValidDate(string $dateString): bool
    {
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $dateString) ?:
                DateTime::createFromFormat('Y-m-d', $dateString) ?:
                DateTime::createFromFormat('m/d/Y', $dateString) ?:
                DateTime::createFromFormat('d/m/Y', $dateString) ?:
                DateTime::createFromFormat('m/d/y', $dateString) ?:
                DateTime::createFromFormat('d-m-Y', $dateString) ?:
                DateTime::createFromFormat('Y-m-d', $dateString) ?:
                DateTime::createFromFormat('j/n/Y', $dateString);

        return $date !== false;
    }

    public static function createFromFormat(string $dateString): ?string
    {
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $dateString) ?:
                DateTime::createFromFormat('Y-m-d', $dateString) ?:
                DateTime::createFromFormat('m/d/Y', $dateString) ?:
                DateTime::createFromFormat('d/m/Y', $dateString) ?:
                DateTime::createFromFormat('m/d/y', $dateString) ?:
                DateTime::createFromFormat('d-m-Y', $dateString) ?:
                DateTime::createFromFormat('j/n/Y', $dateString);

        if (! $date) {
            $timestamp = strtotime($dateString);
            if ($timestamp !== false) {
                return $timestamp;
            } else {
                throw new Exception('Invalid date format');
            }
        }

        return $date->format('Y-m-d H:i:s');
    }
}
