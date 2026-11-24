<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Support;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;

/**
 * The lines an orchestrator writes into a factory task to say where it runs: `Repo: <folder>` for the
 * checkout on the Mac, `Branch: <name>` for a branch the ticket's tasks share. Two different values for
 * one line is a contradiction the task cannot resolve, never a choice to guess at.
 */
final class TaskDirectives
{
    /**
     * Branches a person merges into or deploys from. A task works on its own branch and never on one of
     * these, and nothing is pushed to one: that is the one mistake deleting a branch cannot undo.
     */
    public const array PROTECTED_BRANCHES = [
        'main',
        'master',
        'develop',
        'development',
        'staging',
        'production',
        'release',
        'trunk',
        'head',
    ];

    /**
     * Openings that mean the worker is reporting failure rather than a result. Deliberately few and
     * headline-shaped: this decides whether finished work is thrown away, and the opposite error —
     * marking successful work blocked — costs more than the one it prevents.
     *
     * @var list<string>
     */
    private const array SELF_BLOCKED_OPENINGS = [
        'blocked',
        'task status report: blocked',
        'task status: blocked',
        'status: blocked',
        'unable to complete',
        'i was unable to',
        'i cannot complete',
        'i could not complete',
    ];

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

        // A run opens one checkout, so "Repo: a and b" would silently drop `b`.
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

    /**
     * Does the worker's own answer say it could not do the work?
     *
     * Anchored to the OPENING of the response, and to headline forms only. A task that mentions being
     * blocked halfway through ("I unblocked the pipeline, then counted") has not failed, and matching
     * the word anywhere would mark successful work as blocked — a worse error than the one this fixes,
     * because it stops work that actually completed.
     */
    public static function reportsItselfBlocked(string $response): bool
    {
        $opening = mb_strtolower(trim(Str::limit(strip_tags($response), 200, '')));
        $opening = trim(preg_replace('/^[#*\s>_`-]+/u', '', $opening) ?? $opening);

        foreach (self::SELF_BLOCKED_OPENINGS as $marker) {
            if (str_starts_with($opening, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** A name's part of a branch: short, and never ending in "-" when the cut lands on one. */
    public static function branchSlug(string $name): string
    {
        return trim(Str::limit(Str::slug($name), 40, ''), '-');
    }
}
