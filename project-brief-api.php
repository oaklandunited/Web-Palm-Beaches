<?php
/**
 * Web Palm Beaches — AI Project Brief Streaming Endpoint (SSE)
 *
 * Implements PRD Section 7 & 8:
 *  - Pre-generation email capture with explicit consent (no prospect input/brief stored on disk)
 *  - Validation: businessName optional (<= 160 chars), goals required (20-6,000 chars)
 *  - IP-based rate limiting
 *  - Direct server-side LLM streaming (OpenAI, Anthropic, or Gemini) via Server-Sent Events
 *  - Grounded strictly in Web Palm Beaches' real service catalog
 */

declare(strict_types=1);

// Prevent PHP notices/warnings from corrupting JSON or SSE output
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/config_loader.php';

// Helper: send a JSON error response before SSE starts
function wpb_json_error(int $status, string $code, string $message, array $extraHeaders = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $h) {
        header($h);
    }
    echo json_encode([
        'error' => true,
        'code' => $code,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: send a single SSE event and flush immediately
function wpb_sse_event(string $event, array $payload): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

// Helper: get client IP safely for rate limiting
function wpb_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];
    foreach ($candidates as $raw) {
        if ($raw === '') {
            continue;
        }
        $first = trim(explode(',', $raw)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return '127.0.0.1';
}

// Helper: simple file-based IP rate limiter in system temp dir (stores only hashed IP + timestamps)
function wpb_check_rate_limit(int $maxRequests, int $windowSeconds): array {
    $ipHash = hash('sha256', wpb_client_ip() . '|wpb-brief-v1');
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wpb_brief_rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . $ipHash . '.json';
    $now = time();
    $timestamps = [];

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        // Fail open if temp dir is unwritable
        return ['allowed' => true, 'retryAfter' => 0];
    }

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $timestamps = array_values(array_filter($decoded, static fn($ts) => is_int($ts) && ($now - $ts) < $windowSeconds));
            }
        }

        if (count($timestamps) >= $maxRequests) {
            $oldest = min($timestamps);
            $retryAfter = max(60, $windowSeconds - ($now - $oldest));
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['allowed' => false, 'retryAfter' => $retryAfter];
        }

        $timestamps[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($timestamps));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    return ['allowed' => true, 'retryAfter' => 0];
}

// Helper: send non-blocking lead email via Resend (only email + optional business name — never stores brief)
function wpb_notify_lead(string $email, string $businessName): void {
    $resendKey = (string) wpb_config('RESEND_API_KEY', '');
    if ($resendKey === '' || !function_exists('curl_init')) {
        return;
    }

    $toEmail = (string) wpb_config('LEAD_TO_EMAIL', 'hello@webpalmbeaches.com');
    $fromEmail = (string) wpb_config('LEAD_FROM_EMAIL', 'Web Palm Beaches <no-reply@webpalmbeaches.com>');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeBiz = $businessName !== '' ? htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') : 'Not provided';
    $timestamp = gmdate('Y-m-d H:i:s') . ' UTC';

    $html = "<html><body style='font-family:sans-serif;color:#2c2c2c;'>"
          . "<h2 style='color:#295082;'>New AI Project Brief Lead</h2>"
          . "<p>A prospect started an AI Project Brief on webpalmbeaches.com and consented to follow-up:</p>"
          . "<table style='border-collapse:collapse;width:100%;max-width:520px;'>"
          . "<tr><td style='padding:8px;border-bottom:1px solid #eee;'><strong>Email</strong></td><td style='padding:8px;border-bottom:1px solid #eee;'>{$safeEmail}</td></tr>"
          . "<tr><td style='padding:8px;border-bottom:1px solid #eee;'><strong>Business Name</strong></td><td style='padding:8px;border-bottom:1px solid #eee;'>{$safeBiz}</td></tr>"
          . "<tr><td style='padding:8px;border-bottom:1px solid #eee;'><strong>Timestamp</strong></td><td style='padding:8px;border-bottom:1px solid #eee;'>{$timestamp}</td></tr>"
          . "</table>"
          . "<p style='font-size:12px;color:#666;margin-top:16px;'>Per Web Palm Beaches v1 privacy policy, prospect project goals and generated brief contents are not stored.</p>"
          . "</body></html>";

    $payload = [
        'from' => $fromEmail,
        'to' => [$toEmail],
        'reply_to' => [$email],
        'subject' => 'New AI Project Brief Lead' . ($businessName !== '' ? ": {$businessName}" : ''),
        'html' => $html,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $resendKey,
            'Content-Type: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

// 1. Method check
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wpb_json_error(405, 'method_not_allowed', 'Please submit your project brief using the form.');
}

// 2. Parse JSON request body
$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    wpb_json_error(400, 'invalid_request', 'Missing request payload.');
}

