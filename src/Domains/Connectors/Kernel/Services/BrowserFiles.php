<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Kernel\Services;

use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Support\McpToolResult;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\Integrations;

/**
 * Reads files out of a Kernel browser VM over the agent's own MCP connection, so no second credential
 * is needed. Kernel deletes a session's filesystem when the session ends, so this only works while the
 * browser is alive.
 */
class BrowserFiles
{
    public const string SERVER = 'kernel_mcp';

    public const array DEFAULT_DIRECTORIES = ['/home/kernel/Downloads', '/tmp'];

    /** Everything else a browser VM leaves around — X11 locks, the agent's own scripts — is noise on a plan. */
    public const array COLLECTABLE_EXTENSIONS = ['csv', 'xlsx', 'xls', 'json', 'pdf', 'txt', 'xml', 'zip'];

    /** base64 inflates by a third and crosses the MCP response as text; past this, export a narrower range. */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    private const string FIND_TEMPLATE = 'find %s -maxdepth 2 -type f -newermt "-12 hours" -printf "%%s\t%%p\n" 2>/dev/null | sort -rn';

    public function __construct(private readonly Agent $agent)
    {
    }

    public static function integration(): ?Integrations
    {
        /** @var Integrations|null $integration */
        $integration = Integrations::query()
            ->where('name', self::SERVER)
            ->where('apps_id', 0)
            ->first();

        return $integration;
    }

    /**
     * @param list<string> $directories
     * @return list<array{path: string, size: int}>
     */
    public function recent(string $sessionId, array $directories = self::DEFAULT_DIRECTORIES): array
    {
        $result = $this->exec($sessionId, 'sh', ['-c', sprintf(self::FIND_TEMPLATE, implode(' ', $directories))]);
        $files = [];

        foreach (explode("\n", trim((string) ($result['stdout'] ?? ''))) as $line) {
            [$size, $path] = array_pad(explode("\t", trim($line), 2), 2, null);

            if ($path === null || ! is_numeric($size) || ! self::isCollectable($path)) {
                continue;
            }

            $files[] = ['path' => $path, 'size' => (int) $size];
        }

        return $files;
    }

    /** Only the sweep filters; a path named explicitly through `save_browser_file` is taken as asked. */
    public static function isCollectable(string $path): bool
    {
        $name = basename(str_replace('\\', '/', $path));

        // `.crdownload` is Chrome mid-download: saving it stores a truncated file that looks complete.
        return ! str_starts_with($name, '.')
            && ! str_ends_with($name, '.crdownload')
            && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::COLLECTABLE_EXTENSIONS, true);
    }

    public function sizeOf(string $sessionId, string $path): ?int
    {
        $result = $this->exec($sessionId, 'stat', ['-c', '%s', $path]);
        $size = trim((string) ($result['stdout'] ?? ''));

        return ($result['exit_code'] ?? 1) === 0 && is_numeric($size) ? (int) $size : null;
    }

    /** Base64 as it left the VM, ready for `createFileSystemFromBase64`. */
    public function read(string $sessionId, string $path): ?string
    {
        $result = $this->exec($sessionId, 'base64', ['-w0', $path]);
        $encoded = trim((string) ($result['stdout'] ?? ''));

        return ($result['exit_code'] ?? 1) === 0 && $encoded !== '' ? $encoded : null;
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function exec(string $sessionId, string $command, array $args): array
    {
        $integration = self::integration();

        if ($integration === null) {
            return [];
        }

        $content = new McpConnectionService($this->agent, $integration)
            ->connector()
            ->callRemoteTool('exec_command', [
                'session_id' => $sessionId,
                'command' => $command,
                'args' => $args,
            ]);

        return McpToolResult::json($content) ?? [];
    }
}
