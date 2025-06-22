<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

/**
 * @todo move to the social domain
 */
class GenerateMessageSlugActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $slugField = $params['field'] ?? null;
        $useAI = $params['use_ai'] ?? false;
        $backupField = $params['backup_field'] ?? null;

        if ($slugField === null) {
            return ['No field configured to generate slug'];
        }

        $messageData = is_array($message->message) ? $message->message : (Str::isJson($message->message) ? json_decode($message->message, true) : []);
        $fieldToSlug = $messageData[$slugField] ?? $messageData[$backupField] ?? null;

        /*   if ($fieldToSlug === null) {
              //return ['No slug field {' . $slugField . ' found in message ' . $message->id];
              $fieldToSlug = (string) $message->id;
          } */

        if (empty($messageData) && $fieldToSlug === null) {
            return ['No data found to generate slug for message ' . $message->id];
        }

        try {
            $generatedSlug = $this->generateSlug($fieldToSlug !== null ? $fieldToSlug : json_encode($messageData));
            $message->slug = Str::limit($generatedSlug, 190, '');
        } catch (Exception $e) {
            // Fallback to simple slug if AI generation fails
            $message->slug = Str::simpleSlug(Str::limit($fieldToSlug, 190, ''));
        }

        if (method_exists($message, 'disableWorkflows')) {
            $message->disableWorkflows();
        }
        $message->saveOrFail();

        return [
            'message' => $message->id,
            'slug' => $message->slug,
        ];
    }

    private function generateSlug(string $text): string
    {
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt('Generate a concise, URL-friendly slug based on this prompt: ' . $text . '. Only return the slug in lowercase, separated by hyphens. Do not include quotes, suggestions, or extra text.')
            ->asText();

        return str_replace(['```', 'json'], '', $response->text);
    }
}
