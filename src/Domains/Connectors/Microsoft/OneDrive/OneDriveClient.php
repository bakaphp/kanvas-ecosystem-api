<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Connectors\Microsoft\Client;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDriveEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\Support\DriveItem;
use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use Throwable;

class OneDriveClient
{
    public const string BASE_URL = 'https://graph.microsoft.com/v1.0';
    public const int MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;
    public const int SIMPLE_UPLOAD_MAX_BYTES = 4 * 1024 * 1024;
    public const int CHUNK_BYTES = 5242880;
    private const int SEARCH_PAGE_SIZE = 200;
    private const int SEARCH_MAX_PAGES = 5;

    /**
     * The folder this drive's connection is confined to, as an absolute drive path, or null for the
     * whole drive. Callers see it as "/": every path in and out is relative to it, and an id is resolved
     * before use so it cannot name an item outside.
     */
    private readonly ?string $rootFolder;

    public function __construct(
        private readonly string $accessToken,
        ?string $rootFolder = null,
        private readonly OneDriveDriveEnum $drive = OneDriveDriveEnum::APP
    ) {
        $this->rootFolder = self::rootFolderFrom($rootFolder);
    }

    /**
     * The stored form of a root folder setting: "/a/b", or null for the whole drive.
     */
    public static function rootFolderFrom(?string $folder): ?string
    {
        return self::isRootPath($folder) ? null : self::normalizePath($folder);
    }

    /**
     * The drive a company works on: the one asked for, otherwise the company's own when it connected
     * one (it is the more specific) and the app's shared drive when it did not.
     */
    public static function resolve(AppInterface $app, CompanyInterface $company, ?OneDriveDriveEnum $drive = null): self
    {
        $companyStore = MicrosoftTokenStore::forCompanyMail($app, $company);
        $store = match ($drive) {
            OneDriveDriveEnum::COMPANY => $companyStore,
            OneDriveDriveEnum::APP => MicrosoftTokenStore::forAppOneDrive($app),
            null => $companyStore->hasFiles() ? $companyStore : MicrosoftTokenStore::forAppOneDrive($app),
        };

        try {
            if (! $store->hasFiles()) {
                throw new OneDriveException(OneDriveErrorReasonEnum::NOT_CONNECTED, 'No file access granted');
            }

            $accessToken = Client::validAccessToken($store);
        } catch (Throwable) {
            throw new OneDriveException(OneDriveErrorReasonEnum::NOT_CONNECTED, self::notConnectedMessage($drive));
        }

        return new self(
            $accessToken,
            rootFolder: $store->get(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER),
            drive: $store->drive()
        );
    }

    public function drive(): OneDriveDriveEnum
    {
        return $this->drive;
    }

    public function isConfined(): bool
    {
        return $this->rootFolder !== null;
    }

    private static function notConnectedMessage(?OneDriveDriveEnum $drive): string
    {
        return match ($drive) {
            OneDriveDriveEnum::COMPANY => 'This company has not connected its own OneDrive. A company admin connects '
                . 'Microsoft (Outlook and OneDrive) for the company.',
            OneDriveDriveEnum::APP => 'The app\'s shared OneDrive is not connected. The app owner must connect it first.',
            null => 'No OneDrive is connected: neither this company nor the app has connected one.',
        };
    }

    public static function isRootPath(?string $pathOrId): bool
    {
        return $pathOrId === null || trim($pathOrId, "/ \t\n\r\0\x0B") === '';
    }

    public function search(string $query, int $limit = 25, ?string $folder = null): array
    {
        $url = self::BASE_URL . "/me/drive/root/search(q='" . rawurlencode(str_replace("'", "''", $query)) . "')";

        $folderPath = match (true) {
            self::isRootPath($folder) => $this->rootFolder,
            str_starts_with($folder, '/') => $this->absolutePath($folder),
            default => $this->folderPath($folder),
        };

        if ($folderPath === null) {
            return $this->mapItems($this->request()->get($url, ['$top' => $limit]));
        }

        return array_map($this->relative(...), $this->searchWithinFolder($url, $folderPath, $limit));
    }

