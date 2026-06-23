<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const UNSPLASH_ACCESS_KEY = 'h1dT5onYB7nIp6dw6HPZJHckjdeuX2H21S4j5T8EyvI';
const UNSPLASH_APP_NAME = 'web_palm_beaches_style_editor';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_GET['action'] ?? 'search';

if ($action === 'search') {
    $query = trim((string) ($_GET['query'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(12, max(1, (int) ($_GET['per_page'] ?? 8)));

    if ($query === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing query']);
        exit;
    }

    $endpoint = 'https://api.unsplash.com/search/photos?query=' . rawurlencode($query)
        . '&page=' . $page
        . '&per_page=' . $perPage
        . '&orientation=landscape';

    $response = unsplash_request($endpoint);
    if ($response['status'] >= 400) {
        http_response_code($response['status']);
        echo $response['body'];
        exit;
    }

    $payload = json_decode($response['body'], true);
    $results = [];

    foreach (($payload['results'] ?? []) as $photo) {
        $results[] = [
            'id' => $photo['id'] ?? '',
            'title' => $photo['description'] ?: ($photo['alt_description'] ?? 'Unsplash photo'),
            'thumb' => $photo['urls']['small'] ?? '',
            'imageUrl' => $photo['urls']['regular'] ?? ($photo['urls']['full'] ?? ''),
            'provider' => 'Unsplash',
            'creator' => $photo['user']['name'] ?? 'Unsplash photographer',
            'sourceUrl' => unsplash_referral_url($photo['links']['html'] ?? 'https://unsplash.com'),
            'photographerUrl' => unsplash_referral_url($photo['user']['links']['html'] ?? 'https://unsplash.com'),
            'downloadLocation' => $photo['links']['download_location'] ?? '',
            'license' => 'Unsplash License',
        ];
    }

    echo json_encode([
        'source' => 'unsplash',
        'results' => $results,
    ]);
    exit;
}

if ($action === 'download') {
    $downloadLocation = trim((string) ($_GET['download_location'] ?? ''));
    if ($downloadLocation === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing download_location']);
        exit;
    }

    $response = unsplash_request($downloadLocation);
    http_response_code($response['status'] >= 400 ? $response['status'] : 200);
    echo $response['body'] !== '' ? $response['body'] : json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unsupported action']);

function unsplash_request(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept-Version: v1',
            'Authorization: Client-ID ' . UNSPLASH_ACCESS_KEY,
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'WebPalmBeachesStyleEditor/1.0',
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [
            'status' => 502,
            'body' => json_encode(['error' => $error !== '' ? $error : 'Unsplash request failed']),
        ];
    }

    return [
        'status' => $status > 0 ? $status : 200,
        'body' => $body,
    ];
}

function unsplash_referral_url(string $url): string
{
    if ($url === '') {
        return $url;
    }

    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'utm_source=' . rawurlencode(UNSPLASH_APP_NAME) . '&utm_medium=referral';
}
