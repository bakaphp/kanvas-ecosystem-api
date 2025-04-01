<?php

declare(strict_types=1);

namespace Baka\Support;

use Baka\Contracts\AppInterface;
use GrantHolle\UsernameGenerator\Username;
use InvalidArgumentException;
use Kanvas\Users\Models\UsersAssociatedApps;

class Random
{
    /**
     * Given a firstname give me a random username.
     */
    public static function generateDisplayName(string $displayname, int $randNo = 200): string
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

    public static function generateDisplayNameFromEmail(string $email, AppInterface $app, int $randNo = 200): string
    {
        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format provided');
        }

        // Handle Apple private relay emails consistently
        if (str_ends_with($email, '@privaterelay.appleid.com')) {
            return self::generatePrivateRelayUsername();
        }

        $displayname = substr($email, 0, strpos($email, '@'));

        // First remove special characters, keeping only alphanumeric
        $displayname = preg_replace('/[^a-zA-Z0-9]/', '', $displayname);

        $displayname = preg_replace('/\d+/', '', $displayname);

        // If nothing is left after cleaning, generate a random username
        if ($displayname === null || $displayname === '') {
            return self::generatePrivateRelayUsername();
        }

        // Ensure uniqueness by checking the database
        $originalName = $displayname;
        $counter = 0;

        while ($counter < 10) { // Limit attempts to avoid infinite loop
            // Check if this displayname already exists
            if (! UsersAssociatedApps::query()->fromApp($app)->where('displayname', $displayname)->exists()) {
                return $displayname; // Return unique name
            }

            // If it exists, add random suffix
            $randomNumber = ($randNo > 0) ? rand(1, $randNo) : '';
            $displayname = $originalName . $randomNumber;
            $counter++;
        }

        // Fallback to timestamp-based name to ensure uniqueness
        return $originalName . time();
    }

    /**
     * Generate a consistent username format for private relay emails
     */
    private static function generatePrivateRelayUsername(): string
    {
        $username = (new Username())
            ->withAdjectiveCount(1)
            ->withNounCount(1)
            ->withDigitCount(0)
            ->withCasing('lower')
            ->generate();

        return str_replace(' ', '', $username);
    }

    public static function cleanUpDisplayNameForSlug(string $displayName): string
    {
        $slug = Str::slug($displayName);

        return Str::limit($slug, 45, '');
    }
}