$input = json_decode($rawBody, true);
if (!is_array($input)) {
    wpb_json_error(400, 'invalid_json', 'Request body must be valid JSON.');
}

// 3. Honeypot check
if (!empty($input['website_hp'])) {
    wpb_json_error(400, 'invalid_request', 'Unable to process request.');
}

// 4. Validate email & consent (capture email before brief generation)
$email = trim((string) ($input['email'] ?? ''));
if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    wpb_json_error(400, 'invalid_email', 'Please enter a valid work or personal email address before generating your brief.');
}

$consent = !empty($input['consent']);
if (!$consent) {
    wpb_json_error(400, 'consent_required', 'Please confirm the privacy notice checkbox so we can generate your brief and follow up if helpful.');
}

// 5. Validate businessName (B1: optional, up to 160 chars)
$businessName = trim((string) ($input['businessName'] ?? ''));
$bizLen = function_exists('mb_strlen') ? mb_strlen($businessName, 'UTF-8') : strlen($businessName);
if ($bizLen > 160) {
    wpb_json_error(400, 'business_name_too_long', 'Business name must be 160 characters or fewer.');
}

// 6. Validate goals (B2: required, 20 to 6,000 chars)
$goals = trim((string) ($input['goals'] ?? ''));
$goalsLen = function_exists('mb_strlen') ? mb_strlen($goals, 'UTF-8') : strlen($goals);
if ($goalsLen < 20) {
    wpb_json_error(400, 'goals_too_short', 'Please describe your project goals in at least 20 characters so we can tailor your brief.');
}
if ($goalsLen > 6000) {
    wpb_json_error(400, 'goals_too_long', 'Project goals must be 6,000 characters or fewer. Please shorten your description and try again.');
}

// 7. Rate limiting (Section 8)
$rlMax = (int) wpb_config('BRIEF_RATE_LIMIT_MAX', 6);
$rlWindow = (int) wpb_config('BRIEF_RATE_LIMIT_WINDOW', 3600);
$rl = wpb_check_rate_limit(max(1, $rlMax), max(60, $rlWindow));
if (!$rl['allowed']) {
    $minutes = (int) ceil($rl['retryAfter'] / 60);
    wpb_json_error(
        429,
        'rate_limited',
        "You've reached the temporary limit for generating project briefs. Please wait about {$minutes} minute(s) and try again, or call us directly at 561-291-9001.",
        ['Retry-After: ' . $rl['retryAfter']]
    );
}

// 8. Check LLM configuration before opening stream
$provider = strtolower(trim((string) wpb_config('LLM_PROVIDER', 'openai')));
$apiKey = trim((string) wpb_config('LLM_API_KEY', ''));
if ($apiKey === '') {
    wpb_json_error(
        503,
        'service_unavailable',
        'Our AI Project Brief generator is temporarily unavailable while being configured. Please book a free 30-minute strategy call or call 561-291-9001 and we will scope your project live.'
    );
}

if (!function_exists('curl_init')) {
    wpb_json_error(
        503,
        'service_unavailable',
        'Our AI Project Brief service is temporarily unavailable. Please call 561-291-9001 or use our contact page to book a free strategy call.'
    );
}

// 9. Send non-blocking lead notification (email + optional business name only)
wpb_notify_lead($email, $businessName);

// 10. Grounded system prompt (strictly server-side; draws ONLY on Web Palm Beaches' real services)
$systemPrompt = <<<'PROMPT'
You are a senior solutions consultant at Web Palm Beaches, a South Florida AI consulting, workflow automation, and web agency based in Palm Beach County (serving West Palm Beach, Boca Raton, Delray Beach, Jupiter, and surrounding areas; phone: 561-291-9001; website: webpalmbeaches.com).

