<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Services;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;

/**
 * Pairs uploaded files with people by file name: `PA40876.pdf` belongs to the person whose custom
 * field (`participant_id`, say) reads `PA40876`. The agent uploads the folder and names the field;
 * it never writes the pairs by hand, which is what keeps a 200-person list inside one tool call.
 */
class CampaignAttachmentMatcherService
{
    /**
     * @param  Collection<int, People>  $people
     * @param  Collection<int, Filesystem>  $files
     * @return array{matched: array<int, list<string>>, unmatched_people: list<int>, unmatched_files: list<string>}
     */
    public function match(Collection $people, Collection $files, string $field): array
    {
        $urlsByKey = [];
        foreach ($files as $file) {
            $key = $this->fileKey($file);
            if ($key === '') {
                continue;
            }
            $urlsByKey[$key][] = $file->url;
        }

        $matched = [];
        $unmatchedPeople = [];
        $claimed = [];
        foreach ($people as $person) {
            $key = Str::lowerTrim((string) $person->get($field));
            if ($key === '' || ! isset($urlsByKey[$key])) {
                $unmatchedPeople[] = $person->getId();

                continue;
            }
            $matched[$person->getId()] = $urlsByKey[$key];
            $claimed[$key] = true;
        }

        $unmatchedFiles = [];
        foreach ($files as $file) {
            if (! isset($claimed[$this->fileKey($file)])) {
                $unmatchedFiles[] = $file->name;
            }
        }

        return [
            'matched' => $matched,
            'unmatched_people' => $unmatchedPeople,
            'unmatched_files' => $unmatchedFiles,
        ];
    }

    private function fileKey(Filesystem $file): string
    {
        return Str::lowerTrim(pathinfo($file->name, PATHINFO_FILENAME));
    }
}
