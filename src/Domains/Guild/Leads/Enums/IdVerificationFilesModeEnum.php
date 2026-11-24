<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

use Kanvas\Companies\Models\Companies;

/**
 * Which license documents a lead's files show once an ID verification has produced both the front/back
 * images and the combined PDF of them. Only the read changes: every file stays stored and linked.
 */
enum IdVerificationFilesModeEnum: string
{
    case IMAGES = 'images';
    case PDF = 'pdf';
    case BOTH = 'both';

    public const string COMPANY_KEY = 'id_verification_files';

    public const string COMBINED = 'drivers_license_combined';

    public const array SIDES = ['drivers_license_front', 'drivers_license_back'];

    /** An unset or unrecognised value is `both`: the behaviour of a company that never set the key. */
    public static function fromCompany(Companies $company): self
    {
        $mode = $company->get(self::COMPANY_KEY);

        return is_string($mode) ? (self::tryFrom($mode) ?? self::BOTH) : self::BOTH;
    }

    /**
     * Never hides the only copy of the license: a mode drops a document only when the one it prefers is
     * in the same group, so a failed combined PDF (or a missing side) cannot leave the group empty.
     *
     * @param  array<int, array<string, mixed>>  $files  presented files, as `presentFile()` returns them
     * @return array<int, array<string, mixed>>
     */
    public function filter(array $files): array
    {
        $names = array_column($files, 'field_name');

        $hidden = match ($this) {
            self::IMAGES => array_intersect(self::SIDES, $names) !== [] ? [self::COMBINED] : [],
            self::PDF => in_array(self::COMBINED, $names, true) ? self::SIDES : [],
            self::BOTH => [],
        };

        if ($hidden === []) {
            return $files;
        }

        return array_values(array_filter(
            $files,
            fn (array $file): bool => ! in_array($file['field_name'] ?? '', $hidden, true)
        ));
    }
}