Your job is to turn a prospect's stated goals into a clear, practical, executive-ready Project Brief in clean Markdown.

STRICT SERVICE CATALOG RULE (NEVER invent services outside this list):
Recommend ONLY from Web Palm Beaches' real services:
1. AI Agent Development — Custom chat agents, analytics agents, scheduling agents, and internal knowledge agents (including HIPAA-aware and compliance-ready setups).
2. Workflow Automation — Intelligent automation pipelines (Make, Zapier, custom APIs) for lead follow-up, CRM sync, appointment booking, review requests, invoicing, and reporting.
3. AI Strategy Consulting — Operational audits, high-ROI roadmapping, tool selection, and AI governance workshops.
4. AI Team Training — Hands-on Done-With-You workshops (no coding required): AI Quick Start (half-day, $1,500), AI Team Immersion (full-day, $3,500), System Architect Intensive (4-week, $8,500).
5. AI-Powered Websites — Custom & WordPress websites built to convert (Starter $3K–$5K, Professional $5K–$12K, Enterprise $12K–$25K+).
6. SEO, AEO & GEO Strategy — Local SEO, technical schema markup, Google Business Profile optimization, Answer Engine Optimization (AEO), and Generative Engine Optimization (GEO) for ChatGPT, Perplexity, and Google AI Overviews.
7. System Integration — Connecting CRMs, email platforms, payment processors (Stripe/Square/PayPal), social channels, and cloud infrastructure (AWS/GCP/Azure) into one cohesive stack.

REQUIRED STRUCTURE (use these exact H2 markdown headings in order):
## 1. Executive Summary
A concise 2–4 sentence synthesis of the prospect's situation, core opportunity, and expected business impact.

## 2. Key Objectives
3–5 concrete, measurable objectives derived directly from their goals.

## 3. Recommended Scope
Clear bullet points covering deliverables, key integrations, and any compliance/operational considerations (e.g., HIPAA, Florida Bar, CRM handoff, or mobile performance if relevant).

## 4. Recommended Web Palm Beaches Services
List 2–4 matching services ONLY from the catalog above. For each service, bold the exact service name and explain in 1–2 sentences *why* it fits their specific goals. Where helpful, mention rough planning ranges from the catalog (clearly noting they are estimates, not formal quotes).

## 5. Phased Roadmap
A realistic 3-phase rollout (Phase 1: Discovery & Quick Wins, Phase 2: Core Build & Integration, Phase 3: Launch, Training & Optimization) with approximate week ranges.

## 6. Discovery Questions
4–6 sharp, practical questions Web Palm Beaches will walk through with the prospect on a strategy call to finalize scope and fixed pricing.

## 7. Next Step
A warm, direct invitation to book a free 30-minute Strategy Call with Web Palm Beaches (or call 561-291-9001) to review this brief and lock in a fixed-price scope.

TONE & FORMATTING RULES:
- Plain English, zero hype, practical and tailored to the prospect's exact details.
- Never claim this is a binding quote or guarantee exact ROI numbers.
- Keep total length around 550–850 words so it is thorough yet easy to scan in 2 minutes.
PROMPT;

$userMessage = ($businessName !== '' ? "Business Name: {$businessName}\n\n" : '')
             . "Project Goals & Context:\n{$goals}";

// 11. Begin SSE streaming response
@set_time_limit(120);
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}

http_response_code(200);
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Flush an initial comment so proxies/browsers open the stream immediately
echo ": stream-open\n\n";
@flush();

// 12. Prepare provider request
$modelOverride = trim((string) wpb_config('LLM_MODEL', ''));
$url = '';
$headers = ['Content-Type: application/json'];
$body = [];

