<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Expenses;

use App\GraphQL\Scribe\Resolvers\BillableResolver;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Actions\AttachExpenseReceiptAction;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\RecordExpenseReimbursementAction;
use Kanvas\Scribe\Expenses\Actions\RejectExpenseAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\Actions\UpdateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\VoidExpenseAction;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseReceipt;
use Spatie\LaravelData\DataCollection;

class ExpenseMutation
{
    public function __construct(
        protected readonly BillableResolver $billableResolver = new BillableResolver(),
    ) {
    }

    public function create(mixed $rootValue, array $request): Expense
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateExpenseAction(
            data: $this->buildExpenseData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Expense
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Expense $expense */
        $expense = Expense::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateExpenseAction(
            expense: $expense,
            data: $this->buildExpenseData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function submitForApproval(mixed $rootValue, array $request): Expense
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Expense $expense */
        $expense = Expense::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new SubmitExpenseForApprovalAction(expense: $expense, user: $user)->execute();
    }

    public function approve(mixed $rootValue, array $request): Expense
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Expense $expense */
        $expense = Expense::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new ApproveExpenseAction(expense: $expense, approver: $user)->execute();
    }

    public function reject(mixed $rootValue, array $request): Expense
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Expense $expense */
        $expense = Expense::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new RejectExpenseAction(
            expense: $expense,
            rejector: $user,
            reason: $request['reason'] ?? null,
        )->execute();
    }

    public function void(mixed $rootValue, array $request): Expense
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Expense $expense */
        $expense = Expense::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new VoidExpenseAction(
            expense: $expense,
            voidReasonCode: (string) $request['void_reason_code'],
            user: $user,
        )->execute();
    }

    public function recordReimbursement(mixed $rootValue, array $request): Expense
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Expense $expense */
        $expense = Expense::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new RecordExpenseReimbursementAction(
            expense: $expense,
            user: $user,
            reimbursementPaymentId: isset($request['reimbursement_payment_id'])
                ? (int) $request['reimbursement_payment_id']
                : null,
        )->execute();
    }

    public function attachReceipt(mixed $rootValue, array $request): ExpenseReceipt
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Expense $expense */
        $expense = Expense::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        /** @var Filesystem $filesystem */
        $filesystem = Filesystem::query()
            ->where('id', (int) $input['filesystem_id'])
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->firstOrFail();

        return new AttachExpenseReceiptAction(
            expense: $expense,
            filesystem: $filesystem,
            user: $user,
            metadata: $input['metadata'] ?? null,
        )->execute();
    }

    private function buildExpenseData(
        array $input,
        AppInterface $app,
        CompanyInterface $company,
    ): ExpenseData {
        $vendor = $this->billableResolver->resolvePayeeOrNull(
            $input['vendor_billable_type'] ?? null,
            isset($input['vendor_billable_id']) ? (int) $input['vendor_billable_id'] : null,
            $app,
            $company,
        );

        $lines = new DataCollection(ExpenseLineData::class, array_map(
            fn (array $line): ExpenseLineData => new ExpenseLineData(
                description: $line['description'] ?? null,
                amount_native: (float) $line['amount_native'],
                expense_account_id: (int) $line['expense_account_id'],
                tax_amount_native: (float) ($line['tax_amount_native'] ?? 0),
                item_id: isset($line['item_id']) ? (int) $line['item_id'] : null,
                class_id: isset($line['class_id']) ? (int) $line['class_id'] : null,
                department_id: isset($line['department_id']) ? (int) $line['department_id'] : null,
                sort_order: isset($line['sort_order']) ? (int) $line['sort_order'] : null,
                metadata: $line['metadata'] ?? null,
            ),
            $input['lines'],
        ));

        return new ExpenseData(
            app: $app,
            company: $company,
            lines: $lines,
            expense_date: Carbon::parse((string) $input['expense_date']),
            currency: (string) $input['currency'],
            fx_rate_to_base: (float) ($input['fx_rate_to_base'] ?? 1.0),
            paid_by: ExpensePaidByEnum::from((string) $input['paid_by']),
            vendor: $vendor,
            paid_by_users_id: isset($input['paid_by_users_id']) ? (int) $input['paid_by_users_id'] : null,
            payment_method_id: isset($input['payment_method_id']) ? (int) $input['payment_method_id'] : null,
            bank_account_id: isset($input['bank_account_id']) ? (int) $input['bank_account_id'] : null,
            expense_number: $input['expense_number'] ?? null,
            notes: $input['notes'] ?? null,
            internal_notes: $input['internal_notes'] ?? null,
            regional_compliance: $input['regional_compliance'] ?? null,
            tax_metadata: $input['tax_metadata'] ?? null,
            metadata: $input['metadata'] ?? null,
        );
    }
}
