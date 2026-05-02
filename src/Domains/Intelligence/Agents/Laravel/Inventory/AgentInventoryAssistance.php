<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Inventory;

use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\AttributeSearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\CategorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\ListAvailableProductsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\VariantSearchTool;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Tool;
use Override;
use Stringable;

class AgentInventoryAssistance extends KanvasLaravelAgent
{
    use RemembersConversations;

    #[Override]
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are an inventory assistant. You can answer questions about any part of the inventory:
        products, variants, categories, attributes, and stock levels.

        Response format rules (always follow these):
        - Use plain language, no unnecessary preamble.
        - Lead with a one-line summary of what was found.
        - For a single item, reply in 2-3 lines max.
        - If nothing is found, say so in one line and suggest alternatives.
        - Always answer in the same language the user used.

        Available tools:
        - search_inventory: search products by name
        - list_available_products: list products filtered by status / stock
        - search_attributes: list or search product attributes and their allowed values
        - search_categories: list or search product categories
        - search_variants: search variants by name or SKU
        INSTRUCTIONS;
    }

    /**
     * @return Tool[]
     */
    #[Override]
    public function tools(): iterable
    {
        return [
            new InventorySearchTool(),
            new ListAvailableProductsTool(),
            new AttributeSearchTool(),
            new CategorySearchTool(),
            new VariantSearchTool(),
        ];
    }
}
