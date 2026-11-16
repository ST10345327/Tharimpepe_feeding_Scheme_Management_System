# FSMS Google Sign-In

## Status and architecture

FSMS keeps its PHP MVC website sessions, MySQL `Users` records, role/status rules, and existing opaque API bearer tokens. Google is an OpenID Connect identity provider used to authenticate an FSMS account; it does not replace the FSMS account, password hash, role, or approval state.

```mermaid
sequenceDiagram
    participant User
    participant Website as PHP website / Android app
    participant Google as Google Identity Services / Credential Manager
    participant API as FSMS PHP API
    participant DB as MySQL

    Website->>API: POST /auth/google/challenge
    API->>DB: Store SHA-256(nonce), expiry, unused state
    API-->>Website: nonce + public web client ID
    Website->>Google: Sign in with nonce
    Google-->>Website: Signed ID token containing nonce
    Website->>API: ID token + nonce
    API->>Google: Verify signature, issuer, audience, expiry
    API->>DB: Consume nonce once; resolve provider + sub
    API-->>Website: PHP session (website) or FSMS bearer token (Android)
```

The browser uses Google Identity Services and submits its ID token to the PHP website or API. The Android app uses native Credential Manager, not an embedded webview, and sends the ID token to the same API. The backend verifies the signature using Google's published keys and validates issuer, audience, expiry, nonce, and authorized party when multiple audiences are present. It first looks up the Google `sub`; on first sign-in it can link exactly one active FSMS account when the verified Google email matches.

The website establishes the existing PHP session after regenerating its session ID. Android receives the existing opaque FSMS bearer token from `ApiTokens`; protected APIs continue to validate the stored token hash, expiry, revocation state, and active account status. The Android token is encrypted using a key held by Android Keystore. Browser SPA tokens remain in memory; legacy local-storage tokens are removed (and on Android are migrated once into Keystore). No Google ID token or OAuth client secret is stored by FSMS.

Logout revokes the FSMS API token and clears local authentication. It does not globally sign the user out of Google or guarantee that another client is signed out. The existing FSMS API token lifetime is 30 days and there is no refresh-token endpoint; users must authenticate again after expiry.

## Google Cloud setup

1. Create or select a Google Cloud project and configure the OAuth consent screen for the intended test or production audience.
2. Create a **Web application** OAuth client. Its client ID is `GOOGLE_WEB_CLIENT_ID`. Add the exact HTTPS site origin to Authorized JavaScript origins (for example, the private Tailscale HTTPS origin used for development). Google Identity Services uses its JavaScript callback, so this integration does not require a redirect URI.
3. Create an **Android** OAuth client with package `com.tharimpepe.fsms` and the SHA-1 fingerprint for each signing key used. Obtain the debug fingerprint with `cd android; .\gradlew signingReport`. Add the release signing fingerprint separately before release.
4. Android Credential Manager requests the ID token using the **Web application client ID** as `serverClientId`. The Android OAuth client registration is associated with package and certificate; no client secret or `google-services.json` is embedded in the app.
5. Add eligible test users to the consent screen while the app is in testing mode.

The Web client ID is public configuration, not a secret. Do not put a client secret in PHP, JavaScript, or the APK. The server verifies the token audience against the Web client ID.

## PHP and database setup

1. Configure the PHP process environment with `GOOGLE_WEB_CLIENT_ID` from the Web application client. `.env.example` is a reference file; this project does not automatically parse `.env`. For XAMPP, configure the variable in the Apache service environment or a protected Apache configuration and restart Apache. For the PHP development server, set the variable in the launching PowerShell process. Do not commit credentials.
2. Install PHP dependencies from the lock file: `composer install --no-dev --optimize-autoloader`. This installs the official Google API PHP Client used for ID-token verification. Commit `composer.json` and `composer.lock`, never `vendor/`.
3. Back up the database, then execute [`sql/migrations/20261109_add_google_identity_links.sql`](../sql/migrations/20261109_add_google_identity_links.sql) followed by [`sql/migrations/20261109_bind_google_auth_challenges.sql`](../sql/migrations/20261109_bind_google_auth_challenges.sql) against the FSMS schema. These additive migrations create identity/nonce tables and then add challenge purpose/account/session binding. Existing challenges default to login purpose; Users, passwords, roles, and API tokens are not changed. The final table definitions are in `sql/schema.sql` for new installations.
4. Keep production PHP/API access on HTTPS. Google requires HTTPS origins except for approved local development origins. Never expose MySQL to public internet access.

`GOOGLE_ANDROID_CLIENT_ID` in `.env.example` is informational only and is not read by the server; package name and signing SHA-1 are configured in Google Cloud. Only `GOOGLE_WEB_CLIENT_ID` is required at runtime.

## Existing-account linking and registration

