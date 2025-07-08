<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
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

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($slugField, $useAI, $backupField) {
                $messageData = is_array($message->message) ? $message->message : (Str::isJson($message->message) ? json_decode($message->message, true) : []);
                $fieldToSlug = $messageData[$slugField] ?? $messageData[$backupField] ?? null;

                // If no slug field is configured OR fieldToSlug is null/empty, check if slug already exists
                if ($slugField === null || empty($fieldToSlug)) {
                    // If message already has a slug, validate and potentially improve it
                    if (! empty($message->slug)) {
                        return $this->handleExistingSlug($message, $app);
                    }

                    // No slug field and no existing slug, return error or generate from message data
                    if ($slugField === null) {
                        return ['No field configured to generate slug'];
                    }

                    if (empty($messageData) && $fieldToSlug === null) {
                        return ['No data found to generate slug for message ' . $message->id];
                    }
                }

                try {
                    $generatedSlug = $this->generateSlug($fieldToSlug !== null ? $fieldToSlug : json_encode($messageData));
                    $baseSlug = Str::limit($generatedSlug, 190, '');
                } catch (Exception $e) {
                    // Fallback to simple slug if AI generation fails
                    $baseSlug = Str::simpleSlug(Str::limit($fieldToSlug, 190, ''));
                }

                if (empty($baseSlug)) {
                    $baseSlug = (string) $message->getId();
                }

                // Check if slug is unique, if not append message ID
                $message->slug = trim($this->ensureUniqueSlug($baseSlug, $message, $app));

                if (method_exists($message, 'disableWorkflows')) {
                    $message->disableWorkflows();
                }
                $message->saveOrFail();

                return [
                    'message' => $message->id,
                    'slug' => $message->slug,
                ];
            },
            company: $message->company,
        );
    }

    private function handleExistingSlug(Model $message, AppInterface $app): array
    {
        // Check if message already has a slug
        if (empty($message->slug)) {
            // No slug exists, generate one from message ID
            $baseSlug = (string) $message->getId();
            $message->slug = trim($this->ensureUniqueSlug($baseSlug, $message, $app));
        } else {
            // Slug exists, check if it's unique and improve if duplicate
            $currentSlug = $message->slug;
            $improvedSlug = $this->ensureUniqueSlug($currentSlug, $message, $app);

            // Only update if the slug was improved (made unique)
            if ($improvedSlug !== $currentSlug) {
                $message->slug = trim($improvedSlug);
            }
        }

        // Save the message if slug was changed
        if ($message->isDirty('slug')) {
            if (method_exists($message, 'disableWorkflows')) {
                $message->disableWorkflows();
            }
            $message->saveOrFail();
        }

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

    private function ensureUniqueSlug(string $baseSlug, Model $message, AppInterface $app): string
    {
        // Build the query to check for existing slugs in the same app
        $query = $message->newQuery()
            ->where('slug', $baseSlug)
            ->where('apps_id', $app->getId());

        // Exclude current message if it's being updated
        if ($message->exists) {
            $query->where('id', '!=', $message->id);
        }

        // If slug is unique, return as is
        if (! $query->exists()) {
            return $baseSlug;
        }

        // If not unique, append message ID
        return $baseSlug . '-' . $message->getId();
    }
}
