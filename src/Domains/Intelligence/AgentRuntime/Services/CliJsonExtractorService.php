<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Services;

// Pull the first balanced `{…}` JSON object out of CLI output that has
// stderr-noise warnings mixed in BEFORE and/or AFTER the actual response.
// Respects JSON string quoting + escape sequences so braces inside string
// values don't throw off the depth counter.
final class CliJsonExtractorService
{
    public static function extractFirstObject(string $output): ?string
    {
        $start = strpos($output, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($output);

        for ($i = $start; $i < $length; $i++) {
            $c = $output[$i];

            if ($escape) {
                $escape = false;

                continue;
            }
            if ($c === '\\') {
                $escape = true;

                continue;
            }
            if ($c === '"') {
                $inString = ! $inString;

                continue;
            }
            if ($inString) {
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($output, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
