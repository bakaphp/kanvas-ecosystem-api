<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CustomerAccountMapper;
use Kanvas\Connectors\BrushCrazy\Support\AccountSync;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\UserConfig;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * The customer's Kanvas login, one per BrushCrazy user (BrushCrazy accounts are global, unlike the
 * People copies kept per studio). The site signs customers in as Users and reads their points,
 * Paint Money and VIP balance, and their order history, off that User.
 *
 * Off until BRUSHCRAZY_CUSTOMER_ACCOUNTS_BRANCH_ID names the customers branch.
 *
 * A login that already exists takes a password or profile change made in BrushCrazy only when the
 * site has not changed it since the two last agreed (AccountSync); balances are always refreshed.
 */
class SyncCustomerAccountFromBrushCrazyAction
{
    private const int LOCK_SECONDS = 30;

    /** How BrushCrazy marks an account the site created there (KanvasCustomers::REGISTRATION_METHOD). */
    private const string SITE_REGISTRATION_METHOD = 'Kanvas: website';

    private const int LOCK_WAIT_SECONDS = 10;

    /** Public: the site reads them off `me.custom_fields`. */
    private const array PUBLIC_FIELDS = [
        CustomerAccountMapper::REWARD_POINTS_FIELD,
        CustomerAccountMapper::PAINT_MONEY_FIELD,
        CustomerAccountMapper::GIFT_CARD_BALANCE_FIELD,
    ];

    protected ?CompaniesBranches $branch = null;

    protected bool $branchResolved = false;

    /** @var array<string, Users|null> email => user, for the chunk preload() was given; null = none */
    protected array $preloadedUsers = [];

    /** @var array<int, true> ids of preloaded users already in this app */
    protected array $preloadedMembers = [];

    /** @var array<int, UsersAssociatedApps> user id => their profile in this app */
    protected array $preloadedProfiles = [];

    /** @var array<int, array<string, mixed>> user id => the settings this mirror writes (settingNames()) */
    protected array $preloadedSettings = [];

    /** @var array<int, int>|null BrushCrazy user id => linked login id, for the chunk; null = no preload */
    protected ?array $preloadedLinked = null;

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

    /**
     * One chunk's existing users and their membership of this app in two queries, instead of two
     * per customer. A customer missing from the preload (the webhook path, or one created since)
     * is still looked up one by one.
     *
     * @param  array<int, object>  $rows
     */
    public function preload(array $rows): void
    {
        $this->preloadedUsers = [];
        $this->preloadedMembers = [];
        $this->preloadedProfiles = [];
        $this->preloadedSettings = [];
        $this->preloadedLinked = null;

        if (! $this->enabled()) {
            return;
        }

        $emails = [];
        $bcUserIds = [];

        foreach ($rows as $row) {
            $email = Str::lower(trim((string) ($row->email ?? '')));

            if ($email !== '') {
                $emails[$email] = true;
            }

            if (isset($row->id)) {
                $bcUserIds[] = (int) $row->id;
            }
        }

        // Logins linked to these BrushCrazy users, for a customer whose email changed there.
        $this->preloadedLinked = self::userIdsFor($this->app, $bcUserIds);

        if ($emails === []) {
            return;
        }

        $this->preloadedUsers = array_fill_keys(array_keys($emails), null);

        foreach (Users::notDeleted()->whereIn('email', array_keys($emails))->get() as $user) {
            $this->preloadedUsers[Str::lower((string) $user->email)] = $user;
        }

        $userIds = array_map(
            fn (Users $user) => $user->getKey(),
            array_values(array_filter($this->preloadedUsers))
        );

        // The app profiles (login password and name) and the mirror's settings, one query each for
        // the whole chunk, so a customer with nothing new costs no query at all. A login has one
        // row per company it joined; only the global one (getAppProfile()) holds the password the
        // site checks.
        $memberships = UsersAssociatedApps::query()
            ->whereIn('users_id', $userIds)
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->get();
        $this->preloadedMembers = $memberships
            ->mapWithKeys(fn (UsersAssociatedApps $profile) => [(int) $profile->users_id => true])
            ->all();
        $this->preloadedProfiles = $memberships
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->keyBy(fn (UsersAssociatedApps $profile) => (int) $profile->users_id)
            ->all();

        // get() goes to the database on every Redis miss, and on a first import every key misses.
        $this->preloadedSettings = array_fill_keys($userIds, []);
        UserConfig::query()
            ->whereIn('users_id', $userIds)
            ->whereIn('name', $this->settingNames($rows))
            ->get(['users_id', 'name', 'value'])
            ->each(function (UserConfig $config) {
                $this->preloadedSettings[(int) $config->users_id][$config->name] = Str::jsonToArray($config->value);
            });
    }

