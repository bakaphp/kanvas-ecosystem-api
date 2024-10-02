<?php

declare(strict_types=1);

namespace Kanvas\Sessions\Models;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\KanvasModelTrait;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\Users;
use Laravel\Sanctum\PersonalAccessToken;
use Lcobucci\JWT\Token\Plain;

/**
 * Sessions Model.
 *
 * @property int $users_id
 * @property int $apps_id
 * @property string $token
 * @property int $start
 * @property int $time
 * @property string $ip
 * @property string $page
 * @property int $logged_in
 * @property int $is_admin
 */
class Sessions extends PersonalAccessToken
{
    use KanvasAppScopesTrait;
    use KanvasModelTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * disable created_At and updated_At.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'start',
        'time',
        'page',
        'logged_in',
        'apps_id',
        'token',
        'refresh_token',
        'expires_at',
        'refresh_token_expires_at',
        'abilities',
        'ip',
        'users_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'token',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sessions';

    /**
     * Apps relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    /**
     * Session Keys.
     */
    public function keys(): BelongsTo
    {
        return $this->belongsTo(SessionKeys::class, 'sessions_id', 'id');
    }

    /**
     * Override the getIncrementing() function to return false to tell
     * Laravel that the identifier does not auto increment (it's a string).
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Tell laravel that the key type is a string, not an integer.
     */
    public function getKeyType(): string
    {
        return 'string';
    }

    public static function getById(mixed $id, ?AppInterface $app = null): self
    {
        try {
            return self::where('id', $id)
            ->when($app, function ($query, $app) {
                $query->fromApp($app);
            })
            ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException("No record found for Token $id");
        }
    }

    /**
     * Create a new session token for the given users, to track on the db.
     */
    public function start(
        Users $user,
        string $name,
        string $sessionId,
        Plain $token,
        Plain $refreshToken,
        string $userIp,
        Apps $app,
        array $ability = ['*'],
        int $pageId = 0,
    ): self {
        $last_visit = 0;
        $currentTime = time();

        //
        // Initial ban check against user id, IP and email address
        //
        preg_match('/(..)(..)(..)(..)/', $userIp, $userIp_parts);

        $ipOne = $userIp_parts[1] . $userIp_parts[2] . $userIp_parts[3] . $userIp_parts[4];
        $ipTwo = $userIp_parts[1] . $userIp_parts[2] . $userIp_parts[3] . 'ff';
        $ipThree = $userIp_parts[1] . $userIp_parts[2] . 'ffff';
        $ipFour = $userIp_parts[1] . 'ffffff';

        $userId = $user->getId();
        $email = $user->getEmail();
        $emailDomain = substr(str_replace("\'", "''", $user->getEmail()), strpos(str_replace("\'", "''", $user->getEmail()), '@'));

        $banInfo = DB::table('banlist')
            ->whereIn('ip', [$ipOne, $ipTwo, $ipThree, $ipFour])
            ->where('apps_id', $app->getId())
            ->where(function ($query) use ($userId, $email, $emailDomain) {
                $query->where('users_id', $userId)
                    ->orWhere('email', 'LIKE', $email)
                    ->orWhere('email', 'LIKE', $emailDomain);
            })
            ->first();

        if ($banInfo) {
            throw new AuthenticationException(
                'This account has been banned. Please contact the administrators.'
            );
        }

        /**
         * Create or update the session.
         *
         * @todo we don't need a new session for every env('ANONYMOUS') user, use less ,
         * right now 27.7.15 90% of the sessions are for that type of users
         */
        $session = self::create([
            'users_id' => $user->id,
            'apps_id' => $app->getId(),
            'id' => $sessionId,
            'start' => $currentTime,
            'time' => $currentTime,
            'page' => $pageId,
            'logged_in' => 1,
            'token' => $token->toString(),
            'refresh_token' => $refreshToken->toString(),
            'expires_at' => $token->claims()->get('exp'),
            'refresh_token_expires_at' => $refreshToken->claims()->get('exp'),
            'abilities' => $ability,
            'ip' => $userIp,
        ]);

        $lastVisit = ($user->session_time > 0) ? $user->session_time : $currentTime;

        //update user info
        $user->session_time = $currentTime;
        $user->session_page = $pageId;
        $user->lastvisit = date('Y-m-d H:i:s', $lastVisit);
        $user->saveOrFail();

        $profile = $user->getAppProfile($app);
        $profile->lastvisit = $user->lastvisit;
        $profile->session_time = $user->session_time;

        //create a new one
        $sessionKey = new SessionKeys();
        $sessionKey->name = $name;
        $sessionKey->sessions_id = $sessionId;
        $sessionKey->users_id = $user->id;
        $sessionKey->last_ip = $userIp;
        $sessionKey->last_login = $currentTime;
        $sessionKey->saveOrFail();

        //you are in, no?
        $user->loggedIn = true;

        return $session;
    }

