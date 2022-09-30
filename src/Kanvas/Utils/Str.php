<?php

declare(strict_types=1);

namespace Kanvas\Utils;

use Illuminate\Support\Str as IlluminateStr;

class Str extends IlluminateStr
{
    /**
     * Given a string remove all any special characters.
     *
     * @param string $string
     *
     * @return string
     */
    public static function cleanup(string $string) : string
    {
        return preg_replace("/[^a-zA-Z0-9_\s]/", '', $string);
    }

    /**
     * Given a json string decode it into array.
     *
     * @param mixed $string
     *
     * @return array|?string|mixed
     */
    public static function jsonToArray($string)
    {
        return is_string($string) && self::isJson($string) ? json_decode($string, true) : $string;
    }
}
