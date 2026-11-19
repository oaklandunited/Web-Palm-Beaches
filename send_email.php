<?php
error_reporting(0);
require_once __DIR__ . '/config_loader.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Honeypot Check (Spam Prevention)
    if (!empty($_POST['website_hp'])) {
        exit;
    }

    // 2. reCAPTCHA v3 verification
    $recaptcha_secret = (string) wpb_config('RECAPTCHA_SECRET_KEY', '');
    $recaptcha_token  = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
    if ($recaptcha_secret !== '' && !empty($recaptcha_token)) {
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
            echo "reCAPTCHA verification failed.";
            exit;
        }
    }

    $email = filter_var($_POST['visitor_email'] ?? '', FILTER_SANITIZE_EMAIL);

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $my_email      = (string) wpb_config('LEAD_TO_EMAIL', 'hello@webpalmbeaches.com');
        $from_email    = (string) wpb_config('LEAD_FROM_EMAIL', 'Web Palm Beaches <no-reply@webpalmbeaches.com>');
        $resend_key    = (string) wpb_config('RESEND_API_KEY', '');
        $business_name = "Web Palm Beaches";
        $safe_email    = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        // Prefer Resend API when configured; fall back to PHP mail()
        if ($resend_key !== '' && function_exists('curl_init')) {
            $payload = [
                'from'     => $from_email,
                'to'       => [$my_email],
                'reply_to' => [$email],
                'subject'  => "New AI Strategy Lead: " . $email,
                'html'     => "<p>You have a new request for an AI Strategy Session from: <strong>{$safe_email}</strong></p>",
            ];
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $resend_key,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200 || $http_code === 201) {
                header("Location: thank-you.html");
                exit;
            }
        }

        // Fallback: PHP mail()
        $subject_me = "New AI Strategy Lead: " . $email;
        $headers_me = "From: webmaster@webpalmbeaches.com\r\n" . "Reply-To: " . $email;
        $message_me = "You have a new request for an AI Strategy Session from: " . $email;
        @mail($my_email, $subject_me, $message_me, $headers_me);

        $subject_client = "Confirmation: Your AI Strategy Session with " . $business_name;
        $headers_client = "From: " . $my_email . "\r\n";
        $headers_client .= "Reply-To: " . $my_email . "\r\n";
        $headers_client .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message_client = "Hi there,\n\n" .
                          "Thank you for requesting an AI Strategy Session with Web Palm Beaches! We've received your request.\n\n" .
                          "One of our experts will reach out to you within 24 hours to schedule a time that works best for you.\n\n" .
                          "In the meantime, feel free to try our AI Project Brief tool here: https://webpalmbeaches.com/project-brief\n\n" .
                          "Best regards,\n" .
                          "The Web Palm Beaches Team\n" .
                          "561-291-9001";

        if (@mail($email, $subject_client, $message_client, $headers_client)) {
            header("Location: thank-you.html");
            exit;
        }
    }
    echo "Something went wrong. Please call 561-291-9001.";
}
?>