<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Attributes;

use Attribute;
use BackedEnum;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;

/**
 * Marks a class as a catalog entry in `actions`, synced by `kanvas:workflow-sync-actions`.
 *
 * Everything past `name` exists so an agent can pick this step without guessing. `description` says
 * what it does, `params` names the knobs it reads (an unset required param is how a rule silently
 * does the wrong thing), and `integration` + `requiresConfig` let the catalog report whether the
 * tenant can actually run it yet.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WorkflowAction
{
    /**
     * @param array<array-key, BackedEnum|string> $requiresConfig settings keys that must be set on the
     *        company (falling back to the app) before this step can run
     * @param array<string, string> $params param name => what it does, written for the agent that sets it
     * @param array<array-key, string> $requiredParams params the step cannot run correctly without.
     *        List a param here when omitting it changes behaviour rather than erroring — a silently
     *        wrong rule is worse than one that refuses to be created.
     */
    /**
     * `kind` is normally left null and derived from the base class — a `ProcessWebhookJob` is a
     * receiver, a `KanvasActivity` is a workflow step. Set it only to override that.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?ActionKindEnum $kind = null,
        public readonly BackedEnum|string|null $integration = null,
        public readonly array $requiresConfig = [],
        public readonly array $params = [],
        public readonly array $requiredParams = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function requiredParamNames(): array
    {
        return array_values(array_filter(
            array_map('strval', $this->requiredParams),
            fn (string $name): bool => $name !== ''
        ));
    }

    public function integrationValue(): ?string
    {
        if ($this->integration instanceof BackedEnum) {
            return (string) $this->integration->value;
        }

        return $this->integration !== null && $this->integration !== '' ? $this->integration : null;
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        $keys = [];

        foreach ($this->requiresConfig as $key) {
            $value = $key instanceof BackedEnum ? (string) $key->value : $key;

            if ($value !== '') {
                $keys[] = $value;
            }
        }

        return $keys;
    }
}
