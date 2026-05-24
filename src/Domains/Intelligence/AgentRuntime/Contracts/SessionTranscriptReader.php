<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Contracts;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedSessionTranscript;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * The per-connector seam used by BaseCollectSessionTranscriptsAction.
 *
 * Concrete readers (HermesStateDbReaderService, HermesJsonlFallbackReaderService, future
 * runtimes) own the wire format and the filtering — they must apply $since
 * at the source query level so we don't transfer messages we'll just
 * discard. The base action consumes the yielded transcripts and handles
 * persistence + idempotency uniformly.
 *
 * Readers MUST return content already normalized per the Laravel AI rules:
 *  - role mapped to user / assistant / tool_result (raw 'tool', 'function'
 *    upstream of this DTO);
 *  - tool_result rows have content=null and the payload in toolResults;
 *  - system-role lines dropped before yielding.
 *
 * Keeping the normalization in the reader means the base action doesn't
 * need to know about runtime-specific shapes.
 */
interface SessionTranscriptReader
{
    /**
     * @return iterable<ParsedSessionTranscript>
     */
    public function read(AgentDeployment $deployment, ?Carbon $since = null): iterable;
}
