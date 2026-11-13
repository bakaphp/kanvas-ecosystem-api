<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Support;

use Kanvas\Exceptions\ValidationException;

/**
 * The lines an orchestrator writes into a factory task to say where it runs: `Repo: <folder>` for the
 * checkout on the Mac, `Branch: <name>` for a branch the ticket's tasks share. Two different values for
 * one line is a contradiction the task cannot resolve, never a choice to guess at.
 */
final class TaskDirectives
{
    /**
     * Integration branches a person merges into. A task works on its own branch and never on one of these.
     */
    private const array PROTECTED_BRANCHES = ['main', 'master', 'develop', 'development', 'trunk'];

    /**
     * `Repo: Quorum-app`, `**Repo:** mctekk/Quorum-app`, `Repository: https://github.com/mctekk/Quorum-app.git`:
     * the folder name only.
     */
    public static function repo(string $text): ?string
    {
        preg_match_all(
            '/\bRepo(?:sitory)?\**[ \t]*:[ \t*`]*(?:(?:https?:\/\/|git@)?github\.com[\/:])?(?:[A-Za-z0-9._-]+\/)?([A-Za-z0-9][A-Za-z0-9._-]*)/i',
            $text,
            $matches
        );

        return self::single(
            'Repo',
            array_map(
                static fn (string $name): string => (string) preg_replace('/\.git$/i', '', rtrim($name, '.')),
                $matches[1]
            )
        );
    }

    /**
     * Only at the start of a line, so prose like "the base branch: develop" is never read as one.
     */
    public static function branch(string $text): ?string
    {
        preg_match_all(
            '/^[ \t>*-]*Branch\**[ \t]*:[ \t*`]*([A-Za-z0-9][A-Za-z0-9._\/-]*)/im',
            $text,
            $matches
        );

        $branch = self::single('Branch', array_map(static fn (string $name): string => rtrim($name, './'), $matches[1]));

        if ($branch === null) {
            return null;
        }

        if (str_contains($branch, '..') || str_ends_with($branch, '.lock')) {
            throw new ValidationException('"' . $branch . '" is not a usable branch name.');
        }

        if (in_array(strtolower($branch), self::PROTECTED_BRANCHES, true)) {
            throw new ValidationException(
                'The task names "' . $branch . '" as its branch. A task never works on an integration branch: '
                . 'name a branch of its own, for example "Branch: ticket/12-login-screen".'
            );
        }

        return $branch;
    }

    /**
     * @param list<string> $values
     */
    private static function single(string $label, array $values): ?string
    {
        $values = array_values(array_unique($values));

        if (count(array_unique(array_map(strtolower(...), $values))) > 1) {
            throw new ValidationException(
                'The task names more than one "' . $label . ':" (' . implode(', ', $values) . '); it can have only one.'
            );
        }

        return $values[0] ?? null;
    }
}
