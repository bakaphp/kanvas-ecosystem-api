<?php

declare(strict_types=1);

namespace Tests\Unit\Templates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCaseUnit;

/**
 * Stored text renders through Kanvas\Templates\Support\TemplateSandbox. Blade compiles only the
 * template rows marked blade by hand, and RenderTemplateAction is the one place that decides that.
 */
final class BladeRenderingIsConfinedTest extends TestCaseUnit
{
    /**
     * @var array<string, int> file => Blade or eval calls it may hold
     */
    private const array ALLOWLIST = [
        'src/Kanvas/Templates/Actions/RenderTemplateAction.php' => 3,
    ];

    public function testOnlyTheTemplateRendererCompilesBlade(): void
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
                $calls = preg_match_all(
                    '/Blade::(?:render|compileString)\(|\beval\(/',
                    file_get_contents($file->getPathname())
                );

                if ($calls > (self::ALLOWLIST[$relativePath] ?? 0)) {
                    $offenders[] = $relativePath;
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "Render stored text with TemplateSandbox, or with RenderTemplateAction for a template row:\n - "
            . implode("\n - ", $offenders)
        );
    }
}
