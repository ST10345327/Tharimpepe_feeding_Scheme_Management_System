<?php

class PayFastService
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function isSandboxMode()
    {
        return strtolower((string)(getenv('PAYFAST_MODE') ?: 'sandbox')) === 'sandbox';
    }

    public static function getMerchantId()
    {
        $merchantId = getenv('PAYFAST_MERCHANT_ID');
        return is_string($merchantId) ? trim($merchantId) : '';
    }

    public static function getMerchantKey()
    {
        $merchantKey = getenv('PAYFAST_MERCHANT_KEY');
        return is_string($merchantKey) ? trim($merchantKey) : '';
    }

    public static function getPassphrase()
    {
        $passphrase = getenv('PAYFAST_PASSPHRASE');
        return is_string($passphrase) ? trim($passphrase) : '';
    }

    public static function hostedCheckoutUrl($returnUrl, $cancelUrl, $notifyUrl, $reference, $amount, $currency = 'ZAR')
    {
        $merchantId = self::getMerchantId();
        $merchantKey = self::getMerchantKey();
        if ($merchantId === '' || $merchantKey === '') {
            throw new RuntimeException('PayFast checkout is not configured.');
        }

        $sandbox = self::isSandboxMode();
        $base = $sandbox ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process';
        $params = [
            'merchant_id' => $merchantId,
            'merchant_key' => $merchantKey,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'notify_url' => $notifyUrl,
            'm_payment_id' => (string)$reference,
            'amount' => number_format((float)$amount, 2, '.', ''),
            'currency' => strtoupper($currency),
            'item_name' => 'FSMS Donation',
            'item_description' => 'Tharimpepe Feeding Scheme donation',
        ];

        return $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function createPendingDonation(array $donation)
    {
        $amount = isset($donation['amount']) ? (float)$donation['amount'] : 0.0;
        $reference = $donation['reference'] ?? bin2hex(random_bytes(12));
        $currency = strtoupper((string)($donation['currency'] ?? 'ZAR'));
        if ($amount <= 0) {
            throw new InvalidArgumentException('Donation amount must be greater than zero.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO DonationPayments (
                DonationID, Provider, PaymentReference, MerchantReference, Amount, Currency,
                Status, CreatedAt, UpdatedAt, RequestData
             ) VALUES (:donation_id, :provider, :payment_reference, :merchant_reference, :amount, :currency,
                :status, :created_at, :updated_at, :request_data)'
        );
        $stmt->execute([
            ':donation_id' => isset($donation['donation_id']) ? (int)$donation['donation_id'] : 0,
            ':provider' => 'payfast',
            ':payment_reference' => (string)$reference,
            ':merchant_reference' => (string)$donation['merchant_reference'] ?? $reference,
            ':amount' => number_format($amount, 2, '.', ''),
            ':currency' => $currency,
            ':status' => 'pending',
            ':created_at' => gmdate('Y-m-d H:i:s'),
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':request_data' => json_encode($donation),
        ]);

        return [
            'reference' => $reference,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'status' => 'pending',
            'payment_id' => $this->db->lastInsertId(),
        ];
    }

    public function verifySignature(array $itnData)
    {
        $signature = (string)($itnData['signature'] ?? '');
        $merchantId = (string)($itnData['merchant_id'] ?? '');
        $merchantKey = (string)($itnData['merchant_key'] ?? '');
        $passphrase = self::getPassphrase();

        $data = $itnData;
        unset($data['signature']);
        ksort($data);
        $string = '';
        foreach ($data as $key => $value) {
            $string .= $key . '=' . $value . '&';
        }
        $string = rtrim($string, '&');
        if ($passphrase !== '') {
            $string .= '&passphrase=' . $passphrase;
        }

        $expected = md5($string);
        return hash_equals(strtolower($expected), strtolower($signature))
            && $merchantId !== ''
            && $merchantKey !== '';
    }

    private static function normalizePaymentStatus($value)
    {
        $status = strtoupper(trim((string)$value));

        if ($status === 'COMPLETE' || $status === 'PAID') {
            return 'successful';
        }
        if ($status === 'FAILED') {
            return 'failed';
        }
        if ($status === 'CANCELLED') {
            return 'cancelled';
        }

        return null;
    }

    public function processItn(array $itn)
    {
        $reference = (string)($itn['m_payment_id'] ?? $itn['pf_payment_id'] ?? $itn['merchant_reference'] ?? '');
        if ($reference === '') {
            return ['status' => 'invalid', 'message' => 'Payment reference missing.'];
        }

        if (!$this->verifySignature($itn)) {
            return ['status' => 'invalid', 'message' => 'PayFast signature verification failed.'];
        }

        if ((string)($itn['merchant_id'] ?? '') !== self::getMerchantId()) {
            return ['status' => 'invalid', 'message' => 'Unknown merchant identity.'];
        }

        $paymentStatus = self::normalizePaymentStatus($itn['payment_status'] ?? '');
        if ($paymentStatus === null) {
            return ['status' => 'invalid', 'message' => 'Unsupported PayFast payment status.'];
        }

        $amount = (float)($itn['amount_gross'] ?? $itn['amount'] ?? 0);
        $currency = strtoupper((string)($itn['currency'] ?? 'ZAR'));
        $merchantReference = (string)($itn['merchant_reference'] ?? $reference);

        $stmt = $this->db->prepare(
            'SELECT PaymentID, DonationID, Amount, Currency, Status, PaymentReference, MerchantReference
             FROM DonationPayments
             WHERE (PaymentReference = :reference OR MerchantReference = :merchant_reference)
             ORDER BY CreatedAt DESC LIMIT 1'
        );
        $stmt->execute([
            ':reference' => $reference,
            ':merchant_reference' => $merchantReference,
        ]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return ['status' => 'invalid', 'message' => 'No matching pending donation found.'];
        }
        if (($payment['PaymentReference'] ?? '') !== $reference && ($payment['MerchantReference'] ?? '') !== $merchantReference) {
            return ['status' => 'invalid', 'message' => 'Notification does not match the stored PayFast reference.'];
        }

        if ((float)$payment['Amount'] !== $amount) {
            return ['status' => 'invalid', 'message' => 'Payment amount does not match the donation record.'];
        }
        if (strtoupper((string)$payment['Currency']) !== $currency) {
            return ['status' => 'invalid', 'message' => 'Payment currency does not match the donation record.'];
        }

        $existingSuccess = $this->db->prepare(
            'SELECT PaymentID FROM DonationPayments WHERE PaymentReference = :reference AND LOWER(Status) = :status LIMIT 1'
        );
        $existingSuccess->execute([':reference' => $reference, ':status' => 'successful']);
        if ($existingSuccess->fetchColumn()) {
            return ['status' => 'duplicate', 'message' => 'Duplicate payment notification ignored.'];
        }

        $newStatus = $paymentStatus;
        $update = $this->db->prepare(
            'UPDATE DonationPayments SET Status = :status, UpdatedAt = :updated_at, VerifiedAt = :verified_at, ResponseData = :response_data WHERE PaymentID = :payment_id'
        );
        $update->execute([
            ':status' => $newStatus,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':verified_at' => gmdate('Y-m-d H:i:s'),
            ':response_data' => json_encode($itn),
            ':payment_id' => (int)$payment['PaymentID'],
        ]);

        if ($newStatus === 'successful' && (int)$payment['DonationID'] > 0) {
            $this->db->prepare(
                'UPDATE Donations SET Amount = :amount, DonationType = :donation_type, DonationDate = :donation_date WHERE DonationID = :donation_id'
            )->execute([
                ':amount' => number_format($amount, 2, '.', ''),
                ':donation_type' => 'cash',
                ':donation_date' => gmdate('Y-m-d'),
                ':donation_id' => (int)$payment['DonationID'],
            ]);
        }

        return ['status' => $newStatus, 'message' => 'Payment processed.'];
    }
}
