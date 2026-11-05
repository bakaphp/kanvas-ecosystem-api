<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Classification\Actions\ApplyPeopleClassificationAction;
use Kanvas\Guild\Customers\Classification\DataTransferObject\PeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationFieldEnum as FieldEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationSourceEnum as SourceEnum;
use Kanvas\Guild\Customers\Classification\Services\PeopleClassificationSettings;
use Kanvas\Guild\Customers\Classification\Services\PeopleTitleClassifierService;
use Kanvas\Guild\Customers\Models\People;

class PeopleClassificationMutation
{
    use ResolvesActingContext;

    /**
     * @return array{level: ?string, area: ?string, confidence: float, normalized_title: string}
     */
    public function suggest(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $settings = PeopleClassificationSettings::for($ctx->app, $ctx->company);

        if (! $settings->isActive()) {
            throw new ValidationException('People classification is not enabled for this company.');
        }

        return PeopleTitleClassifierService::classify(
            $ctx->app,
            $ctx->company,
            $settings->taxonomy,
            trim($request['title']),
        );
    }

    public function set(mixed $rootValue, array $request): People
    {
        $ctx = $this->actingContext();
        $taxonomy = PeopleClassificationSettings::for($ctx->app, $ctx->company)->taxonomy;

        if ($taxonomy === null) {
            throw new ValidationException('This company has no people classification taxonomy.');
        }

        /** @var People $people */
        $people = People::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        $level = $request['level'] ?? $people->get(FieldEnum::LEVEL->value);
        $area = $request['area'] ?? $people->get(FieldEnum::AREA->value);
        $this->assertInTaxonomy($taxonomy, $level, $area);

        $people->del(FieldEnum::SUGGESTED->value);

        new ApplyPeopleClassificationAction(
            people: $people,
            level: $level,
            area: $area,
            source: SourceEnum::MANUAL,
            user: $ctx->user,
        )->execute();

        return $people;
    }

    private function assertInTaxonomy(PeopleTaxonomy $taxonomy, ?string $level, ?string $area): void
    {
        if ($level === null && $area === null) {
            throw new ValidationException('Provide a level or an area.');
        }

        if ($level !== null && ! $taxonomy->hasLevel($level)) {
            throw new ValidationException("Unknown level: {$level}.");
        }

        if ($area !== null && ! $taxonomy->hasArea($area)) {
            throw new ValidationException("Unknown area: {$area}.");
        }
    }
}
