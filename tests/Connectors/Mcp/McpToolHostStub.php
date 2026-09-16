<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;

/**
 * A minimal host for the parts of MergesRegisteredTools that do not need a real agent — the worker
 * boundary and toolkit expansion, which are pure functions of the tool list.
 */
class McpToolHostStub
{
    use MergesRegisteredTools;

    /**
     * @param list<object> $tools
     * @return list<object>
     */
    public function boundary(array $tools): array
    {
        return $this->applyWorkerBoundary($tools);
    }
}
