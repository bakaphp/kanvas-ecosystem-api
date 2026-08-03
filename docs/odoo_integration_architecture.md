# Odoo Integration Architecture & Specification

This document outlines the integration strategy, data mapping, and sync flows between the Kanvas Ecosystem Backend and Odoo ERP. The integration utilizes a bi-directional, event-driven pattern powered by the Kanvas Nervous System (Event Queue) and Odoo's XML-RPC / JSON-RPC API.

## 1. Core Integration Protocol & Auth Setup
To communicate with Odoo, Kanvas services will connect to Odoo’s external API endpoints using XML-RPC (via standard port 8069 or SSL 443).
Connection Parameters:
- url: The Odoo instance URL (e.g., https://my-company.odoo.com)
- db: The database name
- username: The API/Integration user email
- password: The Odoo API Key (highly recommended over standard passwords for security compliance)

## 2. Product Domain Integration
The Product Domain manages catalogs, variants, pricing, and stock levels.
Key Entity Mappings:
- Kanvas `Products` -> Odoo `product.template`
- Kanvas `ProductVariants` -> Odoo `product.product`
- Kanvas `Categories` -> Odoo `product.category`
- Kanvas `Inventory/Stock` -> Odoo `stock.quant` / `stock.inventory`

Sync Flows:
1. Kanvas -> Odoo (Product Creation/Updates): Triggered on `product.created` or `product.updated`. It checks/creates categories, updates product.template, and maps Odoo's generated variant IDs back to Kanvas `ProductVariants.external_id`.
2. Odoo -> Kanvas (Stock Level Sync): Triggered by inventory changes in Odoo. Odoo alerts Kanvas with the new quantity of a variant via webhook, updating Kanvas's local `ProductVariants.quantity`.

## 3. Commerce Domain Integration
The Commerce Domain handles customers, carts, discounts, and checkout.
Key Entity Mappings:
- Kanvas `Customers` -> Odoo `res.partner` (with customer_rank > 0)
- Kanvas `Orders` -> Odoo `sale.order`
- Kanvas `OrderItems` -> Odoo `sale.order.line`

Sync Flows:
1. Customer Handshake: On `checkout.initiated` or `order.created`, search for the customer by email/external_id in Odoo's `res.partner`. Create if missing.
2. Sales Order Creation: On `order.created` (paid), create a `sale.order` in Odoo under the mapped partner_id. Create `sale.order.line` items mapped to the appropriate `product_product` IDs. Confirm the order to reserve warehouse inventory.

## 4. Accounting Domain Integration
Tracks invoices, tax collections, payment captures, and ledger entries.
Key Entity Mappings:
- Kanvas `Invoice` -> Odoo `account.move` (with move_type='out_invoice')
- Kanvas `InvoiceLines` -> Odoo `account.move.line`
- Kanvas `Payment` -> Odoo `account.payment`
- Kanvas `Tax` -> Odoo `account.tax`

Sync Flows:
1. Invoice Creation: On `order.paid`, create `account.move` with standard revenue account lines and taxes, then post it via `action_post()` to finalize the record.
2. Payment Reconciliation: Create `account.payment` representing the received funds and reconcile it to the open invoice's outstanding lines.

## 5. Souk Domain (Marketplace & Multi-Vendor) Integration
The Souk Domain governs multi-vendor marketplaces, consignment items, vendor settlements, and commission tracking.
Key Entity Mappings:
- Kanvas `Souk Vendor/Merchant` -> Odoo `res.partner` (supplier_rank > 0)
- Kanvas `Multi-Vendor Order Split` -> Odoo `purchase.order` (per vendor)
- Kanvas `Commission/Settlement Rules` -> Odoo `account.analytic.account`

Sync Flows:
1. Vendor Mapping: Map newly approved vendors to Odoo suppliers (`res.partner` with `supplier_rank = 1`).
2. Order Splits: When an order contains items from multiple vendors, the platform creates a Sales Order (`sale.order`) and triggers individual Purchase Orders (`purchase.order`) for each supplier for automated consignment and dropshipping.
3. Settlements: Map commission rules to Odoo Analytic Accounts to track platform cuts and distribute vendor payouts seamlessly.

## 6. Implementation Checklist
1. Provision Odoo Integration User with restricted permissions.
2. Securely store Odoo API credentials in the server environment configuration.
3. Write an Odoo XML-RPC Client wrapper in the backend code using `ripaclet/odoo-client` or XML-RPC calls.
4. Register event observers/listeners in the Nervous System for sync hooks.
5. Setup robust retry mechanisms (DLQ) for network/timeout robustness.
