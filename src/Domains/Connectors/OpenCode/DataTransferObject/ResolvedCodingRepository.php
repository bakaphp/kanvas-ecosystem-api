<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\DataTransferObject;

use Kanvas\Connectors\OpenCode\Services\GitHubRepositoryService;

/**
 * A repository an agent may work on, paired with a client authenticated for it.
 *
 * A type, rather than a `[$repo, $service]` tuple, because the resolver's other return is an error
 * array — and `is_array()` is true of both, so every tool "succeeded" by returning its own plumbing to
 * the model. A distinct type makes the two outcomes impossible to confuse.
 */
readonly class ResolvedCodingRepository
{
    public function __construct(
        public CodingRepository $repository,
        public GitHubRepositoryService $github,
    ) {
    }
}
