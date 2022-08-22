<?php

declare(strict_types=1);

namespace Kanvas\Sessions\Sessions\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Users\Users\Models\Users;
use Illuminate\Support\Facades\DB;
use Kanvas\Sessions\Keys\Models\SessionKeys;
use Exception;

/**
 * Apps Model
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
class Sessions extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sessions';

    /**
     * Apps relationship
     *
     * @return Apps
     */
    public function user(): Apps
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Create a new session token for the given users, to track on the db.
     *
     * @param Users $user
     * @param string $sessionId
     * @param string $token
     * @param string $userIp
     * @param int $pageId
     *
     * @return Users
     */
    public function start(Users $user, string $sessionId, string $token, string $userIp, int $pageId) : Users
    {
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
            "SELECT * from banlist
            WHERE ip IN (:ip_one, :ip_two, :ip_three, :ip_four)
            OR users_id = :users_id
            OR email LIKE :email
            OR email LIKE :email_domain",
            $params
        );

        $banInfo = count($banData) > 0 ? $banData[0] : null;

        if ($banInfo) {
            if ($banInfo['ip'] || $banInfo['users_id'] || $banInfo['email']) {
                throw new Exception(_('This account has been banned. Please contact the administrators.'));
            }
        }

        /**
         * Create or update the session.
         *
         * @todo we don't need a new session for every getenv('ANONYMOUS') user, use less ,
         * right now 27.7.15 90% of the sessions are for that type of users
         */
        $session = new self();
        $session->users_id = $user->id;
        $session->start = $currentTime;
        $session->time = $currentTime;
        $session->page = $pageId;
        $session->logged_in = 1;
        $session->id = $sessionId;
        $session->token = $token;
        $session->ip = $userIp;
        $session->save();

        $lastVisit = ($user->session_time > 0) ? $user->session_time : $currentTime;

        //update user info
        $user->session_time = $currentTime;
        $user->session_page = $pageId;
        $user->lastvisit = date('Y-m-d H:i:s', $lastVisit);
        $user->save();

        //create a new one
        $session = new SessionKeys();
        $session->sessions_id = $sessionId;
        $session->users_id = $user->id;
        $session->last_ip = $userIp;
        $session->last_login = $currentTime;
        $session->save();

        //you are in, no?
        $user->loggedIn = true;

        return $user;
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
    public function check(Users $user, string $sessionId, string $userIp, int $pageId) : Users
    {
        $currentTime = time();
        $pageId = (int) $pageId;

        $params = [
            'session_id' => $sessionId
        ];

        $result = DB::select(
            "SELECT users.*, sessions.*
            FROM sessions, users
            WHERE sessions.id = :session_id
            AND users.id = sessions.users_id",
            $params
        );

        $userData = current($result);

        if (empty($userData)) {
            throw new Exception('Invalid Session');
        }

        if ($userData->users_id != $user->id) {
            throw new Exception('Invalid Token');
        }


        // print_r($userData);
        // die();

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
                    throw new Exception("Unable to update session");
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

        throw new SessionNotFound(_('No Session Token Found'));
    }
}
