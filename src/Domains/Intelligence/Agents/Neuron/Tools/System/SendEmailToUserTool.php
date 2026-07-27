<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Lets a teammate agent email an INTERNAL colleague — the email counterpart of send_slack_direct_message.
 * The recipient is never a free-form address the model invents: it must resolve to a real Kanvas user in
 * the agent's own company (that guard is what stops a prompt-inject from mailing an outsider). Distinct
 * from send_email, which mails the prospect/customer on a lead — this one is staff-to-staff only.
 *
 * Sends through the agent's app/company SMTP config directly (the same path NotificationMailTrait uses),
 * so it needs no per-app email template wired up.
 */
#[AgentTool(name: 'Email Teammate')]
class SendEmailToUserTool extends Tool
{
    public function __construct(private readonly ?Agent $agent = null)
    {
        parent::__construct(
            name: 'send_email_to_user',
            description: 'Send an email to an internal teammate — a Kanvas user in this company — identified by '
                . 'their email address. Use it when someone asks you to email a colleague / staff member. The '
                . 'recipient must be a member of this company; you cannot email arbitrary outside addresses. This '
                . 'is NOT for emailing a prospect/customer on a lead (use send_email for that).',
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
                name: 'recipient_email',
                type: PropertyType::STRING,
                description: "The teammate's email address. Must belong to a user in this company.",
                required: true,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Subject line of the email. Short and specific.',
                required: true,
            ),
            new ToolProperty(
                name: 'body',
                type: PropertyType::STRING,
                description: 'The email body, written as the agent addressing the teammate. Markdown is supported '
                    . 'and rendered to HTML — do not add a signature or branding, the layout handles that.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $recipient_email, string $subject, string $body): array
    {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No agent is in scope, so I cannot send an email.'];
        }

        $recipient_email = trim($recipient_email);
        $subject = trim($subject);
        $body = trim($body);

        if ($recipient_email === '' || $subject === '' || $body === '') {
            return ['status' => 'error', 'message' => 'A recipient email, a subject, and a body are all required.'];
        }

        try {
            $recipient = UsersRepository::getUserOfAppByEmail($recipient_email, $this->agent->app);
            UsersRepository::belongsToCompany($recipient, $this->agent->company);
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "No teammate in this company has the email {$recipient_email}. Ask the user to confirm who to email.",
            ];
        }

        try {
            $smtp = new SmtpRuntimeConfiguration($this->agent->app, $this->agent->company);
            $fromMail = $smtp->getFromEmail();

            Mail::send(
                new KanvasMailable($smtp->loadSmtpSettings(), MarkdownEmailRenderer::toEmailHtml($body))
                    ->from($fromMail['address'], $fromMail['name'])
                    ->to($recipient->email)
                    ->subject($subject),
            );
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'The email could not be sent right now. Tell the user you will follow up.'];
        }

        return [
            'status' => 'success',
            'to' => $recipient->email,
            'subject' => $subject,
            'message' => "Email sent to {$recipient->email}.",
        ];
    }
}
