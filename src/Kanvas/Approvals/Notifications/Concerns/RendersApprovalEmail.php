<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Notifications\Concerns;

use Kanvas\Approvals\Enums\ApprovalConfigurationEnum;
use Kanvas\Approvals\Enums\ApprovalEmailTemplateEnum;
use Kanvas\Exceptions\ModelNotFoundException;

/**
 * Both approval emails render a named template when the company or app has one, and fall back to
 * built-in HTML otherwise, so an app that never synced its templates still sends a readable email.
 * Template variables: heading, intro, count, approvals (list), approval (the first), company_name, review_url.
 * Each approval: id, name, type, action, approval_type, requested_at.
 */
trait RendersApprovalEmail
{
    /**
     * @param list<array<string, mixed>> $approvals each one from ApprovalRequest::emailSummary()
     */
    protected function setApprovalData(
        array $approvals,
        string $heading,
        string $intro = 'These requests are waiting on your decision.'
    ): void {
        $reviewUrl = $this->app->get(ApprovalConfigurationEnum::REVIEW_URL->value);

        $this->setData([
            'heading' => $heading,
            'intro' => $intro,
            'count' => count($approvals),
            'approvals' => $approvals,
            'approval' => $approvals[0] ?? null,
            'company_name' => $this->company?->name,
            'review_url' => is_string($reviewUrl) && $reviewUrl !== '' ? $reviewUrl : null,
        ]);
    }

    protected function renderApprovalEmail(ApprovalEmailTemplateEnum $template): string
    {
        try {
            return $this->renderTemplate($template->value, $this->getData());
        } catch (ModelNotFoundException) {
            return $this->fallbackApprovalHtml();
        }
    }

    private function fallbackApprovalHtml(): string
    {
        $rows = '';
        foreach ($this->data['approvals'] as $approval) {
            $rows .= '<tr>'
                . '<td style="padding: 8px; border-bottom: 1px solid #eaecf0;"><strong>' . e($approval['name']) . '</strong><br>'
                . '<span style="color: #667085; font-size: 12px;">' . e($approval['type']) . '</span></td>'
                . '<td style="padding: 8px; border-bottom: 1px solid #eaecf0;">' . e($approval['action']) . '</td>'
                . '</tr>';
        }

        $button = $this->data['review_url'] === null
            ? ''
            : '<p style="margin-top: 20px;"><a href="' . e($this->data['review_url']) . '" style="background: #1d4ed8; color: #ffffff; '
                . 'padding: 10px 18px; border-radius: 6px; text-decoration: none;">Review approvals</a></p>';

        return '<h2 style="margin: 0 0 8px;">' . e($this->data['heading']) . '</h2>'
            . '<p style="color: #667085;">' . e($this->data['intro']) . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse;">'
            . $rows
            . '</table>'
            . $button;
    }
}