    /**
     * Graph accepts a folder-scoped search URL but answers it drive-wide on personal accounts
     * (checked live), so the folder is applied here, over the first pages of the drive-wide result.
     */
    private function searchWithinFolder(string $url, string $folderPath, int $limit): array
    {
        $matches = [];
        $response = $this->request()->get($url, ['$top' => self::SEARCH_PAGE_SIZE]);

        for ($page = 1; ; $page++) {
            foreach ($this->mapAbsoluteItems($response) as $item) {
                if (self::isStrictlyUnder($item['path'], $folderPath)) {
                    $matches[] = $item;
                }
            }

            // A literal key: json('@odata.nextLink') would read it as the path @odata → nextLink.
            $next = (string) ($response->json()['@odata.nextLink'] ?? '');

            if (count($matches) >= $limit || $page >= self::SEARCH_MAX_PAGES || ! str_starts_with($next, self::BASE_URL . '/')) {
                return array_slice($matches, 0, $limit);
            }

            $response = $this->request()->get($next);
        }
    }

    private function folderPath(string $folderId): string
    {
        $item = DriveItem::fromGraph($this->getRawItem($folderId));

        if ($item['type'] !== 'folder' || $item['path'] === null) {
            throw new OneDriveException(OneDriveErrorReasonEnum::NOT_FOUND, 'The folder was not found in OneDrive.');
        }

        return $item['path'];
    }

    public function listFolder(?string $pathOrId): array
    {
        $response = $this->request()->get(self::BASE_URL . $this->itemUrl($pathOrId) . '/children');

        return $this->mapItems($response);
    }

    public function getItem(string $pathOrId): array
    {
        return $this->item($this->getRawItem($pathOrId));
    }

    /**
     * Takes the item getItem() returned, so a caller that already inspected it does not fetch it twice.
     */
    public function download(array $item): array
    {
        if ($item['type'] === 'folder') {
            throw new OneDriveException(OneDriveErrorReasonEnum::UNSUPPORTED_TYPE, 'The item is a folder, not a file.');
        }

        if ($item['size'] > self::MAX_DOWNLOAD_BYTES) {
            throw new OneDriveException(
                OneDriveErrorReasonEnum::TOO_LARGE,
                'The file is larger than the 20 MB download limit.'
            );
        }

        $response = $this->request()->get(self::BASE_URL . '/me/drive/items/' . rawurlencode($item['id']) . '/content');
        $this->throwIfFailed($response);

        return [
            'item' => $item,
            'content' => $response->body(),
        ];
    }

    public function replace(string $itemId, string $content, string $etag): array
    {
        $itemUrl = $this->itemUrl($itemId);

        if (strlen($content) <= self::SIMPLE_UPLOAD_MAX_BYTES) {
            $response = $this->request()
                ->withHeaders(['If-Match' => $etag])
                ->withBody($content, 'application/octet-stream')
                ->put(self::BASE_URL . $itemUrl . '/content');

            return $this->item($this->json($response));
        }

        return $this->uploadInSession(
            $itemUrl,
            $content,
            'replace',
            ['If-Match' => $etag]
        );
    }

    public function createFile(?string $folderPathOrId, string $name, string $content): array
    {
        $fileUrl = $this->childUrl($folderPathOrId, $name);

        if (strlen($content) <= self::SIMPLE_UPLOAD_MAX_BYTES) {
            $response = $this->request()
                ->withBody($content, 'application/octet-stream')
                ->put(self::BASE_URL . $fileUrl . '/content?@microsoft.graph.conflictBehavior=fail');

            return $this->item($this->json($response));
        }

        return $this->uploadInSession($fileUrl, $content, 'fail');
    }

    public function createFolder(?string $parentPathOrId, string $name): array
    {
        $response = $this->request()->post(self::BASE_URL . $this->itemUrl($parentPathOrId) . '/children', [
            'name' => $name,
            'folder' => (object) [],
            '@microsoft.graph.conflictBehavior' => 'fail',
        ]);

        return $this->item($this->json($response));
    }

