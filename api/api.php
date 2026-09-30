<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function catalogue(): array {
    $file = __DIR__ . '/db.json';
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    $data = $raw !== false ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $_POST;
}

function money(mixed $value): int {
    preg_match('/[0-9][0-9,]*/', (string) $value, $match);
    return isset($match[0]) ? (int) str_replace(',', '', $match[0]) : 0;
}

function clean(string $value): string {
    return trim($value);
}

function matches(string $haystack, string $needle): bool {
    return $needle !== '' && str_contains(strtolower($haystack), strtolower($needle));
}

function destinationKey(string $message): string {
    $message = strtolower($message);
    foreach ([
        'dubai' => 'Dubai', 'baku' => 'Baku', 'istanbul' => 'Istanbul', 'turkey' => 'Istanbul',
        'phuket' => 'Phuket', 'thailand' => 'Bangkok', 'bangkok' => 'Bangkok', 'umrah' => 'Makkah',
        'makkah' => 'Makkah', 'madinah' => 'Madinah', 'kuala lumpur' => 'KUL', 'malaysia' => 'KUL'
    ] as $needle => $destination) {
        if (str_contains($message, $needle)) return $destination;
    }
    return '';
}

function pick(array $items, callable $test): ?array {
    foreach ($items as $item) if ($test($item)) return $item;
    return $items[0] ?? null;
}

function aiRecommendation(array $catalogue, string $message, int $budget, string $destination): ?array {
    if (!function_exists('curl_init')) return null;
    $model = trim((string) (getenv('AZMEER_AI_MODEL') ?: 'gemini-3.6-flash'));
    $catalogueJson = json_encode($catalogue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $instructions = 'You are GoGlobal AI, a luxury and smart travel concierge for Pakistan-based travellers. Analyze customer request and return valid JSON with keys: "type", "text", "flight_ids", "hotel_ids", "tour_ids", "tour_id", "flight_id", "hotel_id", "dining_id".
"type" must be one of: "flights", "hotels", "tours", "trip", "booking", "message".
- For "flights": provide array of matching flight IDs in "flight_ids" (max 6).
- For "hotels": provide array of matching hotel IDs in "hotel_ids" (max 6).
- For "tours": provide array of matching package IDs in "tour_ids" (max 6).
- For "trip": specify "tour_id", "flight_id", "hotel_id", "dining_id" to construct a complete itinerary.
- For "booking": set type="booking" when user requests to book or reserve.
- For "message": concise Q&A text.
Never invent IDs or prices. Always pick actual IDs from the catalogue.';

    $payload = [
        'contents' => [[
            'parts' => [[
                'text' => $instructions . "\nCatalogue:\n" . $catalogueJson . "\nCustomer request:\n" . json_encode(['message' => $message, 'budget' => $budget, 'destination' => $destination], JSON_UNESCAPED_UNICODE)
            ]]
        ]]
    ];

    $url = 'https://azmeer-ai.az-abdulbasit-az.workers.dev/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-app-auth-key: azmeer-ai-prompthub'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (!is_string($raw) || $status < 200 || $status >= 300) return null;
    $response = json_decode($raw, true);
    $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $content = is_string($content) ? trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content)) : '';
    $answer = $content !== '' ? json_decode($content, true) : null;
    if (!is_array($answer) || !isset($answer['type'], $answer['text'])) return null;

    $type = (string) $answer['type'];
    $text = clean((string) $answer['text']);

    if ($type === 'flights') {
        $ids = is_array($answer['flight_ids'] ?? null) ? $answer['flight_ids'] : [];
        $items = array_values(array_filter($catalogue['flights'] ?? [], fn($item) => in_array($item['id'] ?? '', $ids, true)));
        if (!empty($items)) return ['type' => 'flights', 'text' => $text, 'items' => $items];
    }

    if ($type === 'hotels') {
        $ids = is_array($answer['hotel_ids'] ?? null) ? $answer['hotel_ids'] : [];
        $items = array_values(array_filter($catalogue['hotels'] ?? [], fn($item) => in_array($item['id'] ?? '', $ids, true)));
        if (!empty($items)) return ['type' => 'hotels', 'text' => $text, 'items' => $items];
    }

    if ($type === 'tours') {
        $ids = is_array($answer['tour_ids'] ?? null) ? $answer['tour_ids'] : [];
        $items = array_values(array_filter($catalogue['tours'] ?? [], fn($item) => in_array($item['id'] ?? '', $ids, true)));
        if (!empty($items)) return ['type' => 'tours', 'text' => $text, 'items' => $items];
    }

    if ($type === 'booking') {
        return ['type' => 'booking', 'text' => $text];
    }

    if ($type === 'trip') {
        $find = static function (string $key, array $items) use ($answer): ?array {
            $id = (string) ($answer[$key] ?? '');
            foreach ($items as $item) if ((string) ($item['id'] ?? '') === $id) return $item;
            return null;
        };
        $tour = $find('tour_id', $catalogue['tours'] ?? []);
        if ($tour) {
            $flight = $find('flight_id', $catalogue['flights'] ?? []);
            $hotel = $find('hotel_id', $catalogue['hotels'] ?? []);
            $meal = $find('dining_id', $catalogue['dining'] ?? []);
            return [
                'type' => 'trip',
                'text' => $text,
                'trip' => [
                    'tour' => $tour,
                    'flight' => $flight,
                    'hotel' => $hotel,
                    'meal' => $meal,
                    'total' => (int) ($tour['price'] ?? 0),
                    'budget' => $budget
                ]
            ];
        }
    }

    return ['type' => 'message', 'text' => $text];
}

