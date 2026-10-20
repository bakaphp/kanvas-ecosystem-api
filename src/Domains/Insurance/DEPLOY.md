# Insurance: deploy and configuration

Run once per environment, per app that sells insurance. Keep this file in sync when an activity, setting or command is added.

## Steps

1. Create the `insurance` database (`DB_INSURANCE_DATABASE`) and run migrations.
2. Restart Octane and workers, clear the config cache.
3. Azul certificates are per app (`AZUL_CERT/KEY/CA` in settings, `APP_KEY` must be stable). If missing or in the old format:
   `php artisan azul:import-cert {app_id} --cert=<client.crt> --key=<azul.key> --ca=<chain.pem>`
4. `php artisan kanvas:insurance-setup-order-type {app_id}` — pipeline, carnet, Azul-only restriction, expiry rule, templates. Re-run on every environment.
5. `php artisan kanvas:insurance-seed-catalog --app_id={id} --company_id={id}`
6. Set `souk_check_expired_orders = 1` on the app and keep the scheduler running (reminders, `kanvas-souk:order-finish-expired`).
7. DOP region on the selling company, and the insurer integration (Universal Seguros credentials) on the app and company.
8. Create the workflow rules below.

## Optional per-app settings

`insurance_reminder_days` (default `30,15,7,3,1,0`), `insurance_reminder_channels` (default `mail,push,database`),
`insurance_payment_processors` (default `azul`). Comma list or JSON.

## Workflow rules

Nothing is seeded. Create one rule per row, filtered by the `insurance` order type.

| Activity | Trigger | Required | Without it |
|---|---|---|---|
| `AttachInsuranceQuoteActivity` | Order `created`, `is_async = false` | Yes | Order is never validated; Report voids the hold |
| `UploadInsuranceDocumentsActivity` | File attached to the order | Only if the insurer inspects (Universal) | Emission waits for documents |
| `ReportInsurancePaymentActivity` | Card authorized | Yes | Emit refuses to run |
| `EmitInsurancePolicyActivity` | `status-transition` to `payment_reported` | Yes | No policy, hold never captured |
| `InvoiceInsurancePolicyActivity` | `status-transition` to `emitted` | Only if the insurer invoices (Universal) | Policy is not invoiced |
| `GenerateInsuranceCarnetActivity` | `status-transition` to `policy_active` | Yes | No carnet, no email |
| `SendInsurancePolicyEmailActivity` | After the carnet | Yes | Customer is never emailed |
| `SyncInsurancePolicyActivity` | Schedule | No | Pending emissions are not retried |
| `CancelInsuranceOrderActivity` | `status-transition` to `cancelled` | Recommended | Hold stays until the bank expires it |

Full domain notes: [CLAUDE.md](CLAUDE.md).