    public function delete(string $pathOrId): void
    {
        // An id (or a path Graph resolves) can still name the root, which only the item's facet reveals.
        $item = self::isRootPath($pathOrId) ? null : $this->getRawItem($pathOrId);
        if ($item === null || array_key_exists('root', $item) || $this->isConfiguredRoot(DriveItem::fromGraph($item)['path'])) {
            throw new OneDriveException(
                OneDriveErrorReasonEnum::REQUEST_FAILED,
                'Refusing to delete the OneDrive root folder'
            );
        }

        $itemUrl = '/me/drive/items/' . rawurlencode((string) ($item['id'] ?? ''));
        $response = $this->request()->delete(self::BASE_URL . $itemUrl);
        $this->throwIfFailed($response);
    }

    private function getRawItem(string $pathOrId): array
    {
        $item = $this->json($this->request()->get(self::BASE_URL . $this->unguardedItemUrl($pathOrId)));

        if (! $this->isInsideRoot($item)) {
            throw new OneDriveException(
                OneDriveErrorReasonEnum::OUTSIDE_ALLOWED_FOLDER,
                'That item is outside the OneDrive folder this app allows. Only items under "/" can be used.'
            );
        }

        return $item;
    }

    private function uploadInSession(
        string $itemUrl,
        string $content,
        string $conflictBehavior,
        array $headers = []
    ): array {
        $session = $this->request()
            ->withHeaders($headers)
            ->post(self::BASE_URL . $itemUrl . '/createUploadSession', [
                'item' => ['@microsoft.graph.conflictBehavior' => $conflictBehavior],
            ]);
        $uploadUrl = (string) ($this->json($session)['uploadUrl'] ?? '');
        if ($uploadUrl === '') {
            throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'OneDrive did not return an upload URL');
        }

        $total = strlen($content);
        $offset = 0;
        $response = null;

        while ($offset < $total) {
            $chunk = substr($content, $offset, self::CHUNK_BYTES);
            $end = $offset + strlen($chunk) - 1;

            // The uploadUrl is pre-authenticated: sending the Bearer token to it is rejected.
            $response = Http::timeout(120)
                ->acceptJson()
                ->withHeaders(['Content-Range' => 'bytes ' . $offset . '-' . $end . '/' . $total])
                ->withBody($chunk, 'application/octet-stream')
                ->put($uploadUrl);
            $this->throwIfFailed($response);

            $offset = $end + 1;
        }

