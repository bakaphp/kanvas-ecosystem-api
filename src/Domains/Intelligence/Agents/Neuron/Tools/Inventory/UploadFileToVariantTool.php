<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasFileUploadToolProperties;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesVariantForTool;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Stores a document or image on a single variant — the per-SKU counterpart of
 * upload_file_to_product. Same admin gate and tenant-scoped resolution as the product tool.
 */
#[AgentTool(name: 'Upload File To Variant', category: 'inventory')]
class UploadFileToVariantTool extends Tool implements HasRunKey
{
    use AttachesFileToEntity;
    use GuardsAdminForTool;
    use HasFileUploadToolProperties;
    use HasKanvasContext;
    use ResolvesVariantForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'upload_file_to_variant',
            description: 'Attach a document or image to one specific product variant (a single SKU) — a photo of '
                . 'that colour/size, its own spec sheet, a certificate. Normally you pass `content` — the full '
                . 'text of the document you wrote — plus a `file_name` ending in .md, .txt, .csv or .json. Pass '
                . '`file_url` instead when the file or image already exists at a public URL. Use variant_search '
                . 'or variant_detail to get the variant_id first. If the file describes the whole product rather '
                . 'than one SKU, use upload_file_to_product. Only an administrator can do this.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'variant_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the variant to attach the file to (from variant_search or variant_detail).',
                required: true,
            ),
            ...$this->fileUploadProperties('sku-certificate.md'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $variant_id,
        ?string $file_name = null,
        ?string $content = null,
        ?string $file_url = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $result = $this->resolveVariantOrError($variant_id);
        if (is_array($result)) {
            return $result;
        }
        $variant = $result;

        return [
            'variant_id' => (int) $variant->getId(),
            ...$this->attachFileToEntity(
                entity: $variant,
                entityLabel: 'variant',
                fileUrl: $file_url,
                content: $content,
                fileName: $file_name,
            ),
        ];
    }
}