On first Google sign-in, FSMS automatically links an active account when exactly one FSMS user has the same email and Google asserts `email_verified=true`. Email matching is case-insensitive. Unverified addresses, unmatched or duplicate FSMS email records, and accounts that already have a different Google identity are not auto-linked; those users must sign in with their FSMS credentials and link Google from their profile. Google may not be authoritative for the current ownership of some third-party email addresses even when previously verified, so those addresses should be kept current in FSMS. The API link endpoint also requires a currently valid FSMS bearer token. A Google subject can map to only one FSMS account, and an FSMS account can have only one Google identity for this provider.

New users continue to register through existing FSMS registration and receive the existing pending approval status. They cannot sign in until an administrator activates the account. Existing roles and status checks remain authoritative.

## Tailscale development

1. Install and authenticate Tailscale on the Windows PHP host and teammate devices. Use Tailscale Serve to proxy to the local PHP server (for example, the existing port 8080) over the host's private HTTPS DNS name. Do not enable Tailscale Funnel.
2. Copy `www/js/config.local.example.js` to the ignored `www/js/config.local.js` and set `window.FSMS_API_BASE_URL` to `https://<host>.<tailnet>.ts.net/api`. The API URL must be HTTPS.
3. Use the same HTTPS website origin for browser login and register it in Google Cloud. For Android, run `npx cap sync android`, build from `android`, and install the debug APK on a device with Tailscale access.
4. Allow inbound access only to the private web service as required by the tailnet. Keep MySQL listening on loopback (for example, `127.0.0.1:3306`) and block port 3306 in Windows Firewall; PHP connects to MySQL locally.

The ignored `config.local.js` is copied into Android assets during Capacitor sync. It must not be committed. A teammate must have tailnet access and configure their own local URL before building.

## API and session behavior

| Endpoint | Authentication | Behavior |
|---|---|---|
| `POST /api/auth/google/challenge` | None | Returns public client ID and a five-minute, one-use nonce |
| `POST /api/auth/google/link-challenge` | Existing FSMS bearer token | Returns a five-minute link nonce bound to the authenticated FSMS user |
| `POST /api/auth/google` | Google ID token + nonce | Verifies token, finds or safely auto-links the matching active FSMS user, issues the existing opaque API token |
| `POST /api/auth/google/link` | Existing FSMS bearer token + Google ID token + nonce | Links the verified Google subject to the current FSMS user |
| `POST /api/logout` | Existing FSMS bearer token | Revokes that API token |

The website callback is `/index.php?action=google_login`; linking challenge creation and linking from the website profile both require the current session and CSRF validation. Website linking challenges are bound to a hash of the current session ID and FSMS user ID; API linking challenges are bound to the authenticated API user ID. Login challenges remain public and have the `login` purpose, so they cannot be consumed for account linking. The website callback uses a separate Google-only form and the same session fields and role-based dashboard routing as password login.

## Verification checklist and current evidence

Automated checks run for this change:

- PHP syntax validation passed for changed PHP files.
- PHP test suite passed: 29 tests, 57 assertions.
- Google identity-linking unit coverage verifies subject lookup, uniqueness, idempotent relinking, rejection of account conflicts, nonce purpose/user/session binding, and expiry.
- Website Google login now posts through a separate credential form, avoiding password form constraint validation.
- Android debug build (`:app:assembleDebug`) passed.
- Composer audit reported no known advisories for the locked dependencies.

Live Google authentication has **not** been verified: no Google Cloud Web client ID, Android package/SHA-1 registration, test account, migrated deployment database, or Tailscale HTTPS endpoint was provided. Configure those first, then test:

1. Website password login and Android password login still work.
2. Invalid, expired, wrong-audience, wrong-issuer, and replayed-nonce Google tokens are rejected.
3. An unlinked identity is rejected; linking requires an authenticated FSMS user and a verified Google subject.
4. Pending/inactive users remain blocked and roles/data access remain unchanged.
5. Linked users can sign in through Google on both website and Android; browser/OS session reuse depends on provider and device behavior and must be tested separately.
6. API bearer tokens are accepted by protected routes, expire according to existing policy, and are rejected after logout/revocation.
7. Website PHP session ID rotates after successful Google sign-in.
8. Registration and password recovery continue to use existing flows.

Do not claim cross-client session reuse or global logout without testing those behaviors on the target browser and Android device. The native Google account chooser and browser Google session are separate session contexts.

## Deployment notes and limitations

- Production requires an HTTPS PHP host, MySQL with the additive migration applied, Composer dependencies installed, and a securely configured `GOOGLE_WEB_CLIENT_ID`.
- Tailscale is a private development/testing network, not the public production identity or API hosting plan.
- API bearer tokens retain the existing 30-day expiry. This change does not add refresh tokens, Google refresh-token storage, global sign-out, or automatic account linking.
- Existing API CORS behavior is unchanged; production should restrict allowed origins to the website and required Capacitor origins in a separate reviewed change.
