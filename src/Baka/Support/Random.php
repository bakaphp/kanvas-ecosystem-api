<?php

declare(strict_types=1);

namespace Baka\Support;

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
    public static function generateDisplayNameFromEmail(string $email): string
    {
        return self::generateDisplayName($email);
    }

    public static function cleanUpDisplayNameForSlug(string $displayName): string
    {
        $slug = Str::slug($displayName);

        return Str::limit($slug, 45, '');
    }
}
