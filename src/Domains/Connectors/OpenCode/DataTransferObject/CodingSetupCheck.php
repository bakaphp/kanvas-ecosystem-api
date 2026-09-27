<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\DataTransferObject;

/**
 * One prerequisite, and — when it is not met — who can meet it.
 *
 * The person reading this is usually not the person who can fix it. A git token and a provider key are
 * admin-only by design; an image that is not built yet needs somebody with a shell on the machine. An
 * answer that says only "misconfigured" sends them to the wrong place, and an agent relaying it cannot
 * improve on what it was given.
 *
 * Carries the passing checks too. "What is still missing" and "what is already set" are the same
 * question asked twice, and a list of only failures leaves a person unable to tell a half-configured
 * install from one that was never started.
 */
final readonly class CodingSetupCheck
{
    private function __construct(
        public string $setting,
        public string $scope,
        public bool $ok,
        public ?string $problem = null,
        public ?string $fix = null,
        public bool $needsAdministrator = false,
        public ?string $usingDefault = null,
    ) {
    }

    public static function pass(string $setting, string $scope): self
    {
        return new self(setting: $setting, scope: $scope, ok: true);
    }

    /**
     * Not set, and that is fine — something sensible applies. Reported rather than hidden: "unset" and
     * "unset but harmless" look identical to a person auditing a new install, and the difference is
     * whether they should go looking for a missing step.
     */
    public static function usingDefault(string $setting, string $scope, string $default): self
    {
        return new self(
            setting: $setting,
            scope: $scope,
            ok: true,
            usingDefault: $default
        );
    }

    /** Something only an admin can set: a credential, or a field on the agent itself. */
    public static function needsAdmin(
        string $setting,
        string $scope,
        string $problem,
        string $fix
    ): self {
        return new self(
            setting: $setting,
            scope: $scope,
            ok: false,
            problem: $problem,
            fix: $fix,
            needsAdministrator: true,
        );
    }

    /** Something a person with a shell fixes by running a command. */
    public static function needsCommand(
        string $setting,
        string $scope,
        string $problem,
        string $fix
    ): self {
        return new self(
            setting: $setting,
            scope: $scope,
            ok: false,
            problem: $problem,
            fix: $fix,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'setting' => $this->setting,
                'scope' => $this->scope,
                'ok' => $this->ok,
                'problem' => $this->problem,
                'fix' => $this->fix,
                'needs_administrator' => $this->needsAdministrator ?: null,
                'using_default' => $this->usingDefault,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
