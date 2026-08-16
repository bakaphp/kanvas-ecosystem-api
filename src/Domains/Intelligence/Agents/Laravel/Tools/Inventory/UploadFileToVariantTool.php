<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasFileUploadToolSchema;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use Kanvas\Inventory\Variants\Models\Variants;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

/**
 * Laravel-AI counterpart of the Neuron upload_file_to_variant tool — same body via
 * AttachesFileToEntity, same admin gate on the catalog write.
 */
#[AgentTool(name: 'Upload File To Variant', category: 'inventory')]
class UploadFileToVariantTool implements KanvasToolInterface
{
    use AttachesFileToEntity;
    use HasFileUploadToolSchema;
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Attach a document or image to one specific product variant (a single SKU) — a photo of that '
            . 'colour/size, its own spec sheet, a certificate. Normally you pass content — the full text of the '
            . 'document you wrote — plus a file_name ending in .md, .txt, .csv or .json. Pass file_url instead '
            . 'when the file or image already exists at a public URL. If the file describes the whole product '
            . 'rather than one SKU, use upload_file_to_product. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = $this->contextUser();

        if ($user === null || ! $user->isAdmin()) {
            return 'Only an administrator can attach files to the catalog.';
        }

        $variantId = $request->integer('variant_id');

        try {
            /** @var Variants $variant */
            $variant = Variants::getByIdFromCompanyApp($variantId, $this->company, $this->app);
        } catch (Throwable) {
            return "Variant {$variantId} does not exist in this company. Use a variant search to get a real "
                . 'variant_id.';
        }

        return $this->uploadFromRequest($request, $variant, 'variant', ['variant_id' => $variantId]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema
                ->integer()
                ->description('The ID of the variant to attach the file to.')
                ->required(),
            ...$this->fileUploadSchema($schema, 'sku-certificate.md'),
        ];
    }
}
