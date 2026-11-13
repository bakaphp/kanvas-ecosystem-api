<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCaseUnit;

/**
 * Ratchet for "a domain never imports a connector" (.claude/CLAUDE.md): domain and core code reach a
 * vendor through a contract or Communication, never by importing it. A vendor is anything under
 * Kanvas\Connectors and the provider trees of Communication (Channels/{Medium}/Providers), which a
 * move into the domain would otherwise hide from the count.
 *
 * Today's offenders are listed in fixtures/domain-vendor-imports.txt. A new one fails here; so does a
 * listed file that was fixed, so the list only shrinks.
 */
final class DomainDependencyDirectionTest extends TestCaseUnit
{
    private const string VENDOR_IMPORT = '/^use Kanvas\\\\(Connectors\\\\|Communication\\\\Channels\\\\[A-Za-z]+\\\\Providers\\\\)/m';

    private const string VENDOR_TREE = '#^src/Domains/(Connectors/|Communication/Channels/[A-Za-z]+/Providers/)#';

    public function testNoNewDomainImportsAVendor(): void
    {
        $offenders = array_diff($this->importers(), $this->allowList());

        $this->assertSame(
            [],
            array_values($offenders),
            "These files import a vendor (Kanvas\\Connectors or a Communication provider) directly.\n"
            . "Depend on a contract the domain owns, or send through Communication::for(), instead:\n"
            . implode("\n", $offenders)
        );
    }

    public function testTheAllowListOnlyShrinks(): void
    {
        $fixed = array_diff($this->allowList(), $this->importers());

        $this->assertSame(
            [],
            array_values($fixed),
            "These files no longer import a vendor; remove them from fixtures/domain-vendor-imports.txt:\n"
            . implode("\n", $fixed)
        );
    }

    /**
     * @return list<string>
     */
    private function importers(): array
    {
        $base = base_path();
        $importers = [];

        foreach (['src/Domains', 'src/Kanvas'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base . '/' . $dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = ltrim(str_replace($base, '', $file->getPathname()), '/');

                if (preg_match(self::VENDOR_TREE, $relativePath) === 1) {
                    continue;
                }

                if (preg_match(self::VENDOR_IMPORT, (string) file_get_contents($file->getPathname())) === 1) {
                    $importers[] = $relativePath;
                }
            }
        }

        sort($importers);

        return $importers;
    }

    /**
     * @return list<string>
     */
    private function allowList(): array
    {
        $lines = file(__DIR__ . '/fixtures/domain-vendor-imports.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_values(array_filter(
            array_map('trim', $lines),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')
        ));
    }
}
