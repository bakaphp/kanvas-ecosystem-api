<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Contracts;

interface KnowledgeEmbedder
{
    /** @return array<int, float> */
    public function embed(string $text): array;

    /**
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array;

    public function dimension(): int;

    /**
     * A stable string tying an index to the embedder that built it, e.g.
     * "gemini:gemini-embedding-2:768". Index-time and query-time vectors are
     * only comparable when this matches — a change means a re-index is required.
     */
    public function identifier(): string;
}
