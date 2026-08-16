<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasFileUploadToolProperties;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProductForTool;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Stores a document or image on a catalog product. Catalog write — gated on the requesting human
 * being an admin, like set_product_published, and the product is resolved tenant-scoped so a
 * hallucinated id can never write onto another tenant's row.
 */
#[AgentTool(name: 'Upload File To Product', category: 'inventory')]
class UploadFileToProductTool extends Tool implements HasRunKey
{
    use AttachesFileToEntity;
    use GuardsAdminForTool;
    use HasFileUploadToolProperties;
    use HasKanvasContext;
    use ResolvesProductForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'upload_file_to_product',
            description: 'Attach a document or image to a product — a spec sheet, manual, warranty terms, '
                . 'datasheet or product photo. Normally you pass `content` — the full text of the document you '
                . 'wrote — plus a `file_name` ending in .md, .txt, .csv or .json. Pass `file_url` instead when the '
                . 'file or image already exists at a public URL. Use list_available_products or inventory_search '
                . 'to get the product_id first. To attach to one specific variant instead of the whole product, '
                . 'use upload_file_to_variant. Only an administrator can do this.',
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
                name: 'product_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the product to attach the file to (from list_available_products).',
                required: true,
            ),
            ...$this->fileUploadProperties('spec-sheet.md'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $product_id,
        ?string $file_name = null,
        ?string $content = null,
        ?string $file_url = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $result = $this->resolveProductOrError($product_id);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        return [
            'product_id' => (int) $product->getId(),
            ...$this->attachFileToEntity(
                entity: $product,
                entityLabel: 'product',
                fileUrl: $file_url,
                content: $content,
                fileName: $file_name,
            ),
        ];
    }
}
