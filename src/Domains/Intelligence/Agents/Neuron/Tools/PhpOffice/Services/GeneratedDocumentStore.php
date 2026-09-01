<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class GeneratedDocumentStore
{
    private const PREFIX = 'generated_document:';

    public function remember(string $path): string
    {
        $documentId = (string) Str::uuid();

        Cache::put(self::PREFIX . $documentId, $path, now()->addDay());

        return $documentId;
    }

    public function path(string $documentId): string
    {
        $path = Cache::get(self::PREFIX . $documentId);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException("Invalid or expired generated document id: {$documentId}");
        }

        return $path;
    }
}
