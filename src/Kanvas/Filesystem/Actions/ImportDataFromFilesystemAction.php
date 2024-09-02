<?php
declare(strict_types=1);
 use Illuminate\Support\Str;

private function mapper(array $template, array $data): array
{
    $result = [];

    foreach ($template as $key => $value) {
        switch (true) {
            case is_array($value):
                $result[$key] = $this->mapper($value, $data);
                break;

            case is_string($value) && Str::startsWith($value, '_'):
                $result[$key] = Str::after($value, '_');
                break;

            case is_string($value):
                $result[$key] = $data[$value] ?? null;
                break;

            default:
                $result[$key] = $value;
        }
    }

    return $result;
}