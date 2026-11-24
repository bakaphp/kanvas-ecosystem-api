<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Support\WebsiteUrl;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Intelligence\Knowledge\Jobs\IndexCompanyKnowledgeFilesJob;
use Kanvas\Intelligence\Knowledge\Jobs\IndexCompanyKnowledgeUrlJob;
use Kanvas\Intelligence\Knowledge\Jobs\PruneCompanyKnowledgeJob;
use Kanvas\Users\Models\Users;

/**
 * Company knowledge for the internal agents, during onboarding or any time after: documents
 * attached to the company itself, told apart from its other files (logos, contracts) by a marker
 * field name, and single pages added by URL. Indexing and pruning run as plain queued jobs
 * dispatched from here, so a tenant has nothing to configure.
 */
final class CompanyKnowledgeService
{
    use HasMutationUploadFiles;

    public const string FIELD_PREFIX = 'company_knowledge:';
    public const string SOURCE_TYPE_DOCUMENT = 'company_document';
    public const string SOURCE_TYPE_URL = 'company_url';
    /** Company setting: the added URLs keyed by URL, each `{url, status, chunks, added_at, indexed_at, error}`. */
    public const string URLS_SETTING = 'company_knowledge_urls';

    private const int LOCK_SECONDS = 10;

    /**
     * Each document gets its own field name so the per-field dedupe in AttachFilesystemAction
     * never replaces one knowledge file with the next. The id, not the uuid: field_name is 50 chars.
     *
     * @param array<int, UploadedFile> $files
     * @return list<Filesystem>
     */
    public function upload(
        Companies $company,
        Apps $app,
        Users $user,
        array $files,
    ): array {
        $uploaded = $this->uploadFilesAndCollectAs(
            $company,
            $app,
            $user,
            $files,
            fn (Filesystem $file): string => self::FIELD_PREFIX . $file->getId(),
        );

        if ($uploaded !== []) {
            IndexCompanyKnowledgeFilesJob::dispatch(
                $app,
                $company,
                $user,
                array_map(fn (Filesystem $file): int => (int) $file->getId(), $uploaded),
            );
        }

        return $uploaded;
    }

    /**
     * @return Collection<int, Filesystem>
     */
    public function documents(Companies $company): Collection
    {
        return $company->files()
            ->where('filesystem_entities.field_name', 'like', self::FIELD_PREFIX . '%')
            ->orderByDesc('filesystem.id')
            ->get();
    }

    /**
     * Only a file attached to this company under the marker can be removed here, so the mutation
     * cannot be used to delete an arbitrary file by uuid.
     */
    public function deleteDocument(Companies $company, Apps $app, string $uuid): bool
    {
        $file = $this->documents($company)->firstWhere('uuid', $uuid)
            ?? throw new ValidationException('That file is not a company knowledge document');

        new FilesystemServices($app)->delete($file);
        FilesystemEntities::query()
            ->where('filesystem_id', $file->getKey())
            ->notDeleted()
            ->get()
            ->each(fn (FilesystemEntities $entity) => $entity->softDelete());
        $deleted = (bool) $file->delete();

        if ($deleted) {
            PruneCompanyKnowledgeJob::dispatch(
                $app,
                $company,
                self::SOURCE_TYPE_DOCUMENT,
                (string) $file->uuid,
            );
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed> the recorded entry
     */
    public function addUrl(
        Companies $company,
        Apps $app,
        Users $user,
        string $url,
    ): array {
        $url = WebsiteUrl::normalize($url);
        $entry = $this->recordUrl($company, $url, [
            'status' => 'pending',
            'chunks' => 0,
            'added_at' => Carbon::now()->toIso8601String(),
            'indexed_at' => null,
            'error' => null,
        ]);

        IndexCompanyKnowledgeUrlJob::dispatch(
            $app,
            $company,
            $user,
            $url,
        );

        return $entry;
    }

    public function removeUrl(Companies $company, Apps $app, string $url): bool
    {
        $url = WebsiteUrl::normalize($url);
        $removed = $this->withUrlLock($company, function () use ($company, $url): bool {
            $urls = $this->urlMap($company);
            if (! isset($urls[$url])) {
                return false;
            }

            unset($urls[$url]);
            $company->set(self::URLS_SETTING, $urls);

            return true;
        });

        if (! $removed) {
            return false;
        }

        PruneCompanyKnowledgeJob::dispatch(
            $app,
            $company,
            self::SOURCE_TYPE_URL,
            $url,
        );

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function urls(Companies $company): array
    {
        return array_values($this->urlMap($company));
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed> the entry after the patch
     */
    public function recordUrl(Companies $company, string $url, array $patch): array
    {
        return $this->withUrlLock($company, function () use ($company, $url, $patch): array {
            $urls = $this->urlMap($company);
            $urls[$url] = array_merge($urls[$url] ?? ['url' => $url], $patch, ['url' => $url]);
            $company->set(self::URLS_SETTING, $urls);

            return $urls[$url];
        });
    }

    /**
     * The index job and the screen write the same setting; without the lock whichever lands
     * second drops the other's entry.
     */
    private function withUrlLock(Companies $company, callable $work): mixed
    {
        return Cache::lock('company-knowledge:urls:' . $company->getId(), self::LOCK_SECONDS)->block(5, $work);
    }

    /**
     * The GraphQL shape: status as the enum-style case name, every key present.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function presentUrl(array $entry): array
    {
        return [
            'url' => (string) ($entry['url'] ?? ''),
            'status' => strtoupper((string) ($entry['status'] ?? 'pending')),
            'chunks' => (int) ($entry['chunks'] ?? 0),
            'added_at' => $entry['added_at'] ?? null,
            'indexed_at' => $entry['indexed_at'] ?? null,
            'error' => $entry['error'] ?? null,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function urlMap(Companies $company): array
    {
        $stored = $company->get(self::URLS_SETTING);
        if (is_string($stored)) {
            $stored = Str::jsonToArray($stored);
        }

        return is_array($stored) ? $stored : [];
    }
}
