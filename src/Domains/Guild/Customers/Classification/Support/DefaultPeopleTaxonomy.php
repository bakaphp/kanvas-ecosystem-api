<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Support;

/**
 * A template, copied onto a tenant by kanvas:people-classification-assign-taxonomy and never read at
 * runtime. The taxonomy is part of the classification hash, so a live default would reclassify every
 * tenant using it, at LLM cost, each time this list is edited.
 */
final class DefaultPeopleTaxonomy
{
    public const array VALUE = [
        'levels' => [
            'C_LEVEL' => [
                'label' => 'C-Level / Executive',
                'tag' => 'Level: C-Level',
                'keywords' => ['CEO', 'CFO', 'COO', 'CIO', 'CTO', 'CMO', 'CHRO', 'President', 'Vice President', 'VP', 'Executive Director', 'Partner', 'Founder', 'Owner', 'General Director'],
            ],
            'DIRECTOR' => [
                'label' => 'Director',
                'tag' => 'Level: Director',
                'keywords' => ['Director', 'Head of', 'Lead Director'],
            ],
            'MANAGER' => [
                'label' => 'Manager',
                'tag' => 'Level: Manager',
                'keywords' => ['Manager', 'Assistant Manager', 'Deputy Manager', 'Country Manager', 'Business Unit Leader'],
            ],
            'LEAD' => [
                'label' => 'Lead / Coordinator',
                'tag' => 'Level: Lead',
                'keywords' => ['Team Lead', 'Team Leader', 'Supervisor', 'Coordinator'],
            ],
            'SPECIALIST' => [
                'label' => 'Specialist / Individual Contributor',
                'tag' => 'Level: Specialist',
                'keywords' => ['Analyst', 'Specialist', 'Consultant', 'Engineer', 'Account Executive', 'Assistant', 'Officer', 'Associate'],
            ],
        ],
        'areas' => [
            'HR' => [
                'label' => 'Human Resources',
                'tag' => 'Area: Human Resources',
                'keywords' => ['Human Resources', 'HR', 'People', 'Talent', 'Payroll', 'Compensation', 'Culture', 'Training'],
            ],
            'FINANCE' => [
                'label' => 'Finance & Accounting',
                'tag' => 'Area: Finance',
                'keywords' => ['Finance', 'Treasury', 'Accounting', 'Audit', 'Controller', 'Risk', 'Collections', 'Accounts Receivable', 'Accounts Payable', 'Credit'],
            ],
            'TECHNOLOGY' => [
                'label' => 'Technology & Cybersecurity',
                'tag' => 'Area: Technology',
                'keywords' => ['IT', 'Systems', 'Technology', 'Cybersecurity', 'Information Security', 'Infrastructure', 'Software', 'DevOps', 'Data'],
            ],
            'OPERATIONS' => [
                'label' => 'Operations & Logistics',
                'tag' => 'Area: Operations',
                'keywords' => ['Operations', 'Supply Chain', 'Logistics', 'Warehouse', 'Production', 'Purchasing', 'Procurement', 'Quality'],
            ],
            'SALES_MARKETING' => [
                'label' => 'Sales & Marketing',
                'tag' => 'Area: Sales & Marketing',
                'keywords' => ['Marketing', 'Sales', 'Commercial', 'Business Development', 'Accounts', 'Trade', 'Advertising', 'Growth'],
            ],
            'LEGAL_COMPLIANCE' => [
                'label' => 'Legal & Compliance',
                'tag' => 'Area: Legal',
                'keywords' => ['Legal', 'Compliance', 'Lawyer', 'Attorney', 'Counsel', 'Regulatory Affairs', 'Anti-Money Laundering'],
            ],
            'ADMINISTRATION' => [
                'label' => 'Administration',
                'tag' => 'Area: Administration',
                'keywords' => ['Administration', 'Administrative', 'Office Manager', 'General Services', 'Facilities'],
            ],
            'EXECUTIVE' => [
                'label' => 'Executive Leadership & Strategy',
                'tag' => 'Area: Executive Leadership',
                'keywords' => ['General Management', 'Presidency', 'Strategy', 'Planning', 'New Business', 'Transformation'],
            ],
        ],
    ];
}
