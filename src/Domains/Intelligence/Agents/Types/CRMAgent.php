<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use NeuronAI\Tools\Tool;
use Override;

class CRMAgent extends BaseAgent
{
    #[Override]
    protected function tools(): array
    {
        $information = [
            Tool::make(
                'get_customer_profile',
                'Retrieve basic customer profile information.',
            )->setCallable(function () {
                // Ensure entity is a People instance
                if (! $this->entity instanceof People) {
                    return [
                        'error' => 'No customer information available.',
                    ];
                }

                /** @var People $customer */
                $customer = $this->entity;

                // Get all custom fields for the customer
                $customFields = $customer->getAll();

                return [
                    'name' => $customer->getName(),
                    'first_name' => $customer->firstname,
                    'middle_name' => $customer->middlename,
                    'last_name' => $customer->lastname,
                    'date_of_birth' => $customer->dob?->format('Y-m-d'),
                    'created_at' => $customer->created_at?->format('Y-m-d H:i:s'),
                    'custom_fields' => $customFields,
                ];
            }),

            Tool::make(
                'get_customer_contact_info',
                'Retrieve customer contact information including emails, phones, and addresses.',
            )->setCallable(function () {
                if (! $this->entity instanceof People) {
                    return [
                        'error' => 'No customer information available.',
                    ];
                }

                /** @var People $customer */
                $customer = $this->entity;

                // Get emails
                $emails = $customer->getEmails()->map(function ($email) {
                    return $email->value;
                })->toArray();

                // Get phones
                $phones = $customer->getPhones()->map(function ($phone) {
                    return $phone->value;
                })->toArray();

                // Get cell phones
                $cellPhones = $customer->getCellPhones()->map(function ($phone) {
                    return $phone->value;
                })->toArray();

                // Get addresses
                $addresses = $customer->address()->get()->map(function ($address) {
                    return [
                        'type' => $address->addressType?->name ?? 'Home',
                        'full_address' => trim("{$address->address} {$address->address_2}, {$address->city}, {$address->state} {$address->zip}"),
                        'city' => $address->city,
                        'state' => $address->state,
                        'country' => $address->country?->name,
                    ];
                })->toArray();

                return [
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
            }),

            Tool::make(
                'get_customer_likes',
                'Retrieve items and content the customer has liked or disliked.',
            )->setCallable(function () {
                if (! $this->entity instanceof People) {
                    return [
                        'error' => 'No customer information available.',
                    ];
                }

                /** @var People $customer */
                $customer = $this->entity;

                // Get customer likes - simplified to just the names and details
                $likes = $customer->likes(false)->get()->map(function ($interaction) {
                    // Get information about what was liked
                    $entityName = $this->getEntityName($interaction);
                    $entityType = $this->getEntityTypeName($interaction);

                    return [
                        'item' => $entityName,
                        'type' => $entityType,
                        'when' => $interaction->created_at?->format('Y-m-d'),
                        'notes' => $interaction->notes,
                    ];
                })->toArray();

                // Get customer dislikes
                $dislikes = $customer->dislikes()->get()->map(function ($interaction) {
                    // Get information about what was disliked
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

                return [
                    'likes' => $likes,
                    'dislikes' => $dislikes,
                    'likes_by_category' => $likesByCategory,
                    'total_likes' => count($likes),
                    'total_dislikes' => count($dislikes),
                ];
            }),

            Tool::make(
                'get_customer_organizations',
                'Retrieve organizations associated with the customer.',
            )->setCallable(function () {
                if (! $this->entity instanceof People) {
                    return [
                        'error' => 'No customer information available.',
                    ];
                }

                /** @var People $customer */
                $customer = $this->entity;

                $organizations = $customer->organizations()->get()->map(function ($org) {
                    return $org->name;
                })->toArray();

                $employmentHistory = $customer->employmentHistory()->get()->map(function ($history) {
                    return [
                        'organization' => $history->organization,
                        'position' => $history->position,
                        'start_date' => $history->start_date,
                        'end_date' => $history->end_date ?: 'Present',
                    ];
                })->toArray();

                return [
                    'current_organizations' => $organizations,
                    'employment_history' => $employmentHistory,
                ];
            }),

            Tool::make(
                'get_customer_tags',
                'Retrieve tags associated with the customer.',
            )->setCallable(function () {
                if (! $this->entity instanceof People) {
                    return [
                        'error' => 'No customer information available.',
                    ];
                }

                /** @var People $customer */
                $customer = $this->entity;

                $tags = $customer->tags->map(function ($tag) {
                    return $tag->name;
                })->toArray();

                return [
                    'tags' => $tags,
                ];
            }),

            Tool::make(
                'get_customer_leads',
                'Retrieve comprehensive information about the customer\'s leads.',
            )->setCallable(function () {
                if (! $this->entity instanceof People) {
                    return [
                        'error' => 'No customer information available.',
                    ];
                }

                /** @var People $customer */
                $customer = $this->entity;

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

                        // Status information in readable format
                        'status' => $statusInfo['label'],
                        'status_details' => $statusInfo['description'],
                        'is_active' => $lead->isActive(),
                        'is_open' => $lead->isOpen(),

                        // Basic lead information
                        'created_at' => $lead->created_at?->format('Y-m-d'),
                        'email' => $lead->email,
                        'phone' => $lead->phone,

                        // Related information
                        'source' => $lead->source()->exists() ? $lead->source->name : 'Unknown',
                        'type' => $lead->type()->exists() ? $lead->type->name : 'General',
                        'owner' => $lead->owner()->exists() ? $lead->owner->firstname . ' ' . $lead->owner->lastname : 'Unassigned',
                        'organization' => $lead->organization()->exists() ? $lead->organization->name : null,

                        // Pipeline information
                        'pipeline' => $lead->pipeline()->exists() ? $lead->pipeline->name : null,
                        'pipeline_stage' => $lead->stage()->exists() ? $lead->stage->name : null,

                        // Additional data
                        'is_duplicate' => (bool)$lead->is_duplicate,
                        'custom_fields' => $customFields,
                        'tags' => $tags,
                        'attempts' => $lead->attempts()->count(),
                    ];
                })->toArray();

                // Add some useful summary information
                $summary = [
                    'total_leads' => count($leads),
                    'active_leads' => count(array_filter($leads, fn ($lead) => $lead['is_active'])),
                    'open_leads' => count(array_filter($leads, fn ($lead) => $lead['is_open'])),
                    'most_recent_lead' => ! empty($leads) ? $leads[0]['title'] : null,
                    'most_recent_date' => ! empty($leads) ? $leads[0]['created_at'] : null,
                ];

                // Get the lead sources
                $leadSources = array_unique(array_column($leads, 'source'));

                // Get the lead types
                $leadTypes = array_unique(array_column($leads, 'type'));

                // Collect all tags from leads
                $allTags = [];
                foreach ($leads as $lead) {
                    $allTags = array_merge($allTags, $lead['tags']);
                }
                $uniqueTags = array_unique($allTags);

                return [
                    'leads' => $leads,
                    'summary' => $summary,
                    'lead_sources' => $leadSources,
                    'lead_types' => $leadTypes,
                    'all_lead_tags' => $uniqueTags,
                ];
            }),

            Tool::make(
                'get_customer_custom_fields',
                'Retrieve all custom fields for the customer and their leads.',
            )->setCallable(function () {
                if (! $this->entity instanceof People) {
                    return [
                        'error' => 'No customer information available.',
                    ];
                }

                /** @var People $customer */
                $customer = $this->entity;

                // Get customer custom fields
                $customerCustomFields = $customer->getAll();

                // Get custom fields for each lead
                $leadCustomFields = [];
                $allLeadFields = [];

                $leads = $customer->leads()->get();
                foreach ($leads as $lead) {
                    $fields = $lead->getAll();

                    if (! empty($fields)) {
                        $leadCustomFields[] = [
                            'lead_title' => $lead->title,
                            'created_at' => $lead->created_at?->format('Y-m-d'),
                            'custom_fields' => $fields,
                        ];

                        // Combine all lead fields for a complete view
                        foreach ($fields as $key => $value) {
                            if (! isset($allLeadFields[$key])) {
                                $allLeadFields[$key] = [];
                            }
                            $allLeadFields[$key][] = $value;
                        }
                    }
                }

                // Identify any interesting/important custom fields for the agent
                $importantFields = [];

                // Look for customer preference indicators
                $preferencesKeywords = ['prefer', 'like', 'favorite', 'interest', 'hobby'];
                foreach ($customerCustomFields as $key => $value) {
                    foreach ($preferencesKeywords as $keyword) {
                        if (stripos($key, $keyword) !== false) {
                            $importantFields['preferences'][$key] = $value;
                        }
                    }
                }

                // Look for contact schedule indicators
                $contactKeywords = ['contact', 'call', 'schedule', 'availability', 'preferred time'];
                foreach ($customerCustomFields as $key => $value) {
                    foreach ($contactKeywords as $keyword) {
                        if (stripos($key, $keyword) !== false) {
                            $importantFields['contact_preferences'][$key] = $value;
                        }
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

                return [
                    'customer_custom_fields' => $customerCustomFields,
                    'lead_custom_fields' => $leadCustomFields,
                    'important_fields' => $importantFields,
                    'all_lead_fields' => $allLeadFields,
                ];
            }),

            Tool::make(
                'get_all_customer_info',
                'Retrieve all available customer information in a comprehensive format.',
            )->setCallable(function () {
                if (! $this->entity instanceof People) {
                    return [
                        'error' => 'No customer information available.',
                    ];
                }

                /** @var People $customer */
                $customer = $this->entity;

                // Basic information
                $profile = [
                    'name' => $customer->getName(),
                    'first_name' => $customer->firstname,
                    'last_name' => $customer->lastname,
                    'date_of_birth' => $customer->dob?->format('Y-m-d'),
                    'created_at' => $customer->created_at?->format('Y-m-d'),
                ];

                // Contact information
                $emails = $customer->getEmails()->map(function ($email) {
                    return $email->value;
                })->toArray();

                $phones = $customer->getPhones()->map(function ($phone) {
                    return $phone->value;
                })->toArray();

                $addresses = $customer->address()->get()->map(function ($address) {
                    return [
                        'type' => $address->addressType?->name ?? 'Home',
                        'full_address' => trim("{$address->address} {$address->address_2}, {$address->city}, {$address->state} {$address->zip}"),
                    ];
                })->toArray();

                $profile['contact_info'] = [
                    'primary_email' => $emails[0] ?? null,
                    'all_emails' => $emails,
                    'primary_phone' => $phones[0] ?? null,
                    'all_phones' => $phones,
                    'addresses' => $addresses,
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

                // Custom fields
                $profile['custom_fields'] = $customer->getAll();

                // Likes and preferences
                $profile['likes'] = $customer->likes(false)->get()->map(function ($like) {
                    return [
                        'item' => $this->getEntityName($like),
                        'type' => $this->getEntityTypeName($like),
                    ];
                })->toArray();

                // Lead information
                $profile['leads'] = $customer->leads()->get()->map(function ($lead) {
                    return [
                        'title' => $lead->title,
                        'status' => $this->getLeadStatusInfo($lead)['label'],
                        'created_at' => $lead->created_at?->format('Y-m-d'),
                        'source' => $lead->source()->exists() ? $lead->source->name : 'Unknown',
                        'type' => $lead->type()->exists() ? $lead->type->name : 'General',
                        'custom_fields' => $lead->getAll(),
                        'is_active' => $lead->isActive(),
                    ];
                })->toArray();

                return $profile;
            }),
        ];

        return array_merge(parent::tools(), $information);
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
