<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Support;

final class AutoSubmitForm
{
    public static function render(string $action, array $fields): string
    {
        $inputs = array_map(
            fn (string $name, string $value): string => sprintf(
                '<input type="hidden" name="%s" value="%s">',
                self::escape($name),
                self::escape($value)
            ),
            array_keys($fields),
            array_values($fields)
        );
        $inputs = implode("\n    ", $inputs);
        $action = self::escape($action);

        return <<<HTML
            <html><body onload="document.forms[0].submit()">
            <form name="frm" method="POST" action="{$action}">
                {$inputs}
            </form>
            </body></html>
            HTML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
