# Decision 0002: Provider-Neutral Payment, SMS, and Error Adapters

Status: provider-neutral defaults accepted

LiteGig ships provider-neutral adapters instead of hardcoding Stripe, Twilio, or an error tracking vendor.

Current behavior:
- Manual payment confirmation is the default.
- Gateway payments are feature-flagged with `LITEGIG_PAYMENT_GATEWAY_ENABLED`.
- Gateway webhooks require `X-LiteGig-Signature: sha256=<hmac>` using `LITEGIG_PAYMENT_GATEWAY_WEBHOOK_SECRET`.
- SMS notifications use `LITEGIG_SMS_DRIVER=log` by default or a signed webhook adapter when configured.
- Error tracking logs JSON locally and can post signed events to `LITEGIG_ERROR_WEBHOOK_URL`.

No provider decision is required for a low-infrastructure PHP + SQLite launch. Log-only notifications, manual payments, and local JSON error logs are acceptable defaults when paired with health checks, backups, and production audit.

Owner decision needed only before paid/external provider wiring:
- Payment checkout provider and webhook payload mapping.
- SMS provider adapter endpoint.
- Error/uptime monitoring vendor or internal endpoint.
