<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Support;

final class AutoSubmitChallengeForm
{
    public static function render(string $action, array $fields): string
    {
        $action = self::escape($action);
        $inputs = '';

        foreach ($fields as $name => $value) {
            $inputs .= '<input type="hidden" name="' . self::escape((string) $name) . '" value="' . self::escape((string) $value) . '">';
        }

        return '<html><body onload="document.forms[0].submit()">'
            . '<form name="frm" method="POST" action="' . $action . '">' . $inputs . '</form>'
            . '</body></html>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