function recommendation(array $catalogue, string $message, int $budget, string $destination): array {
    $msg = strtolower($message);
    $destination = $destination ?: destinationKey($message);

    // 1. Flight request intent
    if (str_contains($msg, 'flight') || str_contains($msg, 'fly') || str_contains($msg, 'airline') || str_contains($msg, 'ticket') || str_contains($msg, 'air')) {
        $flights = $catalogue['flights'] ?? [];
        $flightDest = $destination === 'Makkah' || $destination === 'Madinah' ? 'JED' : ($destination === 'Dubai' ? 'DXB' : ($destination === 'Baku' ? 'GYD' : ($destination === 'Istanbul' ? 'IST' : ($destination === 'Phuket' || $destination === 'Bangkok' ? 'BKK' : ''))));
        $matches = array_values(array_filter($flights, static function(array $item) use ($flightDest, $msg): bool {
            if ($flightDest && ($item['to'] ?? '') === $flightDest) return true;
            return matches($item['airline'] . ' ' . $item['from'] . ' ' . $item['to'], $msg);
        }));
        if (empty($matches)) $matches = array_slice($flights, 0, 6);
        return [
            'type' => 'flights',
            'text' => 'Here are the top flight deals' . ($destination ? ' to ' . $destination : '') . ' matching your query:',
            'items' => array_slice($matches, 0, 6)
        ];
    }

    // 2. Hotel request intent
    if (str_contains($msg, 'hotel') || str_contains($msg, 'stay') || str_contains($msg, 'resort') || str_contains($msg, 'accommodation') || str_contains($msg, 'room') || str_contains($msg, 'star')) {
        $hotels = $catalogue['hotels'] ?? [];
        $matches = array_values(array_filter($hotels, static function(array $item) use ($destination, $msg): bool {
            if ($destination && matches($item['city'], $destination)) return true;
            return matches($item['name'] . ' ' . $item['city'], $msg);
        }));
        if (empty($matches)) $matches = array_slice($hotels, 0, 6);
        return [
            'type' => 'hotels',
            'text' => 'Here are top handpicked hotel options' . ($destination ? ' in ' . $destination : '') . ':',
            'items' => array_slice($matches, 0, 6)
        ];
    }

    // 3. Tour / Package / Umrah request intent
    if (str_contains($msg, 'package') || str_contains($msg, 'tour') || str_contains($msg, 'umrah') || str_contains($msg, 'escape') || str_contains($msg, 'trio') || str_contains($msg, 'holiday')) {
        $tours = $catalogue['tours'] ?? [];
        $matches = array_values(array_filter($tours, static function(array $item) use ($destination, $msg): bool {
            if ($destination && matches($item['destination'] . ' ' . $item['title'], $destination)) return true;
            return matches($item['title'] . ' ' . $item['style'], $msg);
        }));
        if (empty($matches)) $matches = array_slice($tours, 0, 6);
        return [
            'type' => 'tours',
            'text' => 'Here are our featured curated travel packages' . ($destination ? ' for ' . $destination : '') . ':',
            'items' => array_slice($matches, 0, 6)
        ];
    }

    // 4. Booking intent
    if (str_contains($msg, 'book') || str_contains($msg, 'confirm') || str_contains($msg, 'reserve') || str_contains($msg, 'checkout')) {
        return [
            'type' => 'booking',
            'text' => 'Ready to secure your trip! Fill in your details below or connect directly with our WhatsApp desk for instant booking.'
        ];
    }

    // 5. Default Trip Builder
    $tours = $catalogue['tours'] ?? [];
    $matches = array_values(array_filter($tours, static fn(array $tour): bool => !$destination || matches($tour['destination'] . ' ' . $tour['title'], $destination)));
    usort($matches, static fn(array $a, array $b): int => abs(($a['price'] ?? 0) - $budget) <=> abs(($b['price'] ?? 0) - $budget));
    $tour = $matches[0] ?? ($tours[0] ?? null);

    if (!$tour) return ['type' => 'message', 'text' => 'Our travel catalogue is updating. Please try again shortly.'];

    $flightDestination = $destination === 'Makkah' || $destination === 'Madinah' ? 'JED' : ($destination === 'Dubai' ? 'DXB' : ($destination === 'Baku' ? 'GYD' : ($destination === 'Istanbul' ? 'IST' : ($destination === 'Phuket' || $destination === 'Bangkok' ? 'BKK' : 'DXB'))));
    $flight = pick($catalogue['flights'] ?? [], static fn(array $item): bool => ($item['to'] ?? '') === $flightDestination);
    $hotel = pick($catalogue['hotels'] ?? [], static fn(array $item): bool => matches((string) ($item['city'] ?? ''), $destination));
    $meal = pick($catalogue['dining'] ?? [], static fn(array $item): bool => matches((string) ($item['destination'] ?? ''), $destination));
    $total = (int) ($tour['price'] ?? 0);
    $minimum = min(array_column($matches ?: [$tour], 'price'));

    if ($budget > 0 && $budget < $minimum) {
        $alternatives = array_values(array_filter($tours, static fn(array $item): bool => ($item['price'] ?? 0) <= $budget || ($item['price'] ?? 0) < $minimum));
        usort($alternatives, static fn(array $a, array $b): int => ($a['price'] ?? 0) <=> ($b['price'] ?? 0));
        return [
            'type' => 'alternatives',
            'text' => 'This budget (PKR ' . number_format($budget) . ') is below the lowest available ' . ($destination ?: 'destination') . ' package at PKR ' . number_format($minimum) . '. Here are tailored value alternatives:',
            'alternatives' => array_slice($alternatives, 0, 4)
        ];
    }

    return [
        'type' => 'trip',
        'text' => 'I’ve built a complete trip package tailored for your brief. You can customize any block before booking.',
        'trip' => ['tour' => $tour, 'flight' => $flight, 'hotel' => $hotel, 'meal' => $meal, 'total' => $total, 'budget' => $budget]
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = catalogue();
    $type = clean((string) ($_GET['type'] ?? 'all'));
    if ($type === 'all') respond(['ok' => true, 'catalogue' => $data]);
    respond(['ok' => true, 'items' => $data[$type] ?? []]);
}

$body = input();
$action = clean((string) ($body['action'] ?? 'chat'));
$catalogue = catalogue();

if ($action === 'chat') {
    $message = clean((string) ($body['message'] ?? ''));
    if ($message === '') respond(['ok' => false, 'error' => 'Tell me where you would like to go.'], 422);
    $budget = money($body['budget'] ?? $message);
    $destination = clean((string) ($body['destination'] ?? ''));

    $aiResult = aiRecommendation($catalogue, $message, $budget, $destination);
    if ($aiResult !== null) {
        respond([
            'ok' => true,
            'reply' => $aiResult,
            'source' => 'ai',
            'catalogueCount' => array_sum(array_map('count', $catalogue))
        ]);
    }

    $computerResult = recommendation($catalogue, $message, $budget, $destination);
    respond([
        'ok' => true,
        'reply' => $computerResult,
        'source' => 'computer',
        'catalogueCount' => array_sum(array_map('count', $catalogue))
    ]);
}

if ($action === 'alternatives') {
    $kind = clean((string) ($body['kind'] ?? 'tours'));
    $items = $catalogue[$kind] ?? [];
    respond(['ok' => true, 'items' => array_slice($items, 0, 12)]);
}

if ($action === 'booking') {
    $booking = $body['booking'] ?? [];
    foreach (['name', 'whatsapp', 'date'] as $required) {
        if (clean((string) ($booking[$required] ?? '')) === '') respond(['ok' => false, 'error' => 'Please complete all required booking fields.'], 422);
    }
    $file = __DIR__ . '/bookings.json';
    $existing = is_file($file) ? json_decode((string) @file_get_contents($file), true) : [];
    if (!is_array($existing)) $existing = [];
    $booking['id'] = 'GG-' . date('YmdHis') . '-' . random_int(100, 999);
    $booking['created_at'] = date(DATE_ATOM);
    $existing[] = $booking;
    @file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    respond(['ok' => true, 'message' => 'Your booking request has been confirmed! Our GoGlobal concierge will contact you via WhatsApp shortly.']);
}

respond(['ok' => false, 'error' => 'Unknown action.'], 400);
