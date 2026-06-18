<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;

/**
 * Covers the auto-resolve-or-create vendor wiring added to ProposeBillFromPdfAction +
 * ProposeExpenseFromPdfAction. Two cases:
 *
 *   1. PDF with a vendor not in Guild yet → a fresh Org is created and linked
 *   2. PDF with the same vendor email as an existing Org → reuses that Org, no second row
 */
class AutoResolveVendorOnPdfIngestTest extends ScribeTestCase
{
    public function test_new_vendor_creates_org_and_links_to_bill(): void
    {
        $pdf = $this->createFilesystemRow();
        $uniqueEmail = 'invoice-' . uniqid() . '@aws.example.test';

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'auto-vendor-new-' . uniqid(),
                fromEmail: $uniqueEmail,
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.95,
                extracted: [
                    'vendor_name' => 'Brand New Vendor ' . uniqid(),
                    'vendor_email' => $uniqueEmail,
                    'bill_number' => 'BN-' . uniqid(),
                    'currency' => 'USD',
                    'subtotal' => 100.0,
                    'tax' => 0.0,
                    'total' => 100.0,
                ],
            )),
            hasher: new FakePdfContentHasher(),
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame('bill', $log->linked_entity_type);

        /** @var Bill $bill */
        $bill = Bill::query()->where('id', $log->linked_entity_id)->firstOrFail();
        $this->assertNotNull(
            $bill->vendor_organization_id,
            'Auto-resolve should have set the vendor FK on the draft Bill.',
        );

        $vendor = Organization::query()->where('id', $bill->vendor_organization_id)->first();
        $this->assertNotNull($vendor, 'A new Guild Organization should have been created.');
        $this->assertSame($uniqueEmail, $vendor->email, 'Vendor email from extraction stored on the Org.');
        $this->assertStringStartsWith('Brand New Vendor', (string) $vendor->name);
        $this->assertSame($this->kanvasApp->getId(), (int) $vendor->apps_id);
        $this->assertSame($this->company->getId(), (int) $vendor->companies_id);
    }

    public function test_seen_vendor_email_reuses_existing_org_no_duplicate(): void
    {
        $sharedEmail = 'recurring-' . uniqid() . '@vendor.test';

        // Pre-existing Org with this email
        $existing = Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => 'Established Vendor',
            'email' => $sharedEmail,
            'address' => '',
            'total_employees' => 0,
        ]);

        $orgCountBefore = Organization::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->count();

        $pdf = $this->createFilesystemRow();
        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'auto-vendor-reuse-' . uniqid(),
                fromEmail: 'mailgun-sender@unrelated.test',  // Mailgun sender doesn't matter
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.9,
                extracted: [
                    'vendor_name' => 'Established Vendor Renamed In LLM',  // name differs
                    'vendor_email' => $sharedEmail,                        // ← match by email
                    'bill_number' => 'BN-' . uniqid(),
                    'currency' => 'USD',
                    'subtotal' => 200.0,
                    'tax' => 0.0,
                    'total' => 200.0,
                ],
            )),
            hasher: new FakePdfContentHasher(),
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);

        /** @var Bill $bill */
        $bill = Bill::query()->where('id', $log->linked_entity_id)->firstOrFail();
        $this->assertSame(
            (int) $existing->id,
            (int) $bill->vendor_organization_id,
            'Bill should link to the pre-existing Org, not a freshly-created one.',
        );

        $orgCountAfter = Organization::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->count();
        $this->assertSame(
            $orgCountBefore,
            $orgCountAfter,
            'No new Org should have been created when an email match existed.',
        );
    }
}
