# Zoho sales import

Imports sales-order, package and invoice list records from the existing Zoho Inventory pull organization. The ERP page is **Flow â†’ Zoho Sales / Packages / Invoices** (`/flow/zoho-sales`), protected by `flow.order.view`.

Run against the ERP database:

```sh
php artisan migrate --path=database/migrations/2026_11_04_000001_create_zoho_sales_documents_table.php --force
php artisan zoho:pull-sales --max-pages=10
```

Rerun until all three modules report scan complete. To resume just one unfinished module, use `--module=salesorders`, `--module=packages` or `--module=invoices`. Progress is saved per organization and module after each complete page. Each run fetches at most the configured number of pages per module; records are bulk-upserted by organization, type and Zoho ID. Failed pages remain retryable. A completed scan restarts at page 1 on the next run to refresh records. Imports preserve the list payload, numbers, customer, date, status, totals and available sales-order references. They do not import PDF attachments or full line-item details, or create dispatches and stock movements.

Enable hourly refresh at minute 40 with `ZOHO_SALES_PULL_ENABLED=true` and the existing Laravel scheduler. Credentials use `services.zoho_items_pull`, consistent with existing pulls. API calls use the Inventory quota gate. A concurrent command is blocked by an organization-specific lock.
