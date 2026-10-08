# payOS integration

## Configuration

Set these server-side variables. Do not commit their values or expose them to React:

```dotenv
PAYOS_CLIENT_ID=
PAYOS_API_KEY=
PAYOS_CHECKSUM_KEY=
PAYOS_RETURN_URL=http://localhost:5173/payments/payos/success
PAYOS_CANCEL_URL=http://localhost:5173/payments/payos/cancel
PAYOS_WEBHOOK_URL=https://your-public-api.example/api/webhooks/payos
```

Register `PAYOS_WEBHOOK_URL` in the payOS dashboard. The webhook endpoint is public because payOS calls it; it verifies every payload with the official SDK before changing any invoice or payment data.

After changing the URL, register it with payOS from the server (do not expose this action as an API endpoint):

```bash
php artisan config:clear
php artisan payos:confirm-webhook
```

## Flow

1. An authenticated invoice owner calls `POST /api/invoices/{invoiceId}/payos`.
2. The API accepts only `UNPAID` or `PARTIALLY_PAID` invoices with a positive remaining whole-VND amount. It persists a separate `payos_payment_requests` row, then returns the payOS checkout URL and QR payload.
3. React displays the QR / checkout link and polls only its own invoice while the dialog is open. A redirect success or cancel page also reads invoice state from the API; neither page marks an invoice paid.
4. payOS calls `POST /api/webhooks/payos`. The server verifies the signature, matches the persisted order code, validates the amount, creates a `PAYOS` payment, and recalculates the invoice in a database transaction.

## Idempotency and reconciliation

- A valid existing pending request for the same invoice and amount is reused.
- An ambiguous SDK create failure remains `CREATION_FAILED` instead of automatically issuing another order. Reconcile it with payOS before retrying, so a network timeout cannot create duplicate QR requests.
- `payments` has a unique `(payment_method, external_reference)` constraint. Duplicate webhook deliveries therefore cannot add a second payment.
- Unknown orders, mismatched payment-link IDs, mismatched amounts, and overpayment attempts are logged and never create a payment. Mismatched requests are marked `RECONCILIATION_REQUIRED` for manual follow-up.
- Manual `CASH`, `BANK_TRANSFER`, `CARD`, and `OTHER` payments remain on their existing protected endpoints. `PAYOS` is never accepted through the manual form/API.