    /**
     * Checks for a given user session, tidies session table and updates user
     * sessions at each page refresh.
     */
    public function check(Users $user, string $sessionId, string $userIp, Apps $app = null, int $pageId): Users
    {
        $currentTime = time();

        $userData = DB::table('sessions')
            ->join('users', 'users.id', '=', 'sessions.users_id')
            ->select('users.*', 'sessions.*')
            ->where('sessions.id', $sessionId)
            ->when($app->get('legacy_login'), function ($query) use ($app) {
                //@todo remove once legacy is deprecated
                $query->whereIn('sessions.apps_id', [$app->getId(), AppEnums::LEGACY_APP_ID->getValue()]);
            }, function ($query) use ($app) {
                $query->where('sessions.apps_id', $app->getId());
            })
            ->first();

        if (! $userData) {
            throw new AuthenticationException('Invalid Session');
        }

        if ($userData->users_id !== $user->id) {
            throw new AuthenticationException('Invalid Token');
        }

        // Only update session DB a minute or so after last update
        if ($currentTime - $userData->time > 60) {
            //update the user session
            $session = self::fromApp($app)->where('id', $sessionId)->firstOrFail();
            $session->time = $currentTime;
            $session->page = $pageId;

            if (! $session->save()) {
                throw new AuthenticationException('Unable to update session');
            }

            //update user
            $user->session_time = $currentTime;
            $user->session_page = $pageId;
            $user->saveOrFail();

            $profile = $user->getAppProfile($app);
            $profile->session_time = $currentTime;
            $profile->updateOrFail();
        }

        return $user;
    }

    /**
     * Get the tokenable model that the access token belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function tokenable()
    {
        return $this->morphTo('sessions_keys');
    }

    /**
     * Find the token instance matching the given token.
     *
     * @param  string  $token
     *
     * @return static|null
     */
    public static function findToken($token)
    {
        return static::where('token', $token)->first();
    }

    /**
     * Determine if the token has a given ability.
     *
     * @param  string  $ability
     *
     * @return bool
     */
    public function can($ability)
    {
        return in_array('*', $this->abilities) ||
               array_key_exists($ability, array_flip($this->abilities));
    }

    /**
     * Determine if the token is missing a given ability.
     *
     * @param  string  $ability
     *
     * @return bool
     */
    public function cant($ability)
    {
        return ! $this->can($ability);
    }

    /**
     * Terminates the specified session
     * It will delete the entry in the sessions table for this session,
     * remove the corresponding auto-login key and reset the cookies.
     *
     * @param string|null $ip
     */
    public function end(Users $user, Apps $app, ?string $sessionId = null): bool
    {
        if ($sessionId === null) {
            return $this->endAll($user, $app);
        }

        DB::table('session_keys')
            ->whereIn('sessions_id', function ($query) use ($app, $sessionId, $user) {
                $query->select('id')
                    ->from('sessions')
                    ->when($app->get('legacy_login'), function ($query) use ($app) {
                        //@todo remove once legacy is deprecated
                        $query->whereIn('sessions.apps_id', [$app->getId(), AppEnums::LEGACY_APP_ID->getValue()]);
                    }, function ($query) use ($app) {
                        $query->where('sessions.apps_id', $app->getId());
                    })
                    ->where('id', $sessionId)
                    ->where('users_id', $user->getId());
            })
            ->delete();

        return $this->fromApp($app)
            ->where('id', $sessionId)
            ->where('users_id', $user->getId())
            ->delete() > 0;
    }

    /**
     * End all user Sessions from all devices and Ips.
     */
    public function endAll(Users $user, Apps $app): bool
    {
        DB::table('session_keys')
            ->whereIn('sessions_id', function ($query) use ($app, $user) {
                $query->select('id')
                    ->from('sessions')
                    ->when($app->get('legacy_login'), function ($query) use ($app) {
                        //@todo remove once legacy is deprecated
                        $query->whereIn('sessions.apps_id', [$app->getId(), AppEnums::LEGACY_APP_ID->getValue()]);
                    }, function ($query) use ($app) {
                        $query->where('sessions.apps_id', $app->getId());
                    })
                    ->where('users_id', $user->getId());
            })
            ->delete();

        return $this->fromApp($app)
            ->where('users_id', $user->getId())
            ->delete() > 0;
    }
}
