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
     * `Repo: example-app`, `**Repo:** acme/example-app`, `Repository: https://github.com/acme/example-app.git`:
     * the folder name only.
     */
    public static function repo(string $text): ?string
    {
        preg_match_all(
            '/\bRepo(?:sitory)?\**[ \t]*:[ \t*`]*(?:(?:https?:\/\/|git@)?github\.com[\/:])?(?:[A-Za-z0-9._-]+\/)?([A-Za-z0-9][A-Za-z0-9._-]*)[`*]*([^\n]*)/i',
            $text,
            $matches
        );

        // A run opens one checkout. "Repo: a and b" used to open `a` and drop `b` without a word, so a
        // backend agent asked about two repositories read only the app.
        foreach ($matches[2] as $index => $rest) {
            if (preg_match('/^[ \t]*(?:,|&|\+|\band\b|\by\b)[ \t*`]*(?:[A-Za-z0-9._-]+\/[A-Za-z0-9]|[A-Za-z0-9._]+-[A-Za-z0-9])/i', $rest) === 1) {
                throw new ValidationException(
                    'The task names more than one repository on its "Repo:" line (' . trim($matches[1][$index] . $rest)
                    . '). A task works in one repository: split it into one task per repository.'
                );
            }
        }

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
     * Text quoted into a task (a ticket, a brief) may name a repository or branch of its own — a query
     * clause reads "Repository: kanvas-ecosystem-api" — and would then contradict the task's real lines.
     */
    public static function neutralize(string $text): string
    {
        $text = (string) preg_replace('/\b(Repo(?:sitory)?)(\**[ \t]*:)/i', '$1 (quoted)$2', $text);

        return (string) preg_replace('/^([ \t>*-]*Branch)(\**[ \t]*:)/im', '$1 (quoted)$2', $text);
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
