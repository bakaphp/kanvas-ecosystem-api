<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Illuminate\Support\Facades\Log;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

class CRMAgent extends BaseAgent
{
    #[Override]
    protected function tools(): array
    {
        return [
            Tool::make(
                'get_customer_information',
                'I can retrieve your customer information, profile details, lead status, and product preferences. This allows me to provide personalized assistance by accessing your contact details, history with our company, any open leads or opportunities, and your product interests. When you ask about your account, leads, or preferences, I\'ll check this information to give you accurate answers tailored to your specific situation. I can tell you about your current status, help with ongoing inquiries, and make recommendations based on your previous interactions and preferences.',
            )->setCallable(function () {
                /** @var People $customer */
                $customer = $this->entity;

                // Basic profile information
                $profile = [
                    'name' => $customer->getName(),
                    'first_name' => $customer->firstname,
                    'middle_name' => $customer->middlename,
                    'last_name' => $customer->lastname,
                    'date_of_birth' => $customer->dob?->format('Y-m-d'),
                    'created_at' => $customer->created_at?->format('Y-m-d H:i:s'),
                    'custom_fields' => $customer->getAll(),
                ];

                // Contact information
                $emails = $customer->getEmails()->map(function ($email) {
                    return $email->value;
                })->toArray();

                $phones = $customer->getPhones()->map(function ($phone) {
                    return $phone->value;
                })->toArray();

                $cellPhones = $customer->getCellPhones()->map(function ($phone) {
                    return $phone->value;
                })->toArray();

                $addresses = $customer->address()->get()->map(function ($address) {
                    return [
                        'type' => $address->addressType?->name ?? 'Home',
                        'full_address' => trim("{$address->address} {$address->address_2}, {$address->city}, {$address->state} {$address->zip}"),
                        'city' => $address->city,
                        'state' => $address->state,
                        'country' => $address->country?->name,
                    ];
                })->toArray();

                $profile['contact_info'] = [
                    'primary_email' => $emails[0] ?? null,
                    'all_emails' => $emails,
                    'primary_phone' => $phones[0] ?? null,
                    'all_phones' => $phones,
                    'cell_phones' => $cellPhones,
                    'addresses' => $addresses,
                    'social_profiles' => [
                        'google' => $customer->google_contact_id ? true : false,
                        'facebook' => $customer->facebook_contact_id ? true : false,
                        'linkedin' => $customer->linkedin_contact_id ? true : false,
                        'twitter' => $customer->twitter_contact_id ? true : false,
                        'instagram' => $customer->instagram_contact_id ? true : false,
                        'apple' => $customer->apple_contact_id ? true : false,
                    ],
                ];

                // Organizations and employment
                $profile['organizations'] = $customer->organizations()->get()->map(function ($org) {
                    return $org->name;
                })->toArray();

                $profile['employment_history'] = $customer->employmentHistory()->get()->map(function ($history) {
                    return [
                        'organization' => $history->organization,
                        'position' => $history->position,
                        'start_date' => $history->start_date,
                        'end_date' => $history->end_date ?: 'Present',
                    ];
                })->toArray();

                // Tags
                $profile['tags'] = $customer->tags->map(function ($tag) {
                    return $tag->name;
                })->toArray();

                // Product interests and preferences (likes)
                $likes = $customer->likes(false)->get()->map(function ($interaction) {
                    $entityName = $this->getEntityName($interaction);
                    $entityType = $this->getEntityTypeName($interaction);

                    return [
                        'item' => $entityName,
                        'type' => $entityType,
                        'when' => $interaction->created_at?->format('Y-m-d'),
                        'notes' => $interaction->notes,
                    ];
                })->toArray();

                $dislikes = $customer->dislikes()->get()->map(function ($interaction) {
                    $entityName = $this->getEntityName($interaction);
                    $entityType = $this->getEntityTypeName($interaction);

                    return [
                        'item' => $entityName,
                        'type' => $entityType,
                        'when' => $interaction->created_at?->format('Y-m-d'),
                        'notes' => $interaction->notes,
                    ];
                })->toArray();

                // Group likes by type for easier understanding
                $likesByCategory = [];
                foreach ($likes as $like) {
                    $type = $like['type'];
                    if (! isset($likesByCategory[$type])) {
                        $likesByCategory[$type] = [];
                    }
                    $likesByCategory[$type][] = $like['item'];
                }

                $profile['preferences'] = [
                    'likes' => $likes,
                    'dislikes' => $dislikes,
                    'likes_by_category' => $likesByCategory,
                ];

                // Lead information
                $leads = $customer->leads()->get()->map(function ($lead) {
                    // Get custom fields for this lead
                    $customFields = $lead->getAll();

                    // Get tags for this lead
                    $tags = $lead->tags->map(function ($tag) {
                        return $tag->name;
                    })->toArray();

                    // Map the status code to a human-readable description
                    $statusInfo = $this->getLeadStatusInfo($lead);

                    return [
                        'title' => $lead->title,
                        'description' => $lead->description ?: 'No description provided',
                        'status' => $statusInfo['label'],
                        'status_details' => $statusInfo['description'],
                        'is_active' => $lead->isActive(),
                        'is_open' => $lead->isOpen(),
                        'created_at' => $lead->created_at?->format('Y-m-d'),
                        'source' => $lead->source()->exists() ? $lead->source->name : 'Unknown',
                        'type' => $lead->type()->exists() ? $lead->type->name : 'General',
                        'owner' => $lead->owner()->exists() ? $lead->owner->firstname . ' ' . $lead->owner->lastname : 'Unassigned',
                        'pipeline' => $lead->pipeline()->exists() ? $lead->pipeline->name : null,
                        'pipeline_stage' => $lead->stage()->exists() ? $lead->stage->name : null,
                        'custom_fields' => $customFields,
                        'tags' => $tags,
                    ];
                })->toArray();

                // Add some useful summary information
                $leadSummary = [
                    'total_leads' => count($leads),
                    'active_leads' => count(array_filter($leads, fn ($lead) => $lead['is_active'])),
                    'open_leads' => count(array_filter($leads, fn ($lead) => $lead['is_open'])),
                    'most_recent_lead' => ! empty($leads) ? $leads[0]['title'] : null,
                    'most_recent_date' => ! empty($leads) ? $leads[0]['created_at'] : null,
                ];

                $profile['leads'] = [
                    'lead_details' => $leads,
                    'lead_summary' => $leadSummary,
                ];

                // Analyze custom fields for important information
                $importantFields = [];

                // Look for customer preference indicators
                $preferencesKeywords = ['prefer', 'like', 'favorite', 'interest', 'hobby'];
                foreach ($customer->getAll() as $key => $value) {
                    foreach ($preferencesKeywords as $keyword) {
                        if (stripos($key, $keyword) !== false) {
                            $importantFields['preferences'][$key] = $value;
                        }
                    }
                }

                // Look for contact schedule indicators
                $contactKeywords = ['contact', 'call', 'schedule', 'availability', 'preferred time'];
                foreach ($customer->getAll() as $key => $value) {
                    foreach ($contactKeywords as $keyword) {
                        if (stripos($key, $keyword) !== false) {
                            $importantFields['contact_preferences'][$key] = $value;
                        }
                    }
                }

                // Collect all lead custom fields
                $allLeadFields = [];
                foreach ($leads as $lead) {
                    foreach ($lead['custom_fields'] as $key => $value) {
                        if (! isset($allLeadFields[$key])) {
                            $allLeadFields[$key] = [];
                        }
                        $allLeadFields[$key][] = $value;
                    }
                }

                // Look for product interest indicators in leads
                $productKeywords = ['product', 'service', 'purchase', 'buy', 'interested in'];
                foreach ($allLeadFields as $key => $values) {
                    foreach ($productKeywords as $keyword) {
                        if (stripos($key, $keyword) !== false) {
                            $importantFields['product_interests'][$key] = array_unique($values);
                        }
                    }
                }

                $profile['important_insights'] = $importantFields;

                return $profile;
            }),

            // Tool for processing customer images
            Tool::make(
                'process_customer_image',
                'Process images sent by the customer, such as product photos, documents, or identification. This tool logs the image for later processing by our team and provides a confirmation message to the customer.',
            )->addProperty(
                new ToolProperty(
                    name: 'image_url',
                    type: 'string',
                    description: 'The URL or file path of the image to process',
                    required: true
                )
            )->setCallable(function (string $image_url) {
                /** @var People $customer */
                $customer = $this->entity;

                // Log the image URL for later processing
                Log::info('Customer Image Received', [
                    'customer_name' => $customer->getName(),
                    'image_url' => $image_url,
                    'received_at' => now()->format('Y-m-d H:i:s'),
                    'lead_id' => $customer->leads()->latest()->first()?->id,
                ]);

                // Return a response that will be used by the agent
                return [
                    'status' => 'received',
                    'message' => 'Image has been received and will be processed by our team',
                    'next_steps' => 'Our team will review the image and update your file accordingly. You will be contacted if we need any additional information.',
                    'estimated_processing_time' => '24-48 hours',
                ];
            }),

            // Improved tool for retrieving inventory information with limits
            Tool::make(
                'get_inventory_information',
                'Retrieve current inventory information including available products, their details, pricing, and stock availability. This tool provides real-time information about our product inventory that matches the customer\'s preferences or inquiry.',
            )->addProperty(
                new ToolProperty(
                    name: 'search_term',
                    type: 'string',
                    description: 'Optional search term to filter inventory (product name, type, category, etc.)',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'limit',
                    type: 'integer',
                    description: 'Maximum number of products to return (default: 5, max: 20)',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'category',
                    type: 'string',
                    description: 'Optional category name to filter products',
                    required: false
                )
            )->setCallable(function (?string $search_term = null, ?int $limit = 5, ?string $category = null) {
                /** @var People $customer */
                $customer = $this->entity;

                // Enforce limits to prevent token overflow
                $limit = min(max(1, $limit), 20); // Between 1 and 20

                // Get the customer's company and app for filtering
                $companyId = $customer->companies_id;
                $app = $this->entity->app;

                // Query for products based on the company and app
                $productsQuery = Products::where('companies_id', $companyId)
                    ->where('apps_id', $app->getId())
                    ->where('is_deleted', 0)
                    ->where('is_published', 1);

                // Apply search term filter if provided
                if ($search_term) {
                    $productsQuery->where(function ($query) use ($search_term) {
                        $query->where('name', 'like', "%{$search_term}%")
                            ->orWhere('description', 'like', "%{$search_term}%")
                            ->orWhere('short_description', 'like', "%{$search_term}%");
                    });
                }

                // Apply category filter if provided
                if ($category) {
                    $productsQuery->whereHas('categories', function ($query) use ($category) {
                        $query->where('name', 'like', "%{$category}%");
                    });
                }

                // Get only a limited number of products to avoid token overflow
                $products = $productsQuery->take($limit)->get();

                // Get default warehouse and channel for pricing information
                $defaultWarehouse = Warehouses::where('companies_id', $companyId)
                    ->where('apps_id', $app->getId())
                    ->where('is_default', 1)
                    ->where('is_deleted', 0)
                    ->first();

                $defaultChannel = Channels::where('companies_id', $companyId)
                    ->where('apps_id', $app->getId())
                    ->where('is_default', 1)
                    ->where('is_deleted', 0)
                    ->first();

                // Prepare inventory data
                $inventory = [
                    'total_products_found' => $productsQuery->count(), // Total count without limit
                    'products_returned' => $products->count(), // Count with limit
                    'products' => [],
                ];

                foreach ($products as $product) {
                    // Get only essential product attributes (limit to 5 most important)
                    $productAttributes = $product->visibleAttributes();
                    $attributesData = [];
                    $attributeCount = 0;

                    foreach ($productAttributes as $attribute) {
                        if ($attributeCount < 5) { // Limit attributes to 5 per product
                            $attributesData[$attribute['name']] = $attribute['value'];
                            $attributeCount++;
                        }
                    }

                    // Get variants information (limit to 3 per product)
                    $variants = $product->variants->take(3);
                    $variantsData = [];

                    foreach ($variants as $variant) {
                        // Skip deleted or unpublished variants
                        if ($variant->is_deleted || ! $variant->is_published) {
                            continue;
                        }

                        // Get only essential variant attributes (limit to 3)
                        $variantAttributes = $variant->visibleAttributes();
                        $variantAttributesData = [];
                        $variantAttributeCount = 0;

                        foreach ($variantAttributes as $attribute) {
                            if ($variantAttributeCount < 3) {
                                $variantAttributesData[$attribute['name']] = $attribute['value'];
                                $variantAttributeCount++;
                            }
                        }

                        // Get stock availability
                        $stockQuantity = 0;
                        $price = 0;

                        if ($defaultWarehouse) {
                            $stockQuantity = $variant->getQuantity($defaultWarehouse);
                            $price = $variant->getPrice($defaultWarehouse, $defaultChannel);
                        }

                        // Get just 1 image per variant
                        $images = $variant->getFiles()->take(1)->map(function ($file) {
                            return [
                                'url' => $file->url,
                            ];
                        })->toArray();

                        $variantsData[] = [
                            'id' => $variant->id,
                            'name' => $variant->name,
                            'sku' => $variant->sku,
                            'price' => $price,
                            'stock_quantity' => $stockQuantity,
                            'in_stock' => $stockQuantity > 0,
                            'attributes' => $variantAttributesData,
                            'images' => $images,
                        ];
                    }

                    // Get just 1 image per product
                    $images = $product->getFiles()->take(1)->map(function ($file) {
                        return [
                            'url' => $file->url,
                        ];
                    })->toArray();

                    // Add product to inventory data (only essential information)
                    $inventory['products'][] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'short_description' => $product->short_description,
                        'attributes' => $attributesData,
                        'variants' => $variantsData,
                        'images' => $images,
                        'product_type' => $product->productType ? $product->productType->name : null,
                        'categories' => $product->categories->take(3)->map(function ($category) {
                            return $category->name;
                        })->toArray(),
                    ];
                }

                return $inventory;
            }),
        ];
    }

    /**
     * Get the type name of an entity from an interaction in a simplified way
     */
    private function getEntityTypeName($interaction): string
    {
        try {
            // Determine the namespace
            if (method_exists($interaction, 'entity_namespace')) {
                $namespace = $interaction->entity_namespace;
            } elseif (isset($interaction->entity_namespace)) {
                $namespace = $interaction->entity_namespace;
            } elseif (isset($interaction->interacted_entity_namespace)) {
                $namespace = $interaction->interacted_entity_namespace;
            } else {
                return 'Item';
            }

            // Extract the class name from the namespace
            $parts = explode('\\', $namespace);
            $typeName = end($parts);

            // Make it more human-readable
            return ucfirst(strtolower($typeName));
        } catch (\Exception $e) {
            return 'Item';
        }
    }

    /**
     * Get a simple name for the entity that was interacted with
     */
    private function getEntityName($interaction): string
    {
        try {
            // Determine the entity class and ID
            if (method_exists($interaction, 'entity_namespace') && method_exists($interaction, 'entity_id')) {
                $entityClass = $interaction->entity_namespace;
                $entityId = $interaction->entity_id;
            } elseif (isset($interaction->entity_namespace) && isset($interaction->entity_id)) {
                $entityClass = $interaction->entity_namespace;
                $entityId = $interaction->entity_id;
            } elseif (isset($interaction->interacted_entity_namespace) && isset($interaction->interacted_entity_id)) {
                $entityClass = $interaction->interacted_entity_namespace;
                $entityId = $interaction->interacted_entity_id;
            } else {
                return 'Unknown item';
            }

            // Try to load the entity
            $entity = $entityClass::find($entityId);

            if (! $entity) {
                return 'Unknown item';
            }

            // Try different common name properties
            if (method_exists($entity, 'getName')) {
                return $entity->getName();
            } elseif (isset($entity->name)) {
                return $entity->name;
            } elseif (isset($entity->title)) {
                return $entity->title;
            } elseif (isset($entity->label)) {
                return $entity->label;
            }

            return 'Item #' . $entityId;
        } catch (\Exception $e) {
            return 'Unknown item';
        }
    }

    /**
     * Get human-readable lead status information
     */
    private function getLeadStatusInfo(Lead $lead): array
    {
        // Try to get status from relationship first
        if ($lead->status()->count() > 0) {
            return [
                'label' => $lead->status()->first()->name,
                'description' => $lead->status()->first()->description ?? $this->getDefaultStatusDescription($lead->status()->first()),
            ];
        }

        // Fallback to status code interpretation
        $statusCode = $lead->status()->first();

        switch ($statusCode) {
            case 0:
                return [
                    'label' => 'New',
                    'description' => 'This lead is newly created and waiting for initial contact.',
                ];
            case 1:
                return [
                    'label' => 'In Progress',
                    'description' => 'This lead is currently being worked on.',
                ];
            case 2:
                return [
                    'label' => 'Closed',
                    'description' => 'This lead has been closed.',
                ];
            case 3:
                return [
                    'label' => 'On Hold',
                    'description' => 'This lead is temporarily paused.',
                ];
            case 4:
                return [
                    'label' => 'Qualified',
                    'description' => 'This lead has been qualified as a potential opportunity.',
                ];
            case 5:
                return [
                    'label' => 'Disqualified',
                    'description' => 'This lead has been disqualified and is no longer being pursued.',
                ];
            default:
                return [
                    'label' => 'Unknown Status',
                    'description' => 'The status of this lead is unknown.',
                ];
        }
    }

    /**
     * Get a generic description for a status object
     */
    private function getDefaultStatusDescription($status): string
    {
        $name = strtolower($status->name);

        if (strpos($name, 'new') !== false || strpos($name, 'created') !== false) {
            return 'This lead is newly created and waiting for initial contact.';
        }

        if (strpos($name, 'active') !== false || strpos($name, 'progress') !== false) {
            return 'This lead is currently being worked on.';
        }

        if (strpos($name, 'qualified') !== false) {
            return 'This lead has been qualified as a potential opportunity.';
        }

        if (strpos($name, 'disqualified') !== false) {
            return 'This lead has been disqualified and is no longer being pursued.';
        }

        if (strpos($name, 'closed') !== false || strpos($name, 'complete') !== false) {
            return 'This lead has been closed.';
        }

        if (strpos($name, 'hold') !== false || strpos($name, 'pending') !== false) {
            return 'This lead is temporarily paused.';
        }

        return 'This lead is in the ' . $status->name . ' status.';
    }
}
