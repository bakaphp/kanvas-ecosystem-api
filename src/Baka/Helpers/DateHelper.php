<?php

declare(strict_types=1);

namespace Baka\Helpers;

class DateHelper
{
    public static function detectDateFormat(string $dateString): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return 'Y-m-d';
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateString)) {
            return 'm/d/Y';
        } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dateString)) {
            return 'm-d-Y';
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $dateString)) {
            return 'm/d/y'; // short year format
        }
        // Add more patterns as needed

        return null;
    }
}
