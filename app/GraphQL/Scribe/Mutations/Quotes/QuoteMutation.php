<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Quotes;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Actions\AcceptQuoteAction;
use Kanvas\Scribe\Quotes\Actions\ConvertQuoteToInvoiceAction;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteAction;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteRevisionAction;
use Kanvas\Scribe\Quotes\Actions\ExpireQuoteAction;
use Kanvas\Scribe\Quotes\Actions\RejectQuoteAction;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\Quote as QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine as QuoteLineData;
use Kanvas\Scribe\Quotes\Models\Quote;
use RuntimeException;
use Spatie\LaravelData\DataCollection;

class QuoteMutation
{
    public function create(mixed $rootValue, array $request): Quote
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateQuoteAction(
            data: $this->buildQuoteData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function send(mixed $rootValue, array $request): Quote
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Quote $quote */
        $quote = Quote::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        if ($quote->customer_organization_id === null) {
            throw new RuntimeException(
                "Quote {$quote->id} has no customer reference — assign one before sending."
            );
        }

        /** @var Organization $billable */
        $billable = Organization::getByIdFromCompanyApp(
            (int) $quote->customer_organization_id,
            $company,
            $app,
        );

        return new SendQuoteAction(
            quote: $quote,
            billable: $billable,
            user: $user,
        )->execute();
    }

    public function accept(mixed $rootValue, array $request): Quote
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Quote $quote */
        $quote = Quote::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new AcceptQuoteAction(quote: $quote, user: $user)->execute();
    }

    public function reject(mixed $rootValue, array $request): Quote
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Quote $quote */
        $quote = Quote::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new RejectQuoteAction(
            quote: $quote,
            lostReason: $request['reason'] ?? null,
            user: $user,
        )->execute();
    }

    public function expire(mixed $rootValue, array $request): Quote
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Quote $quote */
        $quote = Quote::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new ExpireQuoteAction(quote: $quote, user: $user)->execute();
    }

    public function createRevision(mixed $rootValue, array $request): Quote
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Quote $originalQuote */
        $originalQuote = Quote::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new CreateQuoteRevisionAction(
            originalQuote: $originalQuote,
            newRevisionData: $this->buildQuoteData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function convertToInvoice(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Quote $quote */
        $quote = Quote::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new ConvertQuoteToInvoiceAction(
            quote: $quote,
            netTermsDays: isset($request['net_terms_days']) ? (int) $request['net_terms_days'] : 30,
            user: $user,
        )->execute();
    }

    public function generatePdf(mixed $rootValue, array $request): Filesystem
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Quote $quote */
        $quote = Quote::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new DocumentPdfService($quote, $request['template_name'] ?? null)->generate($user);
    }

    private function buildQuoteData(
        array $input,
        AppInterface $app,
        CompanyInterface $company,
    ): QuoteData {
        $billable = null;
        if (isset($input['customer_organization_id'])) {
            /** @var Organization $billable */
            $billable = Organization::getByIdFromCompanyApp(
                (int) $input['customer_organization_id'],
                $company,
                $app,
            );
        }

        $lines = new DataCollection(QuoteLineData::class, array_map(
            fn (array $line): QuoteLineData => new QuoteLineData(
                description: (string) ($line['description'] ?? ''),
                quantity: (float) ($line['quantity'] ?? 1),
                unit_price_native: (float) $line['unit_price_native'],
                item_id: isset($line['item_id']) ? (int) $line['item_id'] : null,
                sort_order: isset($line['sort_order']) ? (int) $line['sort_order'] : null,
                discount_rate: isset($line['discount_rate']) ? (float) $line['discount_rate'] : null,
                discount_amount_native: (float) ($line['discount_amount_native'] ?? 0),
                tax_rate: isset($line['tax_rate']) ? (float) $line['tax_rate'] : null,
                tax_amount_native: (float) ($line['tax_amount_native'] ?? 0),
                class_id: isset($line['class_id']) ? (int) $line['class_id'] : null,
                department_id: isset($line['department_id']) ? (int) $line['department_id'] : null,
                metadata: $line['metadata'] ?? null,
            ),
            $input['lines'],
        ));

        $contact = null;
        if (isset($input['contact_people_id'])) {
            /** @var People $contact */
            $contact = People::getByIdFromCompanyApp((int) $input['contact_people_id'], $company, $app);
        }

        return new QuoteData(
            app: $app,
            company: $company,
            billable: $billable,
            lines: $lines,
            currency: (string) $input['currency'],
            fx_rate_to_base: (float) ($input['fx_rate_to_base'] ?? 1.0),
            quote_number: $input['quote_number'] ?? null,
            issued_date: isset($input['issued_date']) ? Carbon::parse((string) $input['issued_date']) : null,
            valid_until: isset($input['valid_until']) ? Carbon::parse((string) $input['valid_until']) : null,
            notes: $input['notes'] ?? null,
            internal_notes: $input['internal_notes'] ?? null,
            terms: $input['terms'] ?? null,
            regional_compliance: $input['regional_compliance'] ?? null,
            metadata: $input['metadata'] ?? null,
            parent_quote_id: isset($input['parent_quote_id']) ? (int) $input['parent_quote_id'] : null,
            contact: $contact,
        );
    }
}
