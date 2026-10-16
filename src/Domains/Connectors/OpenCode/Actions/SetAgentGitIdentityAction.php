<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\DataTransferObject\GitIdentity;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Validated here rather than at commit time: git rejects a malformed ident only when the commit runs,
 * inside a queued push long after whoever set it has gone.
 */
class SetAgentGitIdentityAction
{
    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    /**
     * Sets whichever of the two is given and leaves the other as it was.
     */
    public function set(?string $name = null, ?string $email = null): GitIdentity
    {
        $name = Str::trimToNull($name);
        $email = Str::trimToNull($email);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('"' . $email . '" is not a valid email address.');
        }

        if ($name !== null && preg_match('/[<>\r\n]/', $name) === 1) {
            throw new ValidationException('A commit author name cannot contain "<", ">" or a line break.');
        }

        if ($email !== null) {
            $this->agent->set(AgentCustomFieldEnum::GIT_AUTHOR_EMAIL->value, $email);
        }

        if ($name !== null) {
            $this->agent->set(AgentCustomFieldEnum::GIT_AUTHOR_NAME->value, $name);
        }

        return GitIdentity::forAgent($this->agent);
    }

    public function reset(): GitIdentity
    {
        $this->agent->del(AgentCustomFieldEnum::GIT_AUTHOR_NAME->value);
        $this->agent->del(AgentCustomFieldEnum::GIT_AUTHOR_EMAIL->value);

        return GitIdentity::forAgent($this->agent);
    }
}
