<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\DraftsEmailSignatures;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RequiresHumanCaller;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Notifications\Support\SignatureHtml;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Renders the signature designs the agent wrote to PNG so the person picks one by looking at it — an image
 * link reads the same in the in-app chat, Slack and email, which raw HTML would not. Each design is cleaned
 * and kept as a draft; saving takes the draft id, never the HTML again.
 */
#[AgentTool(name: 'Preview Email Signature', category: 'system')]
class PreviewEmailSignatureTool extends Tool implements RequiresSystemAgent
{
    use DraftsEmailSignatures;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use RequiresHumanCaller;
    use TrackByInputs;

    private const int MAX_DESIGNS = 6;

    protected string $name = 'preview_email_signature';

    protected ?string $description = 'Design email signatures for the person you are talking to and show them as images BEFORE '
        . 'anything is saved. Write each design as email-safe HTML: tables for layout, inline style attributes only '
        . '(no <style>, no classes, no CSS url()), at most 600px wide, web-safe fonts. Put an image they sent you as '
        . '<img src="kanvas-file:FILESYSTEM_ID"> using the filesystem_id from its "Attached file on this message" '
        . 'marker; no other image source is accepted. Make the designs genuinely different when they ask for options. '
        . 'Show every preview image with its name and ask which one they want; when they choose, save it with '
        . 'set_my_email_signature using that design\'s draft_id.';

    /**
     * @return array<int, ToolProperty|ArrayProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'designs',
                description: 'One entry per design to show, up to ' . self::MAX_DESIGNS . '.',
                required: true,
                items: new ObjectProperty(
                    name: 'design',
                    description: 'A signature design.',
                    properties: [
                        new ToolProperty(
                            name: 'name',
                            type: PropertyType::STRING,
                            description: 'A short name to show it by, e.g. "Logo left".',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'html',
                            type: PropertyType::STRING,
                            description: 'The signature as email-safe HTML.',
                            required: true,
                        ),
                    ],
                ),
            ),
        ];
    }

    /**
     * @param array<int, array{name?: string, html?: string}> $designs
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $designs): array
    {
        $caller = $this->humanCallerOrDenial(
            'design an email signature',
            'Say plainly that no preview was made.',
            ['saved' => false],
        );

        if (is_array($caller)) {
            return $caller;
        }

        if ($designs === [] || count($designs) > self::MAX_DESIGNS) {
            return $this->invalidArgs('Send between 1 and ' . self::MAX_DESIGNS . ' designs.');
        }

        $previews = [];

        foreach (array_values($designs) as $index => $design) {
            $name = trim((string) ($design['name'] ?? '')) ?: 'Design ' . ($index + 1);
            $html = $this->sanitizedSignatureOrError((string) ($design['html'] ?? ''));

            if (is_array($html)) {
                return ['design' => $name, ...$html];
            }

            $previewUrl = $this->renderPreview($caller, $html, $index + 1);

            if (is_array($previewUrl)) {
                return $previewUrl;
            }

            $previews[] = [
                'draft_id' => $this->storeSignatureDraft($caller, $html),
                'name' => $name,
                'preview_url' => $previewUrl,
            ];
        }

        return $this->ok(
            ['previews' => $previews],
            'Show each preview image with its name and ask which one they want. Nothing is saved yet.'
        );
    }

    /**
     * @return string|array<string, mixed>
     */
    private function renderPreview(Users $caller, string $html, int $number): string|array
    {
        $signature = new UserEmailSignature(SignatureHtml::toText($html), html: $html);
        $page = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
            . '<body style="margin:0;padding:24px;background:#ffffff;">' . $signature->toHtml() . '</body></html>';

        try {
            return (string) PdfService::htmlToPng(
                app: $this->app,
                user: $caller,
                html: $page,
                fileName: 'signature-preview-' . $number . '.png',
                company: $this->company,
            )->url;
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'The preview could not be rendered.',
                guidance: 'Tell them the previews failed; nothing was saved.',
            );
        }
    }
}
