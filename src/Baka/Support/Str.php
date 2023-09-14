<?php

declare(strict_types=1);

namespace Baka\Support;

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
    public static function cleanup(string $string): string
    {
        return preg_replace("/[^a-zA-Z0-9_\s]/", '', $string);
    }

    /**
     * Given a json string decode it into array.
     *
     * @param mixed $string
     *
     * @return mixed
     */
    public static function jsonToArray($string): mixed
    {
        return is_string($string) && self::isJson($string) ? json_decode($string, true) : $string;
    }

    /**
     * Generate none-unicode slugs for simple parsing.
     *
     * @param string $string
     *
     * @return string
     */
    public static function simpleSlug(string $string): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
    }

    public static function sanitizePhoneNumber(?string $phone) : string
    {
        return $phone ? preg_replace('/\D+/', '', $phone) : '';
    }
}
