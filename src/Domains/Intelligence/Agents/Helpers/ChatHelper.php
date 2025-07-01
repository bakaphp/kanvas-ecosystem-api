<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

use Baka\Support\Str;

class ChatHelper
{
    public static function extractTextFromResponse(string $response): string
    {
        // First try: direct JSON parsing if the entire response is JSON
        if (Str::isJson($response)) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // If there's a specific 'response' field, return it directly
                if (isset($data['response'])) {
                    return $data['response'];
                }

                // Otherwise, join all string values with line breaks
                $result = '';
                foreach ($data as $key => $value) {
                    if (is_string($value)) {
                        $result .= $value . "\n\n";
                    }
                }

                return trim($result); // Remove trailing newlines
            }
        }

        // Second try: Check if response has markdown code blocks with JSON
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            $jsonString = $matches[1];
            $data = json_decode($jsonString, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // If there's a specific 'response' field, return it
                if (isset($data['response'])) {
                    return $data['response'];
                }

                // Otherwise, join all string values with line breaks
                $result = '';
                foreach ($data as $key => $value) {
                    if (is_string($value)) {
                        $result .= $value . "\n\n";
                    }
                }

                return trim($result); // Remove trailing newlines
            }
        }

        // Third try: Handle JSON without code blocks or with malformed blocks
        // Find anything that looks like a JSON object
        if (preg_match('/\{.*"response"\s*:\s*"(.*?)"\s*(?:,.*?)?\}/s', $response, $matches)) {
            // This handles cases where we have: {"response": "text"} anywhere in the string
            return str_replace('\n', "\n", $matches[1]); // Handle escaped newlines
        }

        // Fourth try: Look for a JSON string anywhere in the response
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $possibleJson = $matches[0];
            $data = json_decode($possibleJson, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // If there's a specific 'response' field, return it
                if (isset($data['response'])) {
                    return $data['response'];
                }

                // Otherwise, join all string values with line breaks
                $result = '';
                foreach ($data as $key => $value) {
                    if (is_string($value)) {
                        $result .= $value . "\n\n";
                    }
                }

                return trim($result); // Remove trailing newlines
            }
        }

        // Fifth try: Strip markdown code block formatting and try to parse
        $cleanedResponse = preg_replace('/```(?:json)?\s*(.*)\s*```/s', '$1', $response);
        if (Str::isJson($cleanedResponse)) {
            $data = json_decode($cleanedResponse, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // If there's a specific 'response' field, return it
                if (isset($data['response'])) {
                    return $data['response'];
                }

                // Otherwise, join all string values with line breaks
                $result = '';
                foreach ($data as $key => $value) {
                    if (is_string($value)) {
                        $result .= $value . "\n\n";
                    }
                }

                return trim($result); // Remove trailing newlines
            }
        }

        // Last resort: return the original response with markdown formatting removed
        return preg_replace('/```(?:json)?\s*(.*)\s*```/s', '$1', $response);
    }
}
