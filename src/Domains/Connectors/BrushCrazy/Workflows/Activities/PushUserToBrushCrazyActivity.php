<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Actions\SyncSaleFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\SiteBooking;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

/**
 * BrushCrazy users are global (one account across studios) while Kanvas keeps one People per
 * studio company, so the returned user id is stamped on every unlinked People with this email in a
 * studio company. Best effort: the sale push finds or creates the same user by email anyway.
 */
#[WorkflowAction(
    name: 'BrushCrazy Push User',
    description: 'Creates or updates the site account in BrushCrazy (POST /api/kanvas/users) and links the studio People. Hang it on Users registered and updated.',
    integration: IntegrationsEnum::BRUSHCRAZY,
    requiresConfig: [ConfigurationEnum::BRUSHCRAZY_API_URL, ConfigurationEnum::BRUSHCRAZY_API_SECRET],
)]
class PushUserToBrushCrazyActivity extends BrushCrazyPushActivity
{
    /**
     * @param Users $user
     */
    #[Override]
    public function execute(Model $user, AppInterface $app, array $params): array
    {
        $paramCompany = ($params['company'] ?? null) instanceof Companies ? $params['company'] : $user->getCurrentCompany();
        $email = strtolower(trim((string) $user->email));
        $people = $email !== '' ? $this->studioPeople($app, $email) : collect();
        $company = $people->first()?->company ?? $paramCompany;

        if (! $company instanceof Companies) {
            return $this->failWorkflow(['message' => 'No company to run the BrushCrazy integration under.', 'entity' => null]);
        }

        return $this->runPush($user, $app, $company, $params, function () use ($user, $app, $email, $people, $paramCompany) {
            if ($email === '') {
                return $this->failWorkflow(['message' => 'User has no email.', 'entity' => null]);
            }

            $linked = $people->filter(fn (People $p) => ! empty($p->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value)));
            $fromSite = $people->contains(fn (People $p) => $p->get(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value) === SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS);

            if ($linked->isNotEmpty() && ! $fromSite) {
                return $this->skip('User is a mirrored BrushCrazy customer; BrushCrazy owns it.');
            }

            $studioId = ($paramCompany instanceof Companies ? SiteBooking::studioId($paramCompany) : null)
                ??($people->first() !== null ? SiteBooking::studioId($people->first()->company) : null);
            $phone = trim((string) ($user->cell_phone_number ?: $user->phone_number));

            $body = [
                'email' => $email,
                'first_name' => trim((string) $user->firstname),
                'last_name' => trim((string) $user->lastname),
                'phone' => $phone !== '' ? $phone : null,
                'studio_id' => $studioId,
            ];

            return $this->post($app, 'api/kanvas/users', $body, function (array $response) use ($people) {
                if (! empty($response['user_id'])) {
                    $people->each(fn (People $p) => SiteBooking::stampUser($p, (int) $response['user_id']));
                }

                return [
                    'message' => 'User pushed to BrushCrazy.',
                    'user_id' => $response['user_id'] ?? null,
                    'linked_people' => $people->map(fn (People $p) => $p->getId())->values()->all(),
                    'entity' => null,
                ];
            });
        });
    }

    /**
     * `registered` carries the raw password in its params; it must not land in the history row.
     */
    #[Override]
    public function buildIntegrationHistoryInput(Model $entity, array $params): array
    {
        return [
            'entity' => Arr::only($entity->toArray(), ['id', 'uuid', 'email', 'firstname', 'lastname']),
            'params' => Arr::except($params, ['password']),
        ];
    }

    /**
     * @return Collection<int, People>
     */
    private function studioPeople(AppInterface $app, string $email): Collection
    {
        return People::query()
            ->where('apps_id', $app->getId())
            ->whereHas('emails', fn ($contacts) => $contacts->where('value', $email))
            ->with('company')
            ->get()
            ->filter(fn (People $p) => $p->company !== null && SiteBooking::studioId($p->company) !== null)
            ->values();
    }
}
