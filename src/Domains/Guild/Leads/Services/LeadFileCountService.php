<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Kanvas\Guild\Leads\Models\Lead;

/**
 * `file-count` is jitsubai's per-lead counter, one `{name, count}` entry per file-name base. A number is
 * reserved before the file exists and the counter is unlocked: a concurrent writer can still collide.
 */
class LeadFileCountService
{
    public const string FILE_COUNT_FIELD = 'file-count';

    public function __construct(
        protected Lead $lead
    ) {
    }

    /** `<base>-<next number>.<extension>`, the way every Kanvas-made file of a lead is named. */
    public function reserveName(string $base, string $extension): string
    {
        return $base . '-' . $this->reserve($base) . '.' . $extension;
    }

    /** The next number of `$base`; the other bases' entries are written back untouched. */
    public function reserve(string $base): int
    {
        $counts = $this->lead->get(self::FILE_COUNT_FIELD);
        $counts = is_array($counts) ? $counts : [];
        $names = array_map(fn ($entry) => $entry['name'] ?? null, $counts);
        $index = array_search($base, $names, true);

        if ($index === false) {
            $count = 1;
            $counts[] = ['name' => $base, 'count' => $count];
        } else {
            $count = (int) ($counts[$index]['count'] ?? 0) + 1;
            $counts[$index]['count'] = $count;
        }

        $this->lead->set(self::FILE_COUNT_FIELD, $counts);

        return $count;
    }
}
