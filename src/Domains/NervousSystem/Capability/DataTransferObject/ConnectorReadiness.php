<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\DataTransferObject;

/**
 * Whether the connector behind a tool is actually set up for this tenant.
 *
 * Separate from "does the tool exist" on purpose: "we have no Odoo integration" and "we have one and
 * you haven't configured it" are the same answer to an agent that can only see its toolset, and they
 * need opposite next moves — one is a roadmap item, the other is a message to an admin naming the
 * setting to fill in.
 */
final readonly class ConnectorReadiness
{
    /**
     * @param array<string, bool> $checks Named checks, so a partial setup reports which half is missing.
     * @param list<string> $issues Admin-facing, each naming the setting to fix.
     */
    public function __construct(
        public string $slug,
        public string $label,
        public bool $ready,
        public array $checks = [],
        public array $issues = [],
    ) {
    }

    /**
     * @param array<string, bool> $checks
     */
    public static function ready(string $slug, string $label, array $checks = []): self
    {
        return new self(
            slug: $slug,
            label: $label,
            ready: true,
            checks: $checks,
        );
    }

    /**
     * @param array<string, bool> $checks
     * @param list<string> $issues
     */
    public static function notReady(
        string $slug,
        string $label,
        array $checks,
        array $issues,
    ): self {
        return new self(
            slug: $slug,
            label: $label,
            ready: false,
            checks: $checks,
            issues: $issues,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connector' => $this->label,
            'slug' => $this->slug,
            'ready' => $this->ready,
            'checks' => $this->checks,
            'issues' => $this->issues,
        ];
    }
}
