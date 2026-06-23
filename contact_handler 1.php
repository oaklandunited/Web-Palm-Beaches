<?php
// Prevent warnings from breaking the fetch response
error_reporting(0);

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

    // 2. Modern, PHP 8+ Safe Data Collection
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

    $my_email = "hello@webpalmbeaches.com";
    $subject = "New AI Strategy Session Request: $company";

    // 3. Construct the Email
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
            <tr><td><strong>Industry</strong></td><td>$industry</td></tr>
            <tr><td><strong>Services</strong></td><td>$services</td></tr>
            <tr><td><strong>Timeline</strong></td><td>$timeline</td></tr>
        </table>
        <h3>Biggest Challenge:</h3>
        <p style='background:#f9f9f9; padding:15px;'>$challenge</p>
    </body>
    </html>";

    $headers  = "From: no-reply@webpalmbeaches.com\r\n";
    $headers .= "Reply-To: $email\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    // 4. Attempt to send the email
    $mailSent = @mail($my_email, $subject, $message, $headers);

    if ($mailSent) {
        http_response_code(200);
        echo "Success";
    } else {
        // If mail() is blocked by the server, it will trigger this
        http_response_code(403);
        echo "Mail function blocked by host.";
    }
} else {
    http_response_code(405);
    echo "Method Not Allowed";
}
?>