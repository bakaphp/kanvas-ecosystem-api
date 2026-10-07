<?php

declare(strict_types=1);

namespace Tests\Connectors;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCaseUnit;

/**
 * Coverage ratchet for integration teardown.
 *
 * Disconnecting an integration runs the handler's teardown(), which only knows the company
 * settings the handler declares in companySettingKeys(). A handler that writes a company setting
 * in setup() without declaring it leaves the credential live after the tenant disconnects.
 */
final class HandlersDeclareSettingKeysTest extends TestCaseUnit
{
    public function testEveryHandlerThatWritesCompanySettingsDeclaresThem(): void
    {
        $offenders = [];

        foreach ($this->handlerFiles() as $file) {
            $source = file_get_contents($file);

            if (! str_contains($source, 'extends BaseIntegration')) {
                continue;
            }

            $writesCompanySettings = preg_match('/\$this->company->set(?:Encrypted)?\(/', $source) === 1;

            if ($writesCompanySettings && ! str_contains($source, 'function companySettingKeys(): array')) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These handlers write company settings in setup() but do not declare them in companySettingKeys(), so disconnecting leaves the credentials live:\n - "
                . implode("\n - ", $offenders)
        );
    }

    /**
     * @return iterable<string>
     */
    private function handlerFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                base_path('src/Domains/Connectors'),
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                yield $file->getPathname();
            }
        }
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(base_path(), '', $file), '/');
    }
}
