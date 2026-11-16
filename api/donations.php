<?php
function handleDonationCheckout() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    $input = getJsonInput();
    validateRequired($input, ['donor_name', 'amount', 'donation_date']);

    $amount = (float)($input['amount'] ?? 0);
    if ($amount <= 0) {
        apiJsonResponse(false, 'Donation amount must be greater than zero.', null, 400);
    }

    $donationType = strtolower((string)($input['donation_type'] ?? 'cash'));
    if ($donationType !== 'cash') {
        apiJsonResponse(false, 'Only monetary cash donations are accepted via the active PayFast checkout.', null, 400);
    }

    $db = getDBConnection();
    $donationModel = new Donation($db);
    $donationResult = $donationModel->createDonation([
        'DonorName' => trim((string)($input['donor_name'] ?? '')),
        'DonorEmail' => trim((string)($input['donor_email'] ?? '')),
        'DonationType' => 'cash',
        'Amount' => $amount,
        'Description' => trim((string)($input['description'] ?? '')),
        'DonationDate' => trim((string)($input['donation_date'] ?? '')),
    ]);

    if (!$donationResult['success'] || empty($donationResult['id'])) {
        apiJsonResponse(false, $donationResult['message'] ?? 'Could not create the donation record.', null, 500);
    }

    $reference = 'FSMS-' . strtoupper(bin2hex(random_bytes(6)));
    $paymentService = new PayFastService($db);
    $pending = $paymentService->createPendingDonation([
        'donation_id' => (int)$donationResult['id'],
        'merchant_reference' => $reference,
        'reference' => $reference,
        'amount' => $amount,
        'currency' => strtoupper((string)($input['currency'] ?? 'ZAR')),
    ]);

    $returnUrl = getenv('PAYFAST_RETURN_URL') ?: 'https://localhost/fsms/public/index.php?action=donations_success';
    $cancelUrl = getenv('PAYFAST_CANCEL_URL') ?: 'https://localhost/fsms/public/index.php?action=donations_cancel';
    $notifyUrl = getenv('PAYFAST_NOTIFY_URL') ?: 'https://localhost/fsms/public/index.php?action=payfast_itn';

    $checkoutUrl = PayFastService::hostedCheckoutUrl($returnUrl, $cancelUrl, $notifyUrl, $pending['reference'], $pending['amount'], $pending['currency']);

    apiJsonResponse(true, 'PayFast hosted checkout created.', [
        'donation_id' => (int)$donationResult['id'],
        'payment_reference' => $pending['reference'],
        'checkout_url' => $checkoutUrl,
        'status' => 'pending',
    ]);
}

function handleDonationPayfastItn() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit();
    }

    $db = getDBConnection();
    $raw = file_get_contents('php://input');
    $itn = [];
    if (is_string($raw) && trim($raw) !== '') {
        parse_str($raw, $itn);
    }
    if (empty($itn)) {
        $itn = $_POST;
    }
    if (empty($itn)) {
        http_response_code(400);
        echo 'No PayFast payload received';
        exit();
    }

    $service = new PayFastService($db);
    $result = $service->processItn($itn);

    http_response_code(200);
    if ($result['status'] === 'successful') {
        echo 'OK';
    } else {
        echo 'INVALID';
    }
    exit();
}

function handleDonations($method, $id) {
    // Donation submissions may be made by an authenticated operator or anonymously.
    // Reading and editing the donation register remains protected.
    $user = $method === 'POST' ? getAuthenticatedApiUser() : requireAuth();
    $db = getDBConnection();
    $model = new Donation($db);

    switch ($method) {
        case 'GET':
            if (strtolower($user['Role']) === 'donor') {
                if ($id) {
                    $data = $model->getDonationById((int)$id);
                    if (!$data || strcasecmp((string)($data['DonorEmail'] ?? ''), (string)$user['Email']) !== 0) {
                        apiJsonResponse(false, 'Donation not found', null, 404);
                    }
                    apiJsonResponse(true, 'Donation retrieved', $data);
                }
                apiJsonResponse(true, 'Your donations retrieved', $model->getDonationsByEmail($user['Email']));
            }
            requireApiRole($user, ['admin', 'staff', 'volunteer']);
            if ($id) {
                $data = $model->getDonationById((int)$id);
                if (!$data) {
                    apiJsonResponse(false, 'Donation not found', null, 404);
                }
                apiJsonResponse(true, 'Donation retrieved', $data);
            } else {
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
                $type = $_GET['type'] ?? null;
                $search = $_GET['search'] ?? null;

                if ($search) {
                    $data = $model->searchDonations($search);
                    apiJsonResponse(true, 'Donation search results', $data);
                } elseif ($type) {
                    $data = $model->getDonationsByType($type);
                    apiJsonResponse(true, 'Donations by type retrieved', $data);
                } elseif (isset($_GET['start_date']) && isset($_GET['end_date'])) {
                    $data = $model->getDonationsByDateRange($_GET['start_date'], $_GET['end_date']);
                    apiJsonResponse(true, 'Donations by date range retrieved', $data);
                } else {
                    $result = $model->getAllDonations($page, $limit);
                    $summary = $model->getDonationSummary();
                    apiJsonResponse(true, 'Donations retrieved', [
                        'donations' => $result['data'],
                        'pagination' => $result['pagination'],
                        'summary' => $summary
                    ]);
                }
            }
            break;

        case 'POST':
            $input = getJsonInput();
            if ($user && strtolower($user['Role']) === 'donor') {
                $input['donor_email'] = $user['Email'];
                $input['donor_name'] = $user['Username'];
            }
            validateRequired($input, ['donor_name', 'donation_type', 'donation_date']);

            $result = $model->createDonation([
                'DonorName' => $input['donor_name'],
                'DonorEmail' => $input['donor_email'] ?? '',
                'DonationType' => $input['donation_type'],
                'Amount' => $input['amount'] ?? 0,
                'Description' => $input['description'] ?? '',
                'DonationDate' => $input['donation_date']
            ]);
            if ($result['success']) {
                $data = $model->getDonationById((int)$result['id']);
                apiJsonResponse(true, $result['message'], $data, 201);
            }
            apiJsonResponse(false, $result['message'], null, 500);
            break;

        case 'PUT':
            requireApiRole($user, ['admin', 'staff', 'volunteer']);
            if (!$id) {
                apiJsonResponse(false, 'Donation ID is required', null, 400);
            }
            $input = getJsonInput();
            validateRequired($input, ['donor_name', 'donation_type', 'donation_date']);
            $result = $model->updateDonation((int)$id, [
                'DonorName' => $input['donor_name'],
                'DonorEmail' => $input['donor_email'] ?? '',
                'DonationType' => $input['donation_type'],
                'Amount' => $input['amount'] ?? 0,
                'Description' => $input['description'] ?? '',
                'DonationDate' => $input['donation_date']
            ]);
            if ($result['success']) {
                apiJsonResponse(true, $result['message']);
            }
            apiJsonResponse(false, $result['message'], null, 500);
            break;

        case 'DELETE':
            requireApiRole($user, ['admin']);
            if (!$id) {
                apiJsonResponse(false, 'Donation ID is required', null, 400);
            }
            $result = $model->deleteDonation((int)$id);
            if ($result['success']) {
                apiJsonResponse(true, $result['message']);
            }
            apiJsonResponse(false, $result['message'], null, 500);
            break;

        default:
            apiJsonResponse(false, 'Method not allowed', null, 405);
    }
}
