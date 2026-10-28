<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use App\GraphQL\Concerns\ActingContext;
use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Support\Arr;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;

final class CompanyKnowledgeMutation
{
    use ResolvesActingContext;

    /**
     * @return list<Filesystem>
     */
    public function upload(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();

        return new CompanyKnowledgeService()->upload(
            $this->company($ctx),
            $ctx->app,
            $ctx->user,
            Arr::wrap($request['files'] ?? []),
        );
    }

    public function deleteDocument(mixed $root, array $request): bool
    {
        $ctx = $this->actingContext();

        return new CompanyKnowledgeService()->deleteDocument($this->company($ctx), $ctx->app, (string) $request['uuid']);
    }

    /**
     * @return array<string, mixed>
     */
    public function addUrl(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();

        return CompanyKnowledgeService::presentUrl(new CompanyKnowledgeService()->addUrl(
            $this->company($ctx),
            $ctx->app,
            $ctx->user,
            (string) $request['url'],
        ));
    }

    public function removeUrl(mixed $root, array $request): bool
    {
        $ctx = $this->actingContext();

        return new CompanyKnowledgeService()->removeUrl($this->company($ctx), $ctx->app, (string) $request['url']);
    }

    private function company(ActingContext $ctx): Companies
    {
        /** @var Companies $company */
        $company = $ctx->company;
        $company->hasCompanyPermission($ctx->user, $ctx->app);

        return $company;
    }
}
