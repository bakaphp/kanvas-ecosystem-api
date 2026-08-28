<?php

declare(strict_types=1);

namespace Kanvas\AdminLinks\DataTransferObject;

/**
 * A built admin link plus what the recipient needs in order for it to actually
 * open — callers surface these so a user gets "select Acme first" instead of a
 * link that silently redirects to the dashboard.
 */
final readonly class AdminLinkMeta
{
    public function __construct(
        public ?string $url,
        public bool $requiresCompany,
        public ?string $sectionPermission,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'requires_company' => $this->requiresCompany,
            'section_permission' => $this->sectionPermission,
        ];
    }
}
