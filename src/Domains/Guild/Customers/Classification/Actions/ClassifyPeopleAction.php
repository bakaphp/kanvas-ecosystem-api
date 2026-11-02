<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Actions;

use Kanvas\Guild\Customers\Classification\DataTransferObject\PeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationFieldEnum as FieldEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationSourceEnum as SourceEnum;
use Kanvas\Guild\Customers\Classification\Services\PeopleClassificationSettings;
use Kanvas\Guild\Customers\Classification\Services\PeopleTitleClassifierService;
use Kanvas\Guild\Customers\Models\People;

class ClassifyPeopleAction
{
    public function __construct(private readonly People $people, private readonly bool $force = false)
    {
    }

    /**
     * Current means no model call would change anything: a person's manual choice is never
     * reclassified, even by --force, and a person with no title has nothing to classify.
     */
    public static function isCurrent(People $people, PeopleTaxonomy $taxonomy): bool
    {
        if ($people->get(FieldEnum::SOURCE->value) === SourceEnum::MANUAL->value) {
            return true;
        }

        $title = PeopleTitleClassifierService::titleOf($people);

        return $title === null
            || $people->get(FieldEnum::HASH->value) === PeopleTitleClassifierService::fingerprint($title, $taxonomy);
    }

    /**
     * @return array{status: string, level?: ?string, area?: ?string, confidence?: float, title?: string}
     */
    public function execute(): array
    {
        $settings = PeopleClassificationSettings::for($this->people->app, $this->people->company);
        $taxonomy = $settings->taxonomy;

        if ($taxonomy === null || $this->people->company === null) {
            return ['status' => 'not_configured'];
        }

        if ($this->people->get(FieldEnum::SOURCE->value) === SourceEnum::MANUAL->value) {
            return ['status' => 'manual'];
        }

        $title = PeopleTitleClassifierService::titleOf($this->people);

        if ($title === null) {
            return ['status' => 'no_title'];
        }

        $fingerprint = PeopleTitleClassifierService::fingerprint($title, $taxonomy);

        if (! $this->force && $this->people->get(FieldEnum::HASH->value) === $fingerprint) {
            return ['status' => 'unchanged'];
        }

        $result = PeopleTitleClassifierService::classify(
            $this->people->app,
            $this->people->company,
            $taxonomy,
            $title,
            $this->force,
        );

        $applies = $result['confidence'] >= $settings->minConfidence
            && ($result['level'] !== null || $result['area'] !== null);

        $this->people->set(FieldEnum::NORMALIZED_TITLE->value, $result['normalized_title']);
        $this->people->set(FieldEnum::CONFIDENCE->value, $result['confidence']);
        $this->people->set(FieldEnum::HASH->value, $fingerprint);

        if ($applies) {
            $this->people->del(FieldEnum::SUGGESTED->value);
        } else {
            $this->people->set(FieldEnum::SUGGESTED->value, [
                'level' => $result['level'],
                'area' => $result['area'],
            ]);
        }

        new ApplyPeopleClassificationAction(
            people: $this->people,
            level: $applies ? $result['level'] : null,
            area: $applies ? $result['area'] : null,
            source: $applies ? SourceEnum::AI : SourceEnum::PENDING_REVIEW,
        )->execute();

        return [
            'status' => $applies ? 'applied' : 'pending_review',
            'level' => $result['level'],
            'area' => $result['area'],
            'confidence' => $result['confidence'],
            'title' => $title,
        ];
    }
}
