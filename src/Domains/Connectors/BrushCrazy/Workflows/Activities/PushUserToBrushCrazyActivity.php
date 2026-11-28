<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\AccountSync;
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
 *
 * Both ways: a change made on the site (sign-up, profile, password reset or change) is pushed here;
 * a change made in BrushCrazy comes back through the customer mirror. AccountSync settles which
 * side changed, and each side writes the other's value quietly, so nothing loops.
 */
#[WorkflowAction(
    name: 'BrushCrazy Push User',
    description: 'Keeps the customer\'s BrushCrazy account in step with the site (POST /api/kanvas/users): name, phone and password hash, and links the studio People. Hang it on Users registered, updated and after-forgot-password.',
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

        return $this->runPush(
            $user,
            $app,
            $company,
            $params,
            function () use ($user, $app, $email, $people, $paramCompany) {
                if ($email === '') {
                    return $this->failWorkflow(['message' => 'User has no email.', 'entity' => null]);
                }

                $profile = $user->getAppProfile($app);
                $hash = AccountSync::sitePasswordHash($user, $profile);
                $site = AccountSync::siteProfile($user, $profile);

                $password = AccountSync::passwordFingerprint($hash);
                $profileFingerprint = AccountSync::profileFingerprint(...$site);
                $brushCrazyUserId = (int) ($user->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value) ?? 0);

                // `updated` fires for any change to the user (balances, last login...): only a
                // change to what BrushCrazy keeps is worth a call. The echo of BrushCrazy's own
                // change (applied quietly) never gets here.
                if ($brushCrazyUserId > 0
                    && $user->get(CustomFieldEnum::BRUSHCRAZY_SYNCED_PROFILE->value) === $profileFingerprint
                    && ($password === null || $user->get(CustomFieldEnum::BRUSHCRAZY_SYNCED_PASSWORD->value) === $password)) {
                    return $this->skip('Account already in step with BrushCrazy.');
                }

                $studioId = ($paramCompany instanceof Companies ? SiteBooking::studioId($paramCompany) : null)
                    ?? ($people->first() !== null ? SiteBooking::studioId($people->first()->company) : null);

                $body = [
                    'email' => $email,
                    'user_id' => $brushCrazyUserId > 0 ? $brushCrazyUserId : null,
                    'first_name' => $site['firstname'],
                    'last_name' => $site['lastname'],
                    'phone' => $site['phone'],
                    'studio_id' => $studioId,
                    // A bcrypt hash both systems check; never the password itself.
                    'password_hash' => $password !== null ? $hash : null,
                ];

                return $this->post(
                    $app,
                    'api/kanvas/users',
                    $body,
                    function (array $response) use ($people, $user, $password, $profileFingerprint) {
                        if (! empty($response['user_id'])) {
                            $people->each(fn (People $p) => SiteBooking::stampUser($p, (int) $response['user_id']));
                        }

                        // BrushCrazy now has this account: remember it as agreed (quietly, so this
                        // write is not another `updated` push).
                        $user->disableWorkflows();
                        if (! empty($response['user_id'])) {
                            $user->set(CustomFieldEnum::BRUSHCRAZY_USER_ID->value, (int) $response['user_id']);
                        }
                        $user->set(CustomFieldEnum::BRUSHCRAZY_SYNCED_PROFILE->value, $profileFingerprint, false);
                        if ($password !== null) {
                            $user->set(CustomFieldEnum::BRUSHCRAZY_SYNCED_PASSWORD->value, $password, false);
                        }

                        return [
                            'message' => 'User pushed to BrushCrazy.',
                            'user_id' => $response['user_id'] ?? null,
                            'linked_people' => $people->map(fn (People $p) => $p->getId())->values()->all(),
                            'entity' => null,
                        ];
                    }
                );
            }
        );
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
