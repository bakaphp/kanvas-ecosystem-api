<?php

declare(strict_types=1);

namespace Kanvas\Utils;

use Illuminate\Support\Str as IlluminateStr;

class Str extends IlluminateStr
{
    /**
     * Converts the underscore_notation to the UpperCamelCase.
     *
     * @param string $string
     * @param string $delimiter
     *
     * @return string
     */
    public static function camelize(string $string, string $delimiter = '_') : string
    {
        $delimiterArray = str_split($delimiter);
        foreach ($delimiterArray as $delimiter) {
            $stringParts = explode($delimiter, $string);
            $stringParts = array_map('strtolower', $stringParts);
            $stringParts = array_map('ucfirst', $stringParts);
            $string = implode('', $stringParts);
        }

        return $string;
    }

    /**
     * Returns the first string there is between the strings from the parameter start and end.
     *
     * @param string $haystack
     * @param string $start
     * @param string $end
     *
     * @return string
     */
    public static function firstStringBetween(string $haystack, string $start, string $end) : string
    {
        return trim((string) mb_strstr((string) mb_strstr($haystack, $start), $end, true), $start . $end);
    }

    /**
     * Lets you determine whether or not a string includes another string.
     *
     * @param string $needle
     * @param string $haystack
     *
     * @deprecated version 0.2
     *
     * @return bool
     */
    public static function includes(string $needle, string $haystack) : bool
    {
        return self::contains($haystack, $needle);
    }

    /**
     * Compare two strings and returns true if both strings are anagram, false otherwise.
     *
     * @param string $string1
     * @param string $string2
     *
     * @return bool
     */
    public static function isAnagram(string $string1, string $string2) : bool
    {
        return count_chars($string1, 1) === count_chars($string2, 1);
    }

    /**
     * Returns true if the given string is lower case, false otherwise.
     *
     * @param string $string
     *
     * @return bool
     */
    public static function isLowerCase(string $string) : bool
    {
        return $string === mb_strtolower($string);
    }

    /**
     * Returns true if the given string is upper case, false otherwise.
     *
     * @param string $string
     *
     * @return bool
     */
    public static function isUpperCase(string $string) : bool
    {
        return $string === mb_strtoupper($string);
    }

    /**
     * Returns true if the given string is a palindrome, false otherwise.
     *
     * @param string $string
     *
     * @return bool
     */
    public static function palindrome(string $string) : bool
    {
        return strrev($string) === $string;
    }

    /**
     * Returns number of vowels in provided string.
     * Use a regular expression to count the number of vowels (A, E, I, O, U) in a string.
     *
     * @param string $string
     *
     * @return int
     */
    public static function countVowels(string $string) : int
    {
        preg_match_all('/[aeiou]/i', $string, $matches);

        return \count($matches[0]);
    }

    /**
     * Decapitalizes the first letter of the sring and then adds it with rest of the string. Omit the upperRest parameter to keep the
     * rest of the string intact, or set it to true to convert to uppercase.
     *
     * @param string $string
     * @param bool $upperRest
     *
     * @return string
     */
    public static function decapitalize(string $string, bool $upperRest = false) : string
    {
        return mb_strtolower(mb_substr($string, 0, 1)) . ($upperRest ? mb_strtoupper(mb_substr($string, 1)) : mb_substr($string, 1));
    }

    /**
     *  Substring a string to specific lenght, but removing whole words.
     *
     * @param string $string
     * @param int $to
     *
     * @return string
     */
    public static function substringByWord(string $string, int $to) : string
    {
        if (mb_strlen($string) > $to && preg_match("/^.{1,$to}\b/s", $string, $matches)) {
            $string = $matches[0];
        }

        return mb_substr($string, 0, $to);
    }

    /**
     * Convert a number to an excel column letter
     * EJ: 'A'+22 = W;.
     *
     * @param string $letter Initial Letter
     * @param int $number Number of letters to increase
     *
     * @return string
     */
    public static function letterPlusNumber(string $letter, int $number) : string
    {
        for ($i = 0; $i < $number; ++$i) {
            ++$letter;
        }

        return (string) $letter;
    }

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
