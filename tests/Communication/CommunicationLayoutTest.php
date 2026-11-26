<?php

declare(strict_types=1);

namespace Tests\Communication;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCaseUnit;

/**
 * The domain is only vendor-neutral while it cannot see a vendor. Providers live under Connectors,
 * implement the contracts and are named in `config/communication.php`; one direct import and the
 * next provider is added by editing the domain instead of by adding a connector.
 */
final class CommunicationLayoutTest extends TestCaseUnit
{
    private const string ROOT = 'src/Domains/Communication';
    private const string CONNECTOR_NAMESPACE = '/^Kanvas\\\\Connectors\\\\/';

    public function testTheDomainImportsNoConnector(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(self::ROOT) as $file) {
            foreach ($this->imports(file_get_contents($file)) as $import) {
                if (preg_match(self::CONNECTOR_NAMESPACE, $import) === 1) {
                    $offenders[] = $this->relative($file) . ' imports ' . $import;
                }
            }
        }

        $this->assertSame([], $offenders, "The communication domain must not import a connector:\n - " . implode("\n - ", $offenders));
    }

    public function testImportsAreReadFromEveryFormOfUseStatement(): void
    {
        $source = <<<'PHP'
            <?php

            namespace Kanvas\Communication\Messages\Actions;

            use Kanvas\Connectors\Twilio\SmsTransport;
            use Kanvas\Connectors\Mailgun\{Client, EmailTransport as Mail};
            use Kanvas\Communication\Contracts\Transport as TransportContract;

            class Example
            {
                use SomeTrait;
            }
            PHP;

        $this->assertSame(
            [
                'Kanvas\Connectors\Twilio\SmsTransport',
                'Kanvas\Connectors\Mailgun\Client',
                'Kanvas\Connectors\Mailgun\EmailTransport',
                'Kanvas\Communication\Contracts\Transport',
            ],
            $this->imports($source)
        );
    }

    /**
     * @return list<string>
     */
    private function imports(string $source): array
    {
        preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;]+);/m', $source, $statements);

        $imports = [];

        foreach ($statements[1] as $statement) {
            if (preg_match('/^(.+\\\\)\{(.+)\}$/s', trim($statement), $group) === 1) {
                foreach (explode(',', $group[2]) as $member) {
                    $imports[] = $group[1] . $this->withoutAlias($member);
                }

                continue;
            }

            $imports[] = $this->withoutAlias($statement);
        }

        return $imports;
    }

    private function withoutAlias(string $import): string
    {
        return ltrim(trim(preg_split('/\s+as\s+/i', trim($import))[0]), '\\');
    }

    /**
     * @return iterable<string>
     */
    private function phpFiles(string $path): iterable
    {
        if (! is_dir(base_path($path))) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($path), FilesystemIterator::SKIP_DOTS)
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
