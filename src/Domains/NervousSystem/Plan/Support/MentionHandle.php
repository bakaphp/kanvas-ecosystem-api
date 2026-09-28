<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

use Baka\Contracts\AppInterface;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Who can be @mentioned, and by what name.
 *
 * `ParseMessageMentionsAction` matches an `@token` against the app-global `displayname`, so two things
 * make someone unreachable and neither is visible to the writer: no app profile, or a display name
 * with a space in it (`@Liliana Garcia` tokenises to `@Liliana`, which resolves to nobody). An agent
 * told to mention its PM has to be given a handle that actually resolves, or it writes a mention that
 * silently reaches no one — the failure this whole path exists to remove.
 */
class MentionHandle
{
    /** The characters `ParseMessageMentionsAction` will match after an `@`. */
    private const string HANDLE_CHARS = '[\p{L}\p{N}._+\-]+';

    /**
     * The `@handle` for a user, or null when they cannot be mentioned at all.
     */
    public static function forUser(?Users $user, AppInterface $app): ?string
    {
        if ($user === null) {
            return null;
        }

        try {
            $displayname = trim($user->getAppProfile($app)->displayname);
        } catch (Throwable) {
            return null;
        }

        return $displayname !== '' && preg_match('/^' . self::HANDLE_CHARS . '$/u', $displayname) === 1
            ? $displayname
            : null;
    }

    /**
     * Whether this text @mentions that user — matched the way the parser matches, not by substring.
     */
    public static function isNamedIn(string $content, ?Users $user, AppInterface $app): bool
    {
        $handle = self::forUser($user, $app);

        if ($handle === null || ! str_contains($content, '@')) {
            return false;
        }

        if (preg_match_all('/@(' . self::HANDLE_CHARS . ')/u', $content, $matches) === false) {
            return false;
        }

        return in_array($handle, $matches[1], true);
    }
}
