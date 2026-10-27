<?php

declare(strict_types=1);

namespace Tests\Workflow\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCaseUnit;

/**
 * Stored expressions (rule conditions, notification logic, engagement messages) evaluate through
 * Kanvas\Workflow\Rules\Support\SafeExpression, which refuses calls and reads plain data only.
 */
final class ExpressionLanguageIsConfinedTest extends TestCaseUnit
{
    private const string ALLOWED = 'src/Domains/Workflow/Rules/Support/SafeExpression.php';

    public function testOnlySafeExpressionUsesExpressionLanguage(): void
    {
        $base = base_path();
        $offenders = [];

        foreach (['src', 'app'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base . '/' . $dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $relativePath = ltrim(str_replace($base, '', $file->getPathname()), '/');

                if ($file->getExtension() !== 'php' || $relativePath === self::ALLOWED) {
                    continue;
                }

                if (str_contains(file_get_contents($file->getPathname()), 'Symfony\\Component\\ExpressionLanguage')) {
                    $offenders[] = $relativePath;
                }
            }
        }

        $this->assertSame([], $offenders, "Evaluate stored expressions with SafeExpression:\n - " . implode("\n - ", $offenders));
    }
}
