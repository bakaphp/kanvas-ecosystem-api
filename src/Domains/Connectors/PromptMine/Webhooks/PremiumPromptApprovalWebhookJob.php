<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Webhooks;

use DOMDocument;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PremiumPromptApprovalWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        // Extract HTML content from payload
        $htmlContent = $this->getHtmlContentFromPayload($payload);

        if ($htmlContent === null) {
            return [
                'success' => false,
                'message' => 'No HTML content found in webhook payload',
                'payload' => $payload,
            ];
        }

        // Parse approval hash from HTML
        $approvalHash = $this->parseApprovalHashFromHtml($htmlContent);

        $messageApproval = Message::getByCustomField(
            'premium_hash',
            $approvalHash,
        );

        if ($messageApproval) {
            $messageApproval->set('premium_approval', true);

            return [
                'success' => true,
                'message' => 'Premium prompt approved successfully',
                'data' => $messageApproval,
            ];
        }

        return [
            'success' => false,
            'message' => 'No premium hash found in HTML content',
            'html_content' => $htmlContent,
        ];
    }

    /**
     * Extract HTML content from webhook payload.
     */
    private function getHtmlContentFromPayload(array $payload): ?string
    {
        // Check common payload structures for HTML content
        $htmlKeys = ['html', 'email_html', 'content', 'body', 'message_content'];

        foreach ($htmlKeys as $key) {
            if (isset($payload[$key]) && ! empty($payload[$key])) {
                return $payload[$key];
            }
        }

        // Check nested structures
        if (isset($payload['email']) && is_array($payload['email'])) {
            foreach ($htmlKeys as $key) {
                if (isset($payload['email'][$key]) && ! empty($payload['email'][$key])) {
                    return $payload['email'][$key];
                }
            }
        }

        // Check if the entire payload is HTML
        if (is_string($payload) && strip_tags($payload) !== $payload) {
            return $payload;
        }

        return null;
    }

    /**
     * Parse approval hash from HTML content.
     * Looks for <h3>Approval Hash:</h3> followed by <p> tag containing the hash.
     */
    private function parseApprovalHashFromHtml(string $htmlContent): ?string
    {
        try {
            // Create DOMDocument for parsing
            $dom = new DOMDocument();

            // Suppress warnings for malformed HTML
            libxml_use_internal_errors(true);

            // Load HTML content
            $dom->loadHTML($htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            // Restore error handling
            libxml_clear_errors();

            // Find all h3 elements
            $h3Elements = $dom->getElementsByTagName('h3');

            foreach ($h3Elements as $h3) {
                // Check if h3 contains "Approval Hash:"
                if (stripos($h3->textContent, 'Approval Hash') !== false) {
                    // Look for the next sibling element (should be <p>)
                    $nextSibling = $h3->nextSibling;

                    // Skip text nodes (whitespace, etc.)
                    while ($nextSibling && $nextSibling->nodeType === XML_TEXT_NODE) {
                        $nextSibling = $nextSibling->nextSibling;
                    }

                    // Check if next element is a <p> tag
                    if ($nextSibling && $nextSibling->nodeName === 'p') {
                        $hashValue = trim($nextSibling->textContent);

                        // Validate hash format (should be 32 characters alphanumeric)
                        if ($this->isValidApprovalHash($hashValue)) {
                            return $hashValue;
                        }
                    }
                }
            }

            // Fallback: try regex pattern matching
            return $this->parseApprovalHashWithRegex($htmlContent);
        } catch (Exception $e) {
            Log::warning('Failed to parse HTML with DOMDocument, trying regex fallback', [
                'error' => $e->getMessage(),
            ]);

            return $this->parseApprovalHashWithRegex($htmlContent);
        }
    }

    /**
     * Fallback method using regex to extract approval hash.
     */
    private function parseApprovalHashWithRegex(string $htmlContent): ?string
    {
        // Pattern to match "Approval Hash:" followed by hash value
        $patterns = [
            '/<h3[^>]*>Approval Hash:<\/h3>\s*<p[^>]*>([a-zA-Z0-9]{32})<\/p>/i',
            '/Approval Hash:\s*<\/h3>\s*<p[^>]*>([a-zA-Z0-9]{32})<\/p>/i',
            '/Approval Hash[:\s]*([a-zA-Z0-9]{32})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $htmlContent, $matches)) {
                $hashValue = trim($matches[1]);

                if ($this->isValidApprovalHash($hashValue)) {
                    return $hashValue;
                }
            }
        }

        return null;
    }

    /**
     * Validate approval hash format.
     */
    private function isValidApprovalHash(string $hash): bool
    {
        return preg_match('/^[a-zA-Z0-9]{32}$/', $hash) === 1;
    }
}
