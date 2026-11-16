<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account - Tharimpepe Feeding Scheme</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f2f4f7; min-height: 100vh; display: grid; place-items: center; padding: 20px; font-family: Arial, sans-serif; }
        main { width: 100%; max-width: 520px; background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 8px 30px rgba(16, 24, 40, .1); }
        .verification-form { border-top: 1px solid #eaecf0; margin-top: 20px; padding-top: 20px; }
        .status { color: #475467; font-size: 14px; }
    </style>
</head>
<body>
<main>
    <h1 class="h3">Verify your account</h1>
    <p class="status">Verification is required before sign-in. Administrator approval remains a separate step.</p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (!empty($registrationDelivery['email']) || !empty($registrationDelivery['sms'])): ?>
        <div class="alert alert-info">
            Initial delivery status — email: <?php echo htmlspecialchars($registrationDelivery['email'] ?? 'not sent', ENT_QUOTES, 'UTF-8'); ?>;
            SMS: <?php echo htmlspecialchars($registrationDelivery['sms'] ?? 'not sent', ENT_QUOTES, 'UTF-8'); ?>.
            A simulated delivery does not send a real message.
        </div>
    <?php endif; ?>

    <p>Email: <strong><?php echo htmlspecialchars($registrationUser['Email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
        — <?php echo !empty($registrationUser['EmailVerifiedAt']) ? 'verified' : 'not verified'; ?></p>
    <p>Phone: <strong><?php echo htmlspecialchars($registrationUser['Phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
        — <?php echo !empty($registrationUser['SmsVerifiedAt']) ? 'verified' : 'not verified'; ?></p>

    <?php if (empty($registrationUser['EmailVerifiedAt'])): ?>
        <form method="post" class="verification-form">
            <?php echo csrfTokenInput(); ?>
            <input type="hidden" name="verification_action" value="verify_email">
            <label for="email_token" class="form-label">Email verification code</label>
            <input id="email_token" name="email_token" class="form-control mb-2" autocomplete="one-time-code">
            <button class="btn btn-primary" type="submit">Verify email</button>
        </form>
    <?php endif; ?>

    <?php if (empty($registrationUser['SmsVerifiedAt'])): ?>
        <form method="post" class="verification-form">
            <?php echo csrfTokenInput(); ?>
            <input type="hidden" name="verification_action" value="verify_sms">
            <label for="sms_otp" class="form-label">SMS verification code</label>
            <input id="sms_otp" name="sms_otp" class="form-control mb-2" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
            <button class="btn btn-primary" type="submit">Verify phone</button>
        </form>
    <?php endif; ?>

    <div class="d-flex gap-2 flex-wrap mt-3">
        <?php if (empty($registrationUser['EmailVerifiedAt'])): ?>
            <form method="post">
                <?php echo csrfTokenInput(); ?>
                <input type="hidden" name="verification_action" value="resend_email">
                <button class="btn btn-outline-secondary" type="submit">Resend email code</button>
            </form>
        <?php endif; ?>
        <?php if (empty($registrationUser['SmsVerifiedAt'])): ?>
            <form method="post">
                <?php echo csrfTokenInput(); ?>
                <input type="hidden" name="verification_action" value="resend_sms">
                <button class="btn btn-outline-secondary" type="submit">Resend SMS code</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($registrationUser['EmailVerifiedAt']) && !empty($registrationUser['SmsVerifiedAt'])): ?>
        <p class="alert alert-info mt-3 mb-0">Both contacts are verified. Your account is still pending administrator approval.</p>
    <?php endif; ?>
    <p class="mt-4 mb-0"><a href="/index.php?action=login">Return to sign in</a></p>
</main>
</body>
</html>
