<?php

declare(strict_types=1);

namespace Kanvas\Sessions\Models;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Laravel\Sanctum\PersonalAccessToken;
use Lcobucci\JWT\Token\Plain;

/**
 * Apps Model.
 *
 * @property int $users_id
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
        'id',
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
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    /**
     * Session Keys.
     *
     * @return BelongsTo
     */
    public function keys(): BelongsTo
    {
        return $this->belongsTo(SessionKeys::class);
    }

    /**
     * Override the getIncrementing() function to return false to tell
     * Laravel that the identifier does not auto increment (it's a string).
     *
     * @return bool
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Tell laravel that the key type is a string, not an integer.
     *
     * @return string
     */
    public function getKeyType(): string
    {
        return 'string';
    }

    /**
     * Create a new session token for the given users, to track on the db.
     *
     * @param Users $user
     * @param string $sessionId
     * @param Plain $token
     * @param string $userIp
     * @param int $pageId
     *
     * @return self
     */
    public function start(
        Users $user,
        string $name,
        string $sessionId,
        Plain $token,
        Plain $refreshToken,
        string $userIp,
        array $ability = ['*'],
        int $pageId = 0,
    ): self {
        $last_visit = 0;
        $currentTime = time();

        //
        // Initial ban check against user id, IP and email address
        //
        preg_match('/(..)(..)(..)(..)/', $userIp, $userIp_parts);

        // $sql = "SELECT ip, users_id, email
        //     FROM  Canvas\Models\Banlist
        //     WHERE ip IN (:ip_one:, :ip_two:, :ip_three:, :ip_four:)
        //         OR users_id = :users_id:
        //         OR email LIKE :email:
        //         OR email LIKE :email_domain:";

        $params = [
            'users_id' => $user->id,
            'email' => $user->email,
            'email_domain' => substr(str_replace("\'", "''", $user->email), strpos(str_replace("\'", "''", $user->email), '@')),
            'ip_one' => $userIp_parts[1] . $userIp_parts[2] . $userIp_parts[3] . $userIp_parts[4],
            'ip_two' => $userIp_parts[1] . $userIp_parts[2] . $userIp_parts[3] . 'ff',
            'ip_three' => $userIp_parts[1] . $userIp_parts[2] . 'ffff',
            'ip_four' => $userIp_parts[1] . 'ffffff',
        ];

        $banData = DB::select(
            'SELECT * from banlist
            WHERE ip IN (:ip_one, :ip_two, :ip_three, :ip_four)
            OR users_id = :users_id
            OR email LIKE :email
            OR email LIKE :email_domain',
            $params
        );

        $banInfo = count($banData) > 0 ? $banData[0] : null;

        if ($banInfo) {
            if ($banInfo['ip'] || $banInfo['users_id'] || $banInfo['email']) {
                throw new AuthenticationException(_('This account has been banned. Please contact the administrators.'));
            }
        }

        /**
         * Create or update the session.
         *
         * @todo we don't need a new session for every env('ANONYMOUS') user, use less ,
         * right now 27.7.15 90% of the sessions are for that type of users
         */
        $session = self::create([
            'users_id' => $user->id,
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
     *
     * @param Users $user
     * @param string $sessionId
     * @param string $userIp
     * @param int $pageId
     *
     * @return Users
     */
    public function check(Users $user, string $sessionId, string $userIp, int $pageId): Users
    {
        $currentTime = time();
        $pageId = (int) $pageId;

        $params = [
            'session_id' => $sessionId
        ];

        $result = DB::select(
            'SELECT users.*, sessions.*
            FROM sessions, users
            WHERE sessions.id = :session_id
            AND users.id = sessions.users_id',
            $params
        );

        $userData = current($result);

        if (empty($userData)) {
            throw new AuthenticationException('Invalid Session');
        }

        if ($userData->users_id != $user->id) {
            throw new AuthenticationException('Invalid Token');
        }

        //
        // Did the session exist in the DB?
        //
        if ($userData) {
            // Only update session DB a minute or so after last update
            if ($currentTime - $userData->time > 60) {
                //update the user session
                $session = self::find($sessionId);
                $session->time = $currentTime;
                $session->page = $pageId;

                if (!$session->save()) {
                    throw new AuthenticationException('Unable to update session');
                }

                //update user
                $user->id = $userData->users_id;
                $user->session_time = $currentTime;
                $user->session_page = $pageId;
                $user->save();

                //$this->clean($sessionId);
            }

            $user->session_id = $sessionId;

            return $user;
        }

        throw new AuthenticationException(_('No Session Token Found'));
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
        return !$this->can($ability);
    }

    /**
     * Terminates the specified session
     * It will delete the entry in the sessions table for this session,
     * remove the corresponding auto-login key and reset the cookies.
     *
     * @param Users $user
     * @param string|null $ip
     *
     * @return bool
     */
    public function end(Users $user, ?string $sessionId = null): bool
    {
        if (is_null($sessionId)) {
            return $this->endAll($user);
        }

        self::where('id', $sessionId)
            ->where('users_id', $user->getId())
            ->delete();

        SessionKeys::where('sessions_id', $sessionId)
        ->where('users_id', $user->getId())
        ->delete();

        return true;
    }

    /**
     * End all user Sessions from all devices and Ips.
     *
     * @param Users $user
     *
     * @return bool
     */
    public function endAll(Users $user): bool
    {
        $this->find([
            'conditions' => 'users_id = :users_id:',
            'bind' => [
                'users_id' => $user->getId(),
            ]
        ])
        ->delete();

        SessionKeys::find([
            'conditions' => 'users_id = :users_id: ',
            'bind' => [
                'users_id' => $user->getId(),
            ]
        ])
        ->delete();

        return true;
    }
}
