<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Filters;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

class EngagementLeadFilter
{
    /**
     * @return array{lead_ids: list<int>, exclude: bool, matching_engagements: int, slugs: list<string>}
     */
    public function resolve(
        Apps $app,
        Companies $company,
        string $action,
        string $completion
    ): array {
        $completion = strtolower(trim($completion));
        if (! in_array($completion, ['started', 'incomplete', 'submitted', 'missing'], true)) {
            throw new InvalidArgumentException('Engagement completion must be started, incomplete, submitted, or missing.');
        }

        $slugs = $this->actionSlugs($action);
        $latest = $this->engagements($app, $company, $slugs)
            ->sortByDesc(fn (Engagement $engagement): string => sprintf(
                '%s-%010d',
                (string) $engagement->created_at,
                $engagement->getId(),
            ))
            ->unique(fn (Engagement $engagement): string => $engagement->entity_uuid !== ''
                ? $engagement->slug . ':' . $engagement->entity_uuid
                : $engagement->slug . ':id:' . $engagement->getId())
            ->values();

        $matching = match ($completion) {
            'started', 'missing' => $latest,
            'submitted' => $latest->filter(fn (Engagement $engagement): bool => $engagement->stage?->slug === ActionStatusEnum::SUBMITTED->value),
            'incomplete' => $latest->reject(fn (Engagement $engagement): bool => $engagement->stage?->slug === ActionStatusEnum::SUBMITTED->value),
        };

        return [
            'lead_ids' => $matching->pluck('leads_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
            'exclude' => $completion === 'missing',
            'matching_engagements' => $matching->count(),
            'slugs' => $slugs,
        ];
    }

    /** @param list<string> $slugs */
    protected function engagements(Apps $app, Companies $company, array $slugs): Collection
    {
        return Engagement::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('is_deleted', 0)
            ->whereIn('slug', $slugs)
            ->with('stage')
            ->get();
    }

    /** @return list<string> */
    private function actionSlugs(string $action): array
    {
        $action = strtolower(trim($action));

        return match ($action) {
            'trade', 'trade-in', 'trade_in', ActionEnum::ADD_TRADE->value => [ActionEnum::ADD_TRADE->value],
            'credit', 'credit-app', 'credit_app' => [
                ActionEnum::CREDIT_APP->value,
                ActionEnum::CREDIT_APP_2->value,
                ActionEnum::CREDIT_APP_3->value,
                ActionEnum::CREDIT_APP_4->value,
                ActionEnum::CREDIT_APP_5->value,
                ActionEnum::CREDIT_APP_6->value,
                ActionEnum::CREDIT_APP_7->value,
                ActionEnum::BUSINESS_CREDIT_APP->value,
            ],
            '' => throw new InvalidArgumentException('Provide an engagement action or alias.'),
            default => [$action],
        };
    }
}
