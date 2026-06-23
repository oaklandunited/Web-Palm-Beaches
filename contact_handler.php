<?php
// Prevent warnings from breaking the fetch response
error_reporting(0);

// Resend.com API Key Configuration
// IMPORTANT: Replace this placeholder with your actual Resend API Key (starts with re_)
$resend_api_key = 're_J3dt71cn_JvF7oKg6Qv9dHY9xUkash9zj';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Honeypot check for bots
    if (!empty($_POST['website_hp'])) {
        http_response_code(200);
        echo "Bot detected and ignored.";
        exit;
    }

    // 2. reCAPTCHA v3 verification
    $recaptcha_secret = '6LfDwJ8sAAAAAJ6Je6so_ZY0TvCJPK5PSyObq2MY';
    $recaptcha_token  = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
    if (empty($recaptcha_token)) {
        http_response_code(400);
        echo "reCAPTCHA token missing.";
        exit;
    }
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret'   => $recaptcha_secret,
        'response' => $recaptcha_token
    ]));
    $verify      = curl_exec($ch);
    curl_close($ch);
    $verify_data = json_decode($verify, true);
    if (!$verify_data['success'] || $verify_data['score'] < 0.5) {
        http_response_code(400);
        echo "reCAPTCHA verification failed.";
        exit;
    }

    // 3. Modern, PHP 8+ Safe Data Collection
    $firstName = isset($_POST['firstName']) ? htmlspecialchars(strip_tags($_POST['firstName'])) : 'Not provided';
    $lastName  = isset($_POST['lastName']) ? htmlspecialchars(strip_tags($_POST['lastName'])) : 'Not provided';
    $email     = isset($_POST['email']) ? filter_var($_POST['email'], FILTER_SANITIZE_EMAIL) : 'No email';
    $phone     = isset($_POST['phone']) ? htmlspecialchars(strip_tags($_POST['phone'])) : 'Not provided';
    $company   = isset($_POST['company']) ? htmlspecialchars(strip_tags($_POST['company'])) : 'Not provided';
    $challenge = isset($_POST['challenge']) ? htmlspecialchars(strip_tags($_POST['challenge'])) : 'Not provided';
    
    // Arrays and optional fields
    $services  = isset($_POST['services']) && is_array($_POST['services']) ? implode(", ", $_POST['services']) : "None selected";
    $industry  = isset($_POST['industry']) ? htmlspecialchars(strip_tags($_POST['industry'])) : "Not specified";
    $timeline  = isset($_POST['timeline']) ? htmlspecialchars(strip_tags($_POST['timeline'])) : "Not specified";

    // Newly Parsed Fields
    $website    = isset($_POST['website']) ? filter_var($_POST['website'], FILTER_SANITIZE_URL) : 'Not provided';
    $referral   = isset($_POST['referral']) ? htmlspecialchars(strip_tags($_POST['referral'])) : 'Not specified';
    $additional = isset($_POST['additional']) ? htmlspecialchars(strip_tags($_POST['additional'])) : 'None';

    // Validate email format
    if ($email !== 'No email' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo "Invalid email address format.";
        exit;
    }

    $my_email = "hello@webpalmbeaches.com";
    $subject = "New AI Strategy Session Request: $company";

    // 4. Construct the HTML Email
    $message = "
    <html>
    <head><style>td{padding:8px; border-bottom:1px solid #eee;}</style></head>
    <body style='font-family: sans-serif; color: #333;'>
        <h2>New Lead from Web Palm Beaches</h2>
        <table style='width: 100%; border-collapse: collapse;'>
            <tr><td><strong>Name</strong></td><td>$firstName $lastName</td></tr>
            <tr><td><strong>Email</strong></td><td>$email</td></tr>
            <tr><td><strong>Phone</strong></td><td>$phone</td></tr>
            <tr><td><strong>Company</strong></td><td>$company</td></tr>
            <tr><td><strong>Website</strong></td><td>$website</td></tr>
            <tr><td><strong>Industry</strong></td><td>$industry</td></tr>
            <tr><td><strong>Services</strong></td><td>$services</td></tr>
            <tr><td><strong>Timeline</strong></td><td>$timeline</td></tr>
            <tr><td><strong>Referral Source</strong></td><td>$referral</td></tr>
        </table>
        <h3>Biggest Challenge:</h3>
        <p style='background:#f9f9f9; padding:15px;'>$challenge</p>
        <h3>Additional Information / Comments:</h3>
        <p style='background:#f9f9f9; padding:15px;'>$additional</p>
    </body>
    </html>";

    // 5. Send Email via Resend.com API
    $api_url = 'https://api.resend.com/emails';
    
    // Note: The 'from' email must belong to a verified domain in your Resend account.
    $payload = [
        'from'     => 'Web Palm Beaches <no-reply@webpalmbeaches.com>',
        'to'       => [$my_email],
        'reply_to' => [$email],
        'subject'  => $subject,
        'html'     => $message
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $resend_api_key,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code === 200 || $http_code === 201) {
        http_response_code(200);
        echo "Success";
    } else {
        http_response_code($http_code ?: 500);
        if ($curl_error) {
            echo "CURL Connection Error: " . htmlspecialchars($curl_error);
        } else {
            $res_data = json_decode($response, true);
            $error_msg = isset($res_data['message']) ? $res_data['message'] : 'Unknown Resend API error';
            echo "Resend API Error: " . htmlspecialchars($error_msg);
        }
    }
} else {
    http_response_code(405);
    echo "Method Not Allowed";
}
?>