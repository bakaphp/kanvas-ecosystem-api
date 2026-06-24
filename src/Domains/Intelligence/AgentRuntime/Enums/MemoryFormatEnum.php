<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

// How runtime memory files serialize a list of one-line durable facts.
//
//   Separator: Hermes-style §-separated lines. The whole file is dumped
//              into the prompt verbatim, so the format only needs to be
//              round-trippable.
//
//   MarkdownSections: each fact becomes its own `## N` block. Needed for
//              OpenClaw which chunks markdown by header for vector/FTS
//              retrieval — one fact per chunk yields tight matches; a
//              §-separated blob would either be one large muddy chunk or
//              miss the chunker's heuristic entirely.
enum MemoryFormatEnum: string
{
    case Separator = 'separator';
    case MarkdownSections = 'markdown_sections';
}
