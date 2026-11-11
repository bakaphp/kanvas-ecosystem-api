<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports\Concerns;

use Baka\Http\SafeUrlFetcher;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Throwable;

trait DrawsHeaderLogos
{
    private const string HEADER_LOGO_FILE_PREFIX = 'temp_payments_logo_';

    protected function headerLogoDrawings(array $logoUrls): array
    {
        $drawings = [];

        foreach (array_values($logoUrls) as $index => $logoUrl) {
            try {
                $content = SafeUrlFetcher::fetch($logoUrl);
                if ($content === '') {
                    continue;
                }

                $tempPath = storage_path('app/' . self::HEADER_LOGO_FILE_PREFIX . $index . '.png');
                file_put_contents($tempPath, $content);

                $drawing = new Drawing();
                $drawing->setName('Logo ' . ($index + 1));
                $drawing->setDescription('Header Logo');
                $drawing->setPath($tempPath);
                $drawing->setHeight(50);
                $drawing->setCoordinates(Coordinate::stringFromColumnIndex(($index * 2) + 1) . '1');

                $drawings[] = $drawing;
            } catch (Throwable) {
                continue;
            }
        }

        return $drawings;
    }

    public static function cleanupHeaderLogoFiles(): void
    {
        foreach (glob(storage_path('app/' . self::HEADER_LOGO_FILE_PREFIX . '*.png')) ?: [] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
