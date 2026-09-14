<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCaseUnit;

/**
 * Coverage ratchet for SSRF protection.
 *
 * Fails CI when a file under src/ or app/ uses a raw remote-fetch primitive
 * (file_get_contents() on a non-literal, or curl) without going through the SSRF guard
 * (Baka\Http\SafeUrl / SafeUrlFetcher). New code must either use the guard or be added to
 * the allow-list below with a justification — so a fetch can never ship unguarded by accident.
 *
 * A file "passes" if it references SafeUrl (treated as guarded) or is explicitly allow-listed.
 * The allow-list holds the legitimate non-SSRF cases: local-file reads, hardcoded service
 * endpoints, and admin-configured CLI commands — none of which fetch a user-influenced URL.
 */
final class NoUnguardedUrlFetchTest extends TestCaseUnit
{
    /**
     * @var list<string>
     */
    private const ALLOWLIST = [
        // Admin-configured WSDL endpoint (S3), not user input.
        'src/Domains/Connectors/UniversalAssistance/Client.php',
        // Reads local docker-compose templates from disk.
        'src/Domains/Intelligence/AgentRuntime/Services/BaseDockerComposeBuilderService.php',
        // Reads local PHP source files for attribute discovery.
        'src/Baka/Discovery/AttributeClassDiscovery.php',
        // CLI: reads a local JSON file argument.
        'app/Console/Commands/Ecosystem/ImportEmailTemplatesCommand.php',
        // CLI: reads a local Item Balance XML file argument.
        'app/Console/Commands/Connectors/Yusen/YusenInventoryReportCommand.php',
        // CLI: operates on local temp files.
        'app/Console/Commands/Connectors/ScrapperApi/CleanScrapperImageCommand.php',
        // CLI: reads a local agent-type definition file.
        'app/Console/Commands/Intelligence/Agents/CreateAgentTypeCommand.php',
        // Hardcoded SightEngine moderation API URL from config.
        'src/Domains/Connectors/SightEngine/Services/ContentModerationService.php',
        // Local PDF generation.
        'src/Baka/Support/PdfGenerator.php',
        // Local PDF merge.
        'src/Baka/Helpers/MergePdf.php',
        // CLI: admin-configured WordPress endpoint.
        'app/Console/Commands/Connectors/WordPress/DownloadWordPressInventoryCommand.php',
        // Reads a local temp file it created itself (tempnam()) to build a backup ZIP —
        // never a remote or user-influenced path.
        'src/Domains/Intelligence/Agents/Services/AgentConfigBackupService.php',
        // Reads the admin-configured Google OAuth token file from disk, gated by is_file().
        'src/Domains/Connectors/Google/Actions/CreateGoogleCalendarMeetingAction.php',
        // CLI: reads a local golden-set JSON file from the --file option, gated by is_readable().
        'app/Console/Commands/Inventory/EvaluateProductDiscoveryCommand.php',
        // Reads the local PHP source of a reflected tool class (ReflectionClass::getFileName()) to
        // tokenise its description argument, gated by is_readable().
        'src/Domains/Intelligence/Agents/Services/AgentToolDiscoveryService.php',
    ];

    public function testNoUnguardedRemoteUrlFetch(): void
    {
        $base = base_path();
        $offenders = [];

        foreach (['src', 'app'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base . '/' . $dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = ltrim(str_replace($base, '', $file->getPathname()), '/');

                if (in_array($relativePath, self::ALLOWLIST, true)) {
                    continue;
                }

                $code = file_get_contents($file->getPathname());

                // Files that reference the guard are considered protected.
                if (str_contains($code, 'SafeUrl')) {
                    continue;
                }

                $hasRawFetch = preg_match('/file_get_contents\(\s*[^\'"\s]/', $code) === 1
                    || preg_match('/curl_exec\(|curl_init\(/', $code) === 1;

                if ($hasRawFetch) {
                    $offenders[] = $relativePath;
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'Unguarded remote-URL fetch detected. Use Baka\\Http\\SafeUrlFetcher::fetch() '
            . '(or SafeUrl::assertSafe() before the request), or add the file to the '
            . 'allow-list in this test with a justification if it is a local-file / '
            . "hardcoded / admin-config read:\n - " . implode("\n - ", $offenders)
        );
    }
}