        return $this->item($this->json($response));
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->accessToken)->acceptJson()->timeout(30);
    }

    private function mapItems(Response $response): array
    {
        return array_map($this->relative(...), $this->mapAbsoluteItems($response));
    }

    private function mapAbsoluteItems(Response $response): array
    {
        return array_map(
            DriveItem::fromGraph(...),
            $this->json($response)['value'] ?? []
        );
    }

    private function item(array $graphItem): array
    {
        return $this->relative(DriveItem::fromGraph($graphItem));
    }

    private function relative(array $item): array
    {
        if ($this->rootFolder !== null && $item['path'] !== null) {
            $item['path'] = $this->isConfiguredRoot($item['path'])
                ? '/'
                : substr($item['path'], strlen($this->rootFolder));
        }

        return $item;
    }

    /**
     * Fails closed: an item whose path Graph does not report (the drive root has none) is outside.
     */
    private function isInsideRoot(array $graphItem): bool
    {
        if ($this->rootFolder === null) {
            return true;
        }

        $path = DriveItem::fromGraph($graphItem)['path'];

        return $this->isConfiguredRoot($path) || self::isStrictlyUnder($path, $this->rootFolder);
    }

    private static function isStrictlyUnder(?string $path, string $folder): bool
    {
        return $path !== null
            && str_starts_with(mb_strtolower($path), mb_strtolower(rtrim($folder, '/')) . '/');
    }

    private function isConfiguredRoot(?string $path): bool
    {
        return $this->rootFolder !== null && $path !== null
            && mb_strtolower(rtrim($path, '/')) === mb_strtolower($this->rootFolder);
    }

    private function absolutePath(string $path): string
    {
        return ($this->rootFolder ?? '') . self::normalizePath($path);
    }

    /**
     * "/" for the root, otherwise "/a/b" with empty segments dropped. A dot segment may be resolved
     * against its parent ("/Docs/.." naming the root), so none is accepted.
     */
    private static function normalizePath(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path)), fn (string $segment) => trim($segment) !== ''));

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new OneDriveException(
                    OneDriveErrorReasonEnum::REQUEST_FAILED,
                    'Paths may not contain "." or ".." segments'
                );
            }
        }

        return '/' . implode('/', $segments);
    }

    private function json(Response $response): array
    {
        $this->throwIfFailed($response);

        return $response->json() ?? [];
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->status() < 400) {
            return;
        }

        $graphMessage = (string) ($response->json('error.message') ?? '');

        /*
         * Graph names an account without a drive only in prose: a work tenant with no SharePoint
         * licence answers 400, a work account whose OneDrive was never provisioned answers 404.
         */
        if (Str::contains($graphMessage, ['SPO license', 'mysite not found'], ignoreCase: true)) {
            throw new OneDriveException(
                OneDriveErrorReasonEnum::ONEDRIVE_NOT_AVAILABLE,
                'This Microsoft account has no OneDrive. Connect a personal Microsoft account or a work account with a OneDrive licence.'
            );
        }

        [$reason, $message] = match ($response->status()) {
            401, 403 => [
                OneDriveErrorReasonEnum::PERMISSION_MISSING,
                'Microsoft file access is missing. Reconnect Microsoft to grant access to files.',
            ],
            404 => [OneDriveErrorReasonEnum::NOT_FOUND, 'The file or folder was not found in OneDrive.'],
            409 => [OneDriveErrorReasonEnum::NAME_CONFLICT, 'An item with that name already exists.'],
            412 => [
                OneDriveErrorReasonEnum::FILE_CHANGED,
                'The file changed since it was read. Read it again before editing.',
            ],
            423 => [
                OneDriveErrorReasonEnum::FILE_LOCKED,
                'The file is open in another session (Word or Excel). Ask the user to close it, then try again.',
            ],
            default => [
                OneDriveErrorReasonEnum::REQUEST_FAILED,
                $graphMessage !== '' ? $graphMessage : 'OneDrive request failed (HTTP ' . $response->status() . ').',
            ],
        };

        throw new OneDriveException($reason, $message);
    }

    /**
     * Under a configured root an id is fetched once first, so it is refused before anything is
     * written to it.
     */
    private function itemUrl(?string $pathOrId): string
    {
        if ($this->rootFolder !== null && ! self::isRootPath($pathOrId) && ! str_starts_with($pathOrId, '/')) {
            $this->getRawItem($pathOrId);
        }

        return $this->unguardedItemUrl($pathOrId);
    }

    private function unguardedItemUrl(?string $pathOrId): string
    {
        if (self::isRootPath($pathOrId)) {
            return $this->rootFolder === null
                ? '/me/drive/root'
                : '/me/drive/root:' . $this->encodePath($this->rootFolder) . ':';
        }

        if (str_starts_with($pathOrId, '/')) {
            return '/me/drive/root:' . $this->encodePath($this->absolutePath($pathOrId)) . ':';
        }

        return '/me/drive/items/' . rawurlencode($pathOrId);
    }

    private function childUrl(?string $folderPathOrId, string $name): string
    {
        $folder = $this->itemUrl($folderPathOrId);
        $encodedName = $this->encodeSegment($name);

        if (str_ends_with($folder, ':')) {
            return substr($folder, 0, -1) . '/' . $encodedName . ':';
        }

        return $folder . ':/' . $encodedName . ':';
    }

    private function encodePath(string $path): string
    {
        $segments = array_filter(explode('/', $path), fn (string $segment) => $segment !== '');

        return '/' . implode('/', array_map($this->encodeSegment(...), $segments));
    }

    /**
     * A dot segment may be resolved against its parent ("/Docs/.." naming the root), so none is sent.
     */
    private function encodeSegment(string $segment): string
    {
        if ($segment === '.' || $segment === '..') {
            throw new OneDriveException(
                OneDriveErrorReasonEnum::REQUEST_FAILED,
                'Paths may not contain "." or ".." segments'
            );
        }

        return rawurlencode($segment);
    }
}
