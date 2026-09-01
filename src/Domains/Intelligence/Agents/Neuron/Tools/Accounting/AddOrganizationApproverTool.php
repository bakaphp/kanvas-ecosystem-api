<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Links a Kanvas User as an approver for a vendor/customer Organization — the same table
 * ImportVendorApproversCommand populates from a spreadsheet, but callable one at a time in
 * conversation. An Organization can have more than one approver.
 */
#[AgentTool(name: 'Add Organization Approver', category: 'accounting')]
class AddOrganizationApproverTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'add_organization_approver',
            description: 'Adds (or reuses) a Kanvas User as an approver for a vendor/customer organization — '
                . 'that person can then approve pending bills/invoices for it and gets notified when a new one '
                . 'comes in. Resolve the organization_id first with find_vendor or find_customer. Only call when '
                . 'the user explicitly asks to add/assign an approver, never on your own initiative.',
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
                name: 'organization_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas Organization id, from find_vendor or find_customer. Never guess it.',
                required: true,
            ),
            new ToolProperty(
                name: 'approver_email',
                type: PropertyType::STRING,
                description: 'The approver\'s email. If no Kanvas User has this email yet, a minimal one is '
                    . 'created just to hold the identity — no welcome email is sent.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $organization_id, string $approver_email): array
    {
        $email = trim($approver_email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'linked' => false,
                'reason' => 'invalid_email',
                'message' => "\"{$approver_email}\" is not a valid email address.",
            ];
        }

        try {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp($organization_id, $this->company, $this->app);
        } catch (ModelNotFoundException) {
            return [
                'linked' => false,
                'reason' => 'organization_not_found',
                'message' => "No organization with id {$organization_id} for this app/company.",
            ];
        }

        try {
            OrganizationApprover::linkApproverEmail($organization, $email);
        } catch (Throwable $e) {
            return [
                'linked' => false,
                'reason' => 'link_failed',
                'message' => 'Could not link that approver: ' . $e->getMessage(),
            ];
        }

        return [
            'linked' => true,
            'organization_id' => $organization->getId(),
            'organization_name' => $organization->name,
            'approver_email' => $email,
            'approvers' => OrganizationApprover::emailsFor($organization),
        ];
    }
}
