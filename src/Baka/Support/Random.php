<?php

declare(strict_types=1);

namespace Baka\Support;

use Baka\Contracts\AppInterface;
use GrantHolle\UsernameGenerator\Username;
use InvalidArgumentException;
use Kanvas\Users\Models\UsersAssociatedApps;

class Random
{
    public const int MAX_FIRSTNAME_LENGTH = 8;
    public const int MAX_LASTNAME_LENGTH = 5;
    public const int MAX_UNIQUENESS_ATTEMPTS = 10;

    public static function generateDisplayName(string $displayname, int $randNo = 200): string
    {
        $displayname = Str::cleanup($displayname);

        // Break name into parts, filter out empty elements
        $usernameParts = array_filter(explode(' ', strtolower($displayname)));

        // Get only first two parts (typically first and last name)
        $usernameParts = array_slice($usernameParts, 0, 2);

        $part1 = ! empty($usernameParts[0])
            ? substr($usernameParts[0], 0, self::MAX_FIRSTNAME_LENGTH)
            : '';

        $part2 = ! empty($usernameParts[1])
            ? str_shuffle(substr($usernameParts[1], 0, self::MAX_LASTNAME_LENGTH))
            : '';

        $part3 = ($randNo > 0) ? rand(1, $randNo) : '';

        return $part1.$part2.$part3;
    }

    public static function generateDisplayNameFromEmail(string $email, AppInterface $app, int $randNo = 200): string
    {
        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format provided');
        }

        // Handle Apple private relay emails with special formatting
        if (str_ends_with($email, '@privaterelay.appleid.com')) {
            return self::generatePrivateRelayUsername();
        }

        // Extract username portion from email
        $displayname = substr($email, 0, strpos($email, '@'));

        // Clean up the display name (remove special chars and numbers)
        $displayname = self::cleanupEmailUsername($displayname);

        // If cleaning removed everything, generate a fallback name
        if ($displayname === '' || $displayname === null) {
            return self::generatePrivateRelayUsername();
        }

        return self::ensureUsernameUniqueness($displayname, $app, $randNo);
    }

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

    private static function cleanupEmailUsername(string $username): ?string
    {
        // Remove special characters
        $username = preg_replace('/[^a-zA-Z0-9]/', '', $username);

        // Remove numbers
        $username = preg_replace('/\d+/', '', $username);

        return $username;
    }

    private static function ensureUsernameUniqueness(string $username, AppInterface $app, int $randNo): string
    {
        $originalName = $username;
        $counter = 0;

        // Try a few times with random numbers
        while ($counter < self::MAX_UNIQUENESS_ATTEMPTS) {
            if (! UsersAssociatedApps::query()->fromApp($app)->where('displayname', $username)->exists()) {
                return $username; // Already unique
            }

            // Add random suffix
            $randomNumber = ($randNo > 0) ? rand(1, $randNo) : '';
            $username = $originalName.$randomNumber;
            $counter++;
        }

        // Ultimate fallback - timestamp will guarantee uniqueness
        return $originalName.time();
    }

    /**
     * Create a URL-friendly slug from a display name.
     *
     * @param string $displayName The display name to convert to a slug
     *
     * @return string Slug-formatted string
     */
    public static function cleanUpDisplayNameForSlug(string $displayName): string
    {
        $slug = Str::slug($displayName);

        return Str::limit($slug, 45, '');
    }
}
