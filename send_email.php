<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Honeypot Check (Spam Prevention)
    if (!empty($_POST['website_hp'])) {
        exit;
    }

    // 2. reCAPTCHA v3 verification
    $recaptcha_secret = '6LfDwJ8sAAAAAJ6Je6so_ZY0TvCJPK5PSyObq2MY';
    $recaptcha_token  = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
    if (!empty($recaptcha_token)) {
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

    $email = filter_var($_POST['visitor_email'], FILTER_SANITIZE_EMAIL);

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        
        // --- SETTINGS ---
        $my_email = "hello@webpalmbeaches.com"; 
        $business_name = "Web Palm Beaches";

        // 2. NOTIFICATION TO YOU
        $to_me = $my_email;
        $subject_me = "New AI Strategy Lead: " . $email;
        $headers_me = "From: webmaster@webpalmbeaches.com\r\n" . "Reply-To: " . $email;
        $message_me = "You have a new request for an AI Strategy Session from: " . $email;
        mail($to_me, $subject_me, $message_me, $headers_me);

        // 3. AUTO-RESPONDER TO THE CLIENT
        $to_client = $email;
        $subject_client = "Confirmation: Your AI Strategy Session with " . $business_name;
        
        $headers_client = "From: " . $my_email . "\r\n";
        $headers_client .= "Reply-To: " . $my_email . "\r\n";
        $headers_client .= "Content-Type: text/plain; charset=UTF-8\r\n";

        $message_client = "Hi there,\n\n" .
                          "Thank you for requesting an AI Strategy Session with Web Palm Beaches! We've received your request.\n\n" .
                          "One of our experts will reach out to you within 24 hours to schedule a time that works best for you.\n\n" .
                          "In the meantime, feel free to check out our process here: https://webpalmbeaches.com/process.html\n\n" .
                          "Best regards,\n" .
                          "The Web Palm Beaches Team\n" .
                          "561-291-9001";

        if (mail($to_client, $subject_client, $message_client, $headers_client)) {
            // SUCCESS: Redirect to your thank you page
            header("Location: thank-you.html");
            exit;
        }
    }
    echo "Something went wrong. Please call 561-291-9001.";
}
?>