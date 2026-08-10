<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Support;

final class KnowledgeChunker
{
    private const int MAX_CHUNK_LENGTH = 1000;

    /**
     * Splits on blank lines (paragraph breaks), packing consecutive paragraphs
     * together until the next one would push a chunk past $maxLength. A single
     * paragraph longer than $maxLength is kept whole rather than cut mid-word.
     *
     * @return array<int, string>
     */
    public function chunk(string $content, int $maxLength = self::MAX_CHUNK_LENGTH): array
    {
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        $paragraphs = preg_split('/\n{2,}/', $content) ?: [$content];

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($paragraph) + 2 > $maxLength) {
                $chunks[] = $current;
                $current = '';
            }

            $current = $current === '' ? $paragraph : "{$current}\n\n{$paragraph}";
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
