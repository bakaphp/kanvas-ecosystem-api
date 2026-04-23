<?php

declare(strict_types=1);

namespace App\GraphQL\Analytics\Queries;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class MessageAnalyticsQuery
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $rootValue, array $args): array
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = app()->bound(CompaniesBranches::class)
            ? app(CompaniesBranches::class)->company
            : $user->getCurrentCompany();

        return new BuildAnalyticsAction(
            model: Message::class,
            app: $app,
            company: $company,
            request: AnalyticsRequest::fromGraphQL($args, $company),
            groupBys: [
                'by_type' => new AnalyticsGroupBy(
                    column: 'message_types_id',
                    relation: 'messageType',
                    labelColumn: 'name',
                ),
            ],
            extraScopes: isset($args['message_type_id'])
                ? fn (Builder $q) => $q->where('message_types_id', (int) $args['message_type_id'])
                : null,
        )->execute();
    }
}
