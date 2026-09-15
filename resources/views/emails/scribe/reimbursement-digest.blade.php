<div style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #111; max-width: 720px;">
    <h1 style="margin-bottom: 4px;">{{ $company_name }} owes you {{ number_format($total_owed, 2) }} {{ $currency }}</h1>
    <p style="color: #555; margin-top: 0;">
        {{ $employee_name }} · {{ $expense_count }} approved expense{{ $expense_count === 1 ? '' : 's' }} awaiting
        reimbursement as of {{ $as_of }}
    </p>

    @if ($days_outstanding >= 60)
        <p style="background: #fff4e5; border-left: 3px solid #d97706; padding: 10px 12px; margin: 16px 0;">
            The oldest of these has been outstanding for {{ $days_outstanding }} days.
        </p>
    @endif

    <table style="border-collapse: collapse; width: 100%; margin-top: 16px;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid #e5e5e5;">
                <th style="padding: 8px 0;">Month</th>
                <th style="padding: 8px 0;">Expenses</th>
                <th style="padding: 8px 0; text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($months as $month)
                <tr style="border-bottom: 1px solid #f2f2f2;">
                    <td style="padding: 8px 0;">{{ $month['label'] }}</td>
                    <td style="padding: 8px 0;">{{ $month['expense_count'] }}</td>
                    <td style="padding: 8px 0; text-align: right;">{{ number_format((float) $month['total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="color: #666; font-size: 13px; margin-top: 24px;">
        This covers expenses you paid personally that have been approved but not yet paid back. Anything you
        submitted that a manager has not approved yet is not counted here.
    </p>
</div>
