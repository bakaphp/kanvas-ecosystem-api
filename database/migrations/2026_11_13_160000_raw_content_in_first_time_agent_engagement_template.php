<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const array TEMPLATE_NAMES = [
        'first-time-agent-engagement',
        'first-time-agent-reach-out',
    ];

    public function up(): void
    {
        DB::table('email_templates')
            ->whereIn('name', self::TEMPLATE_NAMES)
            ->where('is_deleted', 0)
            ->get(['id', 'engine', 'template'])
            ->each(function (object $template): void {
                $content = (string) $template->template;
                $updatedContent = $template->engine === 'blade'
                    ? preg_replace('/\{\{\s*\$content\s*\}\}/', '{!! $content !!}', $content)
                    : $this->makeTwigContentRaw($content);

                if ($updatedContent !== null && $updatedContent !== $content) {
                    DB::table('email_templates')
                        ->where('id', $template->id)
                        ->update(['template' => $updatedContent]);
                }
            });
    }

    public function down(): void
    {
    }

    private function makeTwigContentRaw(string $template): string
    {
        $template = preg_replace('/\{\{\s*\$content\s*\}\}/', '{{ content|raw }}', $template) ?? $template;

        return preg_replace_callback(
            '/\{\{\s*content\s*(\|\s*raw)?\s*\}\}/',
            static fn (array $match): string => ($match[1] ?? '') !== '' ? $match[0] : '{{ content|raw }}',
            $template
        ) ?? $template;
    }
};