if ($provider === 'anthropic') {
    $model = $modelOverride !== '' ? $modelOverride : 'claude-3-5-haiku-latest';
    $url = 'https://api.anthropic.com/v1/messages';
    $headers[] = 'x-api-key: ' . $apiKey;
    $headers[] = 'anthropic-version: 2023-06-01';
    $body = [
        'model' => $model,
        'max_tokens' => 1800,
        'temperature' => 0.5,
        'stream' => true,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $userMessage],
        ],
    ];
} elseif ($provider === 'gemini') {
    $model = $modelOverride !== '' ? $modelOverride : 'gemini-2.5-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':streamGenerateContent?alt=sse&key=' . rawurlencode($apiKey);
    $body = [
        'system_instruction' => [
            'parts' => [['text' => $systemPrompt]],
        ],
        'contents' => [
            [
                'role' => 'user',
                'parts' => [['text' => $userMessage]],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.5,
            'maxOutputTokens' => 1800,
        ],
    ];
} else {
    // Default: OpenAI (or OpenAI-compatible endpoint)
    $provider = 'openai';
    $model = $modelOverride !== '' ? $modelOverride : 'gpt-4o-mini';
    $url = (string) wpb_config('LLM_API_URL', 'https://api.openai.com/v1/chat/completions');
    $headers[] = 'Authorization: Bearer ' . $apiKey;
    $body = [
        'model' => $model,
        'temperature' => 0.5,
        'max_tokens' => 1800,
        'stream' => true,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ],
    ];
}

$sseBuffer = '';
$rawErrorBuffer = '';
$emittedAnyText = false;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 110,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use ($provider, &$sseBuffer, &$rawErrorBuffer, &$emittedAnyText): int {
        if (connection_aborted()) {
            return 0; // Abort upstream request immediately when user clicks Stop
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            if (strlen($rawErrorBuffer) < 4096) {
                $rawErrorBuffer .= $chunk;
            }
            return strlen($chunk);
        }

        $sseBuffer .= str_replace("\r\n", "\n", $chunk);
        while (($newlinePos = strpos($sseBuffer, "\n")) !== false) {
            $line = substr($sseBuffer, 0, $newlinePos);
            $sseBuffer = substr($sseBuffer, $newlinePos + 1);
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, 'data:') !== 0) {
                continue;
            }

            $dataStr = trim(substr($trimmed, 5));
            if ($dataStr === '' || $dataStr === '[DONE]') {
                continue;
            }

            $parsed = json_decode($dataStr, true);
            if (!is_array($parsed)) {
                continue;
            }

            $deltaText = '';
            if ($provider === 'openai') {
                $deltaText = (string) ($parsed['choices'][0]['delta']['content'] ?? '');
            } elseif ($provider === 'anthropic') {
                if (($parsed['type'] ?? '') === 'content_block_delta') {
                    $deltaText = (string) ($parsed['delta']['text'] ?? '');
                }
            } elseif ($provider === 'gemini') {
                $parts = $parsed['candidates'][0]['content']['parts'] ?? [];
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        if (isset($part['text']) && is_string($part['text'])) {
                            $deltaText .= $part['text'];
                        }
                    }
                }
            }

            if ($deltaText !== '') {
                $emittedAnyText = true;
                wpb_sse_event('delta', ['text' => $deltaText]);
            }
        }

        return strlen($chunk);
    },
]);

$execOk = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (connection_aborted()) {
    exit;
}

if (!$execOk || $httpCode >= 400 || !$emittedAnyText) {
    if ($httpCode === 429) {
        wpb_sse_event('error', [
            'code' => 'provider_rate_limit',
            'message' => 'Our AI brief generator is experiencing high demand right now. Please wait a moment and try again, or call 561-291-9001.',
        ]);
    } elseif ($httpCode === 503 || $httpCode === 502 || $httpCode === 504 || $curlErrNo === CURLE_OPERATION_TIMEDOUT) {
        wpb_sse_event('error', [
            'code' => 'service_busy',
            'message' => 'The AI service is temporarily busy. Please try again in a few seconds, or book a free strategy call and we will walk through your project live.',
        ]);
    } else {
        wpb_sse_event('error', [
            'code' => 'generation_failed',
            'message' => 'We hit a snag generating your project brief. Your goals are still saved in the form—please try again or call 561-291-9001.',
        ]);
    }
    exit;
}

wpb_sse_event('done', ['ok' => true]);
exit;
