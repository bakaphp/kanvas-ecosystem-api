<?php

declare(strict_types=1);

namespace Baka\Support;

use Kanvas\Users\Models\UsersAssociatedApps;
use GrantHolle\UsernameGenerator\Username;
use Kanvas\Apps\Models\Apps;

class Random
{
    /**
     * Given a firstname give me a random username.
     *
     * @param int $randNo
     */
    public static function generateDisplayName(string $displayname, $randNo = 200): string
    {
        $displayname = Str::cleanup($displayname);
        $usernameParts = array_filter(explode(' ', strtolower($displayname))); //explode and lowercase name
        $usernameParts = array_slice($usernameParts, 0, 2); //return only first two array part

        $part1 = (! empty($usernameParts[0])) ? substr($usernameParts[0], 0, 8) : ''; //cut first name to 8 letters
        $part2 = (! empty($usernameParts[1])) ? substr($usernameParts[1], 0, 5) : ''; //cut second name to 5 letters
        $part3 = ($randNo) ? rand(0, $randNo) : '';

        $username = $part1 . str_shuffle($part2) . $part3; //str_shuffle to randomly shuffle all characters

        return $username;
    }

    /**
     * Given a email generate a displayname.
     */
    public static function generateDisplayNameFromEmail(string $email, Apps $app, $randNo = 200): string
    {
        $app = $app ?? app(Apps::class);
        if (str_ends_with($email, '@privaterelay.appleid.com')) {
            $displayname = (new Username())
                ->withAdjectiveCount(1)
                ->withNounCount(1)
                ->withDigitCount(0)
                ->withCasing('lower')
                ->generate();

            return str_replace(' ', '', $displayname);
        }

        $displayname = substr($email, 0, strpos($email, '@'));

        //Remove any numbers from the email
        preg_match_all('!\d+!', $displayname, $matches);
        $numbers = implode('', $matches[0]);
        $displayname = str_replace($numbers, '', $displayname);

        //Check if there is another user with the same displayname
        if (UsersAssociatedApps::query()->fromApp($app)->where('displayname', $displayname)->first()) {
            $randomNumber = ($randNo) ? rand(0, $randNo) : '';
            $displayname = $displayname . $randomNumber;
        }

        //Remove any characters from the left of a found special character, if any
        if (preg_match('/[^a-zA-Z0-9]/', $displayname, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1];
            $displayname = substr($displayname, $pos + 1);
        }

        return $displayname;
    }

    public static function cleanUpDisplayNameForSlug(string $displayName): string
    {
        $slug = Str::slug($displayName);

        return Str::limit($slug, 45, '');
    }
}