    /**
     * What the mirror writes on a login: the mapper's fields and the agreed fingerprints.
     *
     * @param  array<int, object>  $rows
     * @return array<int, string>
     */
    protected function settingNames(array $rows): array
    {
        $names = [
            CustomFieldEnum::BRUSHCRAZY_SYNCED_PASSWORD->value,
            CustomFieldEnum::BRUSHCRAZY_SYNCED_PROFILE->value,
        ];

        foreach ($rows as $row) {
            $mapped = CustomerAccountMapper::map($row);

            if ($mapped !== null) {
                return [...$names, ...array_keys($mapped['custom_fields'])];
            }
        }

        return $names;
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
            ->block(
                self::LOCK_WAIT_SECONDS,
                fn () => $this->withoutMembershipRecounts(fn () => $this->upsertUser($mapped, $branch))
            );

        $this->writeFields($user, $mapped['custom_fields']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function upsertUser(array $mapped, CompaniesBranches $branch): Users
    {
        $existing = array_key_exists($mapped['email'], $this->preloadedUsers)
            ? $this->preloadedUsers[$mapped['email']]
            : Users::notDeleted()->where('email', $mapped['email'])->first();

        // Not found by email: the customer may have changed it in BrushCrazy. Their login is the
        // one linked to this BrushCrazy user, not a second account.
        if ($existing === null) {
            $bcUserId = (int) ($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_USER_ID->value] ?? 0);
            $linkedId = $this->preloadedLinked !== null
                ? ($this->preloadedLinked[$bcUserId] ?? null)
                : (self::userIdsFor($this->app, [$bcUserId])[$bcUserId] ?? null);
            $existing = $linkedId !== null ? Users::notDeleted()->find($linkedId) : null;
        }

        if ($existing !== null && $this->belongsToApp($existing)) {
            $this->mergeFromBrushCrazy($existing, $mapped);

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

        // Always written: CreateUserAction stamps today's date, which the site shows as the
        // customer's birthday month. No month at the source means no dob, not the import date.
        // Quietly: a plain save fires the `updated` workflow, which pushes the user to BrushCrazy.
        $user->dob = $mapped['dob'];
        $user->saveQuietly();

        // A login made just now has none of the mirror's settings: nothing to read back. And a
        // second BrushCrazy user with the same email later in the chunk finds this one, not the
        // preload's "no user".
        $this->preloadedSettings[(int) $user->getKey()] = [];
        $this->preloadedUsers[$mapped['email']] = $user;
        $this->preloadedMembers[(int) $user->getKey()] = true;

        // Both sides start out agreeing. Without this, a password the customer later changes on the
        // site looks like a stale copy of BrushCrazy's, and the next mirror run puts the old one back.
        $this->rememberAgreed($user, array_filter([
            CustomFieldEnum::BRUSHCRAZY_SYNCED_PASSWORD->value => AccountSync::passwordFingerprint($mapped['password_hash'] ?? null),
            CustomFieldEnum::BRUSHCRAZY_SYNCED_PROFILE->value => $this->brushCrazyProfile($mapped),
        ]));

        return $user;
    }

    /**
     * Brings a change made in BrushCrazy (password, name, phone) to the customer's existing login,
     * unless the login changed on the site since the two last agreed (then the site's push carries
     * its own value to BrushCrazy instead). See AccountSync. Written quietly: no `updated`
     * workflow, so nothing is pushed back. Nothing is written when the two already agree.
     *
     * @param  array<string, mixed>  $mapped
     */
    protected function mergeFromBrushCrazy(Users $user, array $mapped): void
    {
        $userId = (int) $user->getKey();
        $profile = $this->preloadedProfiles[$userId] ?? $user->getAppProfile($this->app);
        $agreedPassword = Str::trimToNull((string) $this->setting($user, CustomFieldEnum::BRUSHCRAZY_SYNCED_PASSWORD->value));
        $agreedProfile = Str::trimToNull((string) $this->setting($user, CustomFieldEnum::BRUSHCRAZY_SYNCED_PROFILE->value));

        // An account the site created in BrushCrazy (its push) is the site's own until the two agree.
        $ownedByBrushCrazy = ($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_REGISTRATION_METHOD->value] ?? null)
            !== self::SITE_REGISTRATION_METHOD;
        $newAgreed = [];

        $brushCrazyHash = $mapped['password_hash'] ?? null;
        $kanvasPassword = AccountSync::passwordFingerprint(AccountSync::sitePasswordHash($user, $profile));
        $brushCrazyPassword = AccountSync::passwordFingerprint($brushCrazyHash);

        if ($brushCrazyPassword !== null) {
            if ($kanvasPassword === null || AccountSync::brushCrazyWins(
                $kanvasPassword,
                $brushCrazyPassword,
                $agreedPassword,
                $ownedByBrushCrazy
            )) {
                $profile->password = $brushCrazyHash;
                $profile->saveQuietly();
                // The global record too, as resetPassword() does: it seeds other apps' logins.
                $user->password = $brushCrazyHash;
                $newAgreed[CustomFieldEnum::BRUSHCRAZY_SYNCED_PASSWORD->value] = $brushCrazyPassword;
            } elseif ($kanvasPassword === $brushCrazyPassword && $agreedPassword !== $kanvasPassword) {
                $newAgreed[CustomFieldEnum::BRUSHCRAZY_SYNCED_PASSWORD->value] = $kanvasPassword;
            }
        }

        $kanvasProfile = AccountSync::profileFingerprint(...AccountSync::siteProfile($user, $profile));
        $brushCrazyProfile = $this->brushCrazyProfile($mapped);

        if (trim($mapped['firstname'] . $mapped['lastname']) !== ''
            && AccountSync::brushCrazyWins(
                $kanvasProfile,
                $brushCrazyProfile,
                $agreedProfile,
                $ownedByBrushCrazy
            )) {
            $user->firstname = $mapped['firstname'];
            $user->lastname = $mapped['lastname'];

            // A new email in BrushCrazy (found by the linked user id): the login follows it,
            // unless another login already has that address.
            if (mb_strtolower((string) $user->email) !== $mapped['email']
                && ! Users::notDeleted()->where('email', $mapped['email'])->whereKeyNot($user->getKey())->exists()) {
                $user->email = $mapped['email'];
            }

            if ($mapped['phone'] !== null) {
                $user->cell_phone_number = $mapped['phone'];
            }

            if ($profile->firstname !== null || $profile->lastname !== null) {
                $profile->firstname = $mapped['firstname'];
                $profile->lastname = $mapped['lastname'];
                $profile->saveQuietly();
            }

            // What the login holds now, not BrushCrazy's value: a phone BrushCrazy cleared or an
            // email another login has stays here, and agreeing on BrushCrazy's value would make the
            // site's next push send the old one back over BrushCrazy's change.
            $applied = AccountSync::profileFingerprint(...AccountSync::siteProfile($user, $profile));

            if ($applied !== $agreedProfile) {
                $newAgreed[CustomFieldEnum::BRUSHCRAZY_SYNCED_PROFILE->value] = $applied;
            }
        } elseif ($kanvasProfile === $brushCrazyProfile && $agreedProfile !== $kanvasProfile) {
            $newAgreed[CustomFieldEnum::BRUSHCRAZY_SYNCED_PROFILE->value] = $kanvasProfile;
        }

        if ($user->isDirty()) {
            $user->saveQuietly();
        }

        $this->rememberAgreed($user, $newAgreed);
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function brushCrazyProfile(array $mapped): string
    {
        return AccountSync::profileFingerprint(
            $mapped['firstname'],
            $mapped['lastname'],
            $mapped['phone'],
            $mapped['email']
        );
    }

    /**
     * Private and without workflows, so remembering what was agreed pushes nothing.
     *
     * @param  array<string, string>  $fingerprints
     */
    protected function rememberAgreed(Users $user, array $fingerprints): void
    {
        if ($fingerprints === []) {
            return;
        }

        $user->disableWorkflows();

        foreach ($fingerprints as $key => $value) {
            $this->putSetting($user, $key, $value, false);
        }
    }

    /**
     * One of settingNames(): from the chunk preload when the login is in it, else get().
     */
    protected function setting(Users $user, string $key): mixed
    {
        $settings = $this->preloadedSettings[(int) $user->getKey()] ?? null;

        return $settings !== null ? ($settings[$key] ?? null) : $user->get($key);
    }

    /**
     * Keeps the preload in step, so a second row for the same login in the chunk compares against
     * what was just written.
     */
    protected function putSetting(Users $user, string $key, mixed $value, bool $isPublic): void
    {
        if ($value === null) {
            $user->del($key);
        } else {
            $user->set($key, $value, $isPublic);
        }

        if (isset($this->preloadedSettings[(int) $user->getKey()])) {
            $this->preloadedSettings[(int) $user->getKey()][$key] = $value;
        }
    }

    /**
     * Every new app membership and company assignment queues a recount of the app's whole user and
     * company totals (UsersAssociatedAppsObserver, UsersAssociatedCompaniesObserver). For ~96k
     * imported logins that is ~96k jobs each counting up to ~96k users. Those listeners are
     * taken off for the duration of one login and put back after it; the totals are display
     * figures the observers recompute from scratch on the next ordinary sign-up.
     *
     * The membership's `updated` goes too: assigning the role writes `user_role` on the row just
     * created, and the queued observer would spend a job per login deciding it has nothing to do.
     *
     * Only these events, only in this process: Model::withoutEvents() would also drop the
     * `creating` hook that gives the user its uuid.
     */
    protected function withoutMembershipRecounts(Closure $create): Users
    {
        $dispatcher = Model::getEventDispatcher();

        if ($dispatcher === null) {
            return $create();
        }

        $events = [
            'eloquent.created: ' . UsersAssociatedApps::class,
            'eloquent.updated: ' . UsersAssociatedApps::class,
            'eloquent.created: ' . UserCompanyApps::class,
        ];
        $saved = [];

        // #[ObservedBy] registers on first boot: a model not yet booted would add its observer
        // back in the middle of the create, after the listeners were taken off.
        new UsersAssociatedApps();
        new UserCompanyApps();

        foreach ($events as $event) {
            $saved[$event] = $dispatcher->getRawListeners()[$event] ?? [];
            $dispatcher->forget($event);
        }

        try {
            return $create();
        } finally {
            foreach ($saved as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $dispatcher->listen($event, $listener);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function writeFields(Users $user, array $fields): void
    {
        $user->disableWorkflows();

        foreach ($fields as $key => $value) {
            $current = $this->setting($user, $key);

            if ($value === null ? $current !== null : ($current === null || $current != $value)) {
                $this->putSetting($user, $key, $value, in_array($key, self::PUBLIC_FIELDS, true));
            }
        }
    }

    protected function belongsToApp(Users $user): bool
    {
        if (isset($this->preloadedMembers[(int) $user->getKey()])) {
            return true;
        }

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
