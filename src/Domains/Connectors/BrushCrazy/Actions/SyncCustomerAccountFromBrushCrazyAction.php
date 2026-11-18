<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CustomerAccountMapper;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\UserConfig;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * The customer's Kanvas login, one per BrushCrazy user (BrushCrazy accounts are global, unlike the
 * People copies kept per studio). The site signs customers in as Users and reads their points,
 * Paint Money and VIP balance, and their order history, off that User.
 *
 * Off until BRUSHCRAZY_CUSTOMER_ACCOUNTS_BRANCH_ID names the customers branch.
 *
 * An email already registered in this app is someone who signed up on the new site: their password
 * and profile are theirs and are never overwritten, only the BrushCrazy balances are refreshed.
 */
class SyncCustomerAccountFromBrushCrazyAction
{
    private const int LOCK_SECONDS = 30;

    private const int LOCK_WAIT_SECONDS = 10;

    /** Public: the site reads them off `me.custom_fields`. */
    private const array PUBLIC_FIELDS = [
        CustomerAccountMapper::REWARD_POINTS_FIELD,
        CustomerAccountMapper::PAINT_MONEY_FIELD,
        CustomerAccountMapper::GIFT_CARD_BALANCE_FIELD,
    ];

    protected ?CompaniesBranches $branch = null;

    protected bool $branchResolved = false;

    public function __construct(
        protected AppInterface $app,
        protected ?Client $client = null,
        ?CompaniesBranches $branch = null,
    ) {
        if ($branch !== null) {
            $this->branch = $branch;
            $this->branchResolved = true;
        }
    }

    /**
     * BrushCrazy user id => Kanvas user id, for the users this app already has a login for.
     *
     * @param  array<int, int>  $bcUserIds
     * @return array<int, int>
     */
    public static function userIdsFor(AppInterface $app, array $bcUserIds): array
    {
        if ($bcUserIds === []) {
            return [];
        }

        return UserConfig::query()
            ->join('users_associated_apps', 'users_associated_apps.users_id', '=', 'user_config.users_id')
            ->where('users_associated_apps.apps_id', $app->getId())
            ->where('users_associated_apps.is_deleted', 0)
            ->where('user_config.name', CustomFieldEnum::BRUSHCRAZY_USER_ID->value)
            ->whereIn('user_config.value', array_map('strval', $bcUserIds))
            ->pluck('user_config.users_id', 'user_config.value')
            ->mapWithKeys(fn ($usersId, $bcUserId) => [(int) $bcUserId => (int) $usersId])
            ->all();
    }

    public function enabled(): bool
    {
        return $this->branch() !== null;
    }

    public function fromRow(object $row): ?Users
    {
        $branch = $this->branch();

        if ($branch === null) {
            return null;
        }

        // A caller holding a partial row would otherwise create the login with no password hash.
        if (! property_exists($row, 'password')) {
            $row = $this->client()->table('users')->where('id', (int) $row->id)->first();
        }

        $mapped = $row !== null ? CustomerAccountMapper::map($row) : null;

        if ($mapped === null) {
            return null;
        }

        $user = Cache::lock('brushcrazy-account:' . $this->app->getId() . ':' . $mapped['email'], self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn () => $this->upsertUser($mapped, $branch));

        $this->writeFields($user, $mapped['custom_fields']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function upsertUser(array $mapped, CompaniesBranches $branch): Users
    {
        $existing = Users::notDeleted()->where('email', $mapped['email'])->first();

        if ($existing !== null && $this->belongsToApp($existing)) {
            return $existing;
        }

        $creator = new CreateImportedUserAction(
            new RegisterInput(
                firstname: $mapped['firstname'],
                lastname: $mapped['lastname'],
                displayname: $mapped['displayname'],
                email: $mapped['email'],
                // An account BrushCrazy made by itself has no usable hash; a random one keeps the
                // login closed until the customer sets a password through "forgot password".
                password: $mapped['password_hash'] ?? Hash::make(Str::random(40)),
                branch: $branch,
                phone_number: $mapped['phone'],
            ),
            $this->appModel(),
        );

        if ($existing !== null) {
            return $creator->joinApp($existing);
        }

        $user = $creator->execute();

        if ($mapped['dob'] !== null) {
            $user->dob = $mapped['dob'];
            $user->saveOrFail();
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function writeFields(Users $user, array $fields): void
    {
        $user->disableWorkflows();

        foreach ($fields as $key => $value) {
            if ($value === null) {
                if ($user->get($key) !== null) {
                    $user->del($key);
                }

                continue;
            }

            $current = $user->get($key);

            if ($current === null || $current != $value) {
                $user->set($key, $value, in_array($key, self::PUBLIC_FIELDS, true));
            }
        }
    }

    protected function belongsToApp(Users $user): bool
    {
        try {
            UsersRepository::belongsToThisApp($user, $this->appModel());

            return true;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    protected function branch(): ?CompaniesBranches
    {
        if (! $this->branchResolved) {
            $branchId = $this->app->get(ConfigurationEnum::BRUSHCRAZY_CUSTOMER_ACCOUNTS_BRANCH_ID->value);

            // Only a numeric id: a uuid compared to an integer id is cast by MySQL to its leading
            // digits and would silently pick some other tenant's branch.
            $this->branch = is_numeric($branchId)
                ? CompaniesBranches::where('id', (int) $branchId)->where('is_deleted', 0)->first()
                : null;
            $this->branchResolved = true;
        }

        return $this->branch;
    }

    protected function client(): Client
    {
        return $this->client ??= new Client($this->app);
    }

    protected function appModel(): Apps
    {
        return $this->app instanceof Apps ? $this->app : Apps::getById($this->app->getId());
    }
}
