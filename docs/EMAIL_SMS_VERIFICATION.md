# Email and SMS verification testing

New website and Capacitor registrations require both email and South African mobile-phone verification before sign-in. Administrator approval remains separate. Accounts that existed when the migration runs receive a 60-day email-verification grace period; legacy accounts are not required to verify a phone number.

## Provider setup

The application reads configuration from the PHP process environment; it does not load `.env` files. Set values in the environment used to launch PHP, then restart the PHP server.

### SendGrid trial

1. Create a SendGrid account and verify a sender address (single-sender verification is suitable for a trial; domain authentication is preferred for production).
2. Create an API key with Mail Send permission.
3. Configure:

   ```text
   EMAIL_PROVIDER=sendgrid
   SENDGRID_API_KEY=<local secret>
   EMAIL_FROM_ADDRESS=<verified sender address>
   EMAIL_FROM_NAME=Tharimpepe Feeding Scheme
   ```

### Twilio trial

1. Create a Twilio trial account and obtain its Account SID and Auth Token.
2. Acquire/configure an SMS-capable Twilio sender and verify the test recipient number in the Twilio console.
3. Configure:

   ```text
   SMS_PROVIDER=twilio
   SMS_ACCOUNT_SID=<account SID>
   SMS_AUTH_TOKEN=<local secret>
   SMS_FROM=<Twilio sender in E.164 format>
   ```

Twilio trial restrictions apply: messages can be sent only to verified recipients and within the available trial balance. The application does not fall back to paid SMS delivery. When trial sending is unavailable, the verification request reports a delivery failure; no OTP is returned by the API or written to logs.

Do not put provider credentials in source files, `.env.example`, commits, or chat. `.env.example` contains names only.

## End-to-end test

1. Apply `sql/migrations/20261114_email_sms_payfast_integration.sql` to the intended MySQL database after checking its identity and taking the required backup. The migration adds verification tables/columns and grants existing accounts 60 days from migration time; do not run it against a production database without approval.
2. Start/restart PHP with both providers configured and `APP_ENV=production` (mock delivery is intentionally disabled in production).
3. Register through the website or Capacitor app with an email address you can access and a Twilio-verified South African mobile number.
4. Confirm the email code and SMS code in the registration verification screen. Codes expire (email: 30 minutes; SMS: 5 minutes); resend is throttled.
5. Confirm sign-in is blocked until both are verified. It remains blocked while the account is pending administrator approval; approve the account separately, then sign in.
6. For an existing account, verify sign-in remains available during the migration-granted email grace period. Changing an email address must leave the current address in place until the new address is verified.

`EMAIL_PROVIDER=mock` and `SMS_PROVIDER=mock` are local development modes only. They simulate delivery and intentionally do not disclose the generated codes, so they cannot complete an end-to-end verification.
