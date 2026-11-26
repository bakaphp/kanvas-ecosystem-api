<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Documents\Services;

use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use finfo;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Kanvas\Companies\Models\Companies;
use Throwable;

/** What every printed Scribe document says about the company that issues it. */
final class DocumentIssuerBlock
{
    /**
     * Embeds the company's uploaded photo, never the app's placeholder.
     *
     * @return array{name: string, logo: ?string, address_lines: list<string>, email: ?string, phone: ?string, website: ?string}
     */
    public static function for(Companies $company): array
    {
        return [
            'name' => (string) $company->name,
            'logo' => self::imageDataUri($company->getFileByName('photo')?->filesystem?->url),
            'address_lines' => self::addressLines(['address' => $company->address ?? null]),
            'email' => $company->email ?? null,
            'phone' => $company->phone ?? null,
            'website' => $company->website ?? null,
        ];
    }

    /**
     * wkhtmltopdf cannot display AVIF/WebP, so inspect the bytes rather than trusting an upload's filename and
     * convert unsupported formats to PNG.
     */
    public static function imageDataUri(?string $url): ?string
    {
        $url = Str::trimToNull($url);
        if ($url === null) {
            return null;
        }

        try {
            $bytes = SafeUrlFetcher::fetch($url);
            $mime = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($bytes);
            if (! str_starts_with($mime, 'image/')) {
                return null;
            }

            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'], true)) {
                $bytes = (string) new ImageManager(new Driver())->decodeBinary($bytes)->encodeUsingMediaType('image/png');
                $mime = 'image/png';
            }

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param array<array-key, mixed>|null $address
     *
     * @return list<string>
     */
    public static function addressLines(?array $address): array
    {
        if ($address === null) {
            return [];
        }

        $cityLine = implode(', ', array_filter([
            trim((string) ($address['city'] ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($address['state'] ?? '')),
                trim((string) ($address['zip'] ?? '')),
            ]))),
        ]));

        return array_values(array_filter([
            trim((string) ($address['address'] ?? '')),
            trim((string) ($address['address_2'] ?? '')),
            $cityLine,
            trim((string) ($address['country'] ?? '')),
        ], static fn (string $line): bool => $line !== ''));
    }
}
