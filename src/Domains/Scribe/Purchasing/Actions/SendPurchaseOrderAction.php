<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderPdfService;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderStateMachineService;
use Kanvas\Users\Models\Users;

/**
 * Emails an approved purchase order to the vendor as a PDF and marks it SENT. The email goes first: if it cannot be
 * sent the order stays approved. A sent order can be sent again, for instance to another contact.
 */
class SendPurchaseOrderAction
{
    /**
     * @param list<string> $recipients
     * @param (Closure(string): string)|null $fetchPdf reads a stored file's bytes; the default fetches its URL
     */
    public function __construct(
        protected readonly PurchaseOrder $order,
        protected readonly Users $user,
        protected readonly array $recipients,
        protected readonly ?string $message = null,
        protected readonly ?Closure $fetchPdf = null,
        protected readonly PurchaseOrderStateMachineService $stateMachine = new PurchaseOrderStateMachineService(),
    ) {
    }

    public function execute(): PurchaseOrder
    {
        if (! $this->order->isIssuedByCompany()) {
            throw InvalidPurchaseOrderTransitionException::notIssuedByCompany($this->order, 'sent');
        }

        if ($this->order->document_status !== PurchaseOrderStatusEnum::SENT) {
            $this->stateMachine->assertTransition($this->order, PurchaseOrderStatusEnum::SENT);
        }

        $recipients = $this->validRecipients();
        $pdf = new PurchaseOrderPdfService($this->order)->generate($this->user);
        $bytes = $this->fetchPdf !== null ? ($this->fetchPdf)($pdf->url) : SafeUrlFetcher::fetch($pdf->url);

        $this->email($recipients, $bytes, $pdf->name);

        $this->order->document_status = PurchaseOrderStatusEnum::SENT;
        $this->order->sent_at = Carbon::now();
        $this->order->sent_to = implode(', ', $recipients);
        $this->order->saveOrFail();

        return $this->order;
    }

    /**
     * @return list<string>
     */
    private function validRecipients(): array
    {
        $recipients = array_values(array_unique(array_filter(
            array_map(static fn (string $email): string => trim($email), $this->recipients),
            static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        )));

        if ($recipients === []) {
            throw new ValidationException('Give at least one valid email address to send the purchase order to.');
        }

        return $recipients;
    }

    /**
     * @param list<string> $recipients
     */
    private function email(array $recipients, string $pdfBytes, string $fileName): void
    {
        $smtp = new SmtpRuntimeConfiguration($this->order->app, $this->order->company);
        $from = $smtp->getFromEmail();
        $company = (string) $this->order->company->name;
        $note = Str::trimToNull($this->message);

        $html = '<p>Please find attached purchase order ' . e($this->order->order_number) . ' from ' . e($company) . '.</p>'
            . ($note === null ? '' : '<p>' . nl2br(e($note)) . '</p>');

        Mail::send(
            new KanvasMailable($smtp->loadSmtpSettings(), $html)
                ->from($from['address'], $from['name'])
                ->to($recipients)
                ->subject("Purchase order {$this->order->order_number} from {$company}")
                ->attachData($pdfBytes, $fileName, ['mime' => 'application/pdf'])
        );
    }
}
