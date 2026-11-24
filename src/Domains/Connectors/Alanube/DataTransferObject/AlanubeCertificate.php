<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\DataTransferObject;

use LogicException;
use SensitiveParameter;

/**
 * The tenant's DGII digital certificate on its way to Alanube. Alanube keeps it; Kanvas never stores it.
 *
 * Deliberately not a Spatie Data object: it must never be serialized (queue, cache, session), dumped, or end up
 * in a log or an LLM prompt, so serialization throws and debug output is masked. It lives only for the length
 * of the `POST /company` call.
 */
final class AlanubeCertificate
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $contents,
        #[SensitiveParameter]
        private readonly string $password,
        public readonly string $name = 'certificado',
        public readonly string $extension = 'p12',
    ) {
    }

    /**
     * @return array{name: string, extension: string, content: string, password: string}
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'extension' => $this->extension,
            'content' => base64_encode($this->contents),
            'password' => $this->password,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'extension' => $this->extension, 'contents' => '[redacted]', 'password' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('A DGII certificate is never serialized; pass it straight through to Alanube.');
    }
}
