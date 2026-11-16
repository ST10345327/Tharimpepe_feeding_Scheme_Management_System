# Database migrations

Apply migrations to an existing FSMS database before deploying code that depends on them. For the API token migration, run `20261002_create_api_tokens.sql` against the configured FSMS database using MySQL or phpMyAdmin. New installations receive the same table through `sql/schema.sql`.

Run `20261109_add_volunteer_registration_status.sql` against existing databases to add the volunteer approval status used when an administrator reviews a registration. The migration backfills existing volunteer status from each linked user's account status. New installations receive this column through `sql/schema.sql`.

The token migration creates an initially empty table. Existing MD5-derived API tokens will no longer authenticate after the new API code is deployed; users must sign in again. New tokens are random bearer values, stored as SHA-256 hashes, expire after 30 days, and are revoked on logout or password change.

Before deploying email/SMS verification code to an existing database, back up and verify the target database, then apply `20261114_email_sms_payfast_integration.sql`. Existing accounts receive a 60-day email-verification grace period beginning when this migration runs. Public registrations require email and SMS verification before sign-in; admin approval remains separate. Configure real SendGrid and Twilio trial credentials in the PHP process environment for end-to-end testing as described in [Email and SMS verification testing](../../docs/EMAIL_SMS_VERIFICATION.md). Do not apply this migration to production without explicit approval.
