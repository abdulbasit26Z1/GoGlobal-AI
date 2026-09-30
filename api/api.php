<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function catalogue(): array { $data = json_decode((string) file_get_contents(__DIR__ . '/db.json'), true); return is_array($data) ? $data : []; }
function input(): array { $raw = file_get_contents('php://input'); $data = json_decode($raw ?: '', true); return is_array($data) ? $data : $_POST; }
function money(mixed $value): int { preg_match('/[0-9][0-9,]*/', (string) $value, $match); return isset($match[0]) ? (int) str_replace(',', '', $match[0]) : 0; }
function clean(string $value): string { return trim($value); }
function matches(string $haystack, string $needle): bool { return $needle !== '' && str_contains(strtolower($haystack), strtolower($needle)); }
function destinationKey(string $message): string {
    $message = strtolower($message);
    foreach (['dubai' => 'Dubai', 'baku' => 'Baku', 'istanbul' => 'Istanbul', 'turkey' => 'Istanbul', 'phuket' => 'Phuket', 'thailand' => 'Bangkok', 'bangkok' => 'Bangkok', 'umrah' => 'Makkah', 'makkah' => 'Makkah', 'madinah' => 'Madinah'] as $needle => $destination) if (str_contains($message, $needle)) return $destination;
    return '';
}
function pick(array $items, callable $test): ?array { foreach ($items as $item) if ($test($item)) return $item; return $items[0] ?? null; }
function aiRecommendation(array $catalogue, string $message, int $budget, string $destination): ?array {
    if (!function_exists('curl_init')) return null;
    $model = trim((string) (getenv('AZMEER_AI_MODEL') ?: 'gemini-3.6-flash'));
    $catalogueJson = json_encode($catalogue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $instructions = 'You are GoGlobal, a travel concierge for Pakistan-based travellers. Use only the supplied catalogue. Return valid JSON with exactly these keys: type, text, tour_id, flight_id, hotel_id, dining_id. type must be trip or message. For a trip, select IDs from the catalogue and write a concise, helpful explanation. Never invent prices, availability, or catalogue items. If the request is unclear, type must be message and ask one useful follow-up question. Customer budget is in PKR and may be 0 if unknown.';
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
    if ($answer['type'] !== 'trip') return ['type' => 'message', 'text' => clean((string) $answer['text'])];
    $find = static function (string $key, array $items) use ($answer): ?array {
        $id = (string) ($answer[$key] ?? '');
        foreach ($items as $item) if ((string) ($item['id'] ?? '') === $id) return $item;
        return null;
    };
    $tour = $find('tour_id', $catalogue['tours'] ?? []);
    if (!$tour) return null;
    $flight = $find('flight_id', $catalogue['flights'] ?? []);
    $hotel = $find('hotel_id', $catalogue['hotels'] ?? []);
    $meal = $find('dining_id', $catalogue['dining'] ?? []);
    return ['type' => 'trip', 'text' => clean((string) $answer['text']), 'trip' => ['tour' => $tour, 'flight' => $flight, 'hotel' => $hotel, 'meal' => $meal, 'total' => (int) ($tour['price'] ?? 0), 'budget' => $budget]];
}
function recommendation(array $catalogue, string $message, int $budget, string $destination): array {
    $destination = $destination ?: destinationKey($message);
    $tours = $catalogue['tours'] ?? [];
    $matches = array_values(array_filter($tours, static fn(array $tour): bool => !$destination || matches($tour['destination'] . ' ' . $tour['title'], $destination)));
    usort($matches, static fn(array $a, array $b): int => abs(($a['price'] ?? 0) - $budget) <=> abs(($b['price'] ?? 0) - $budget));
    $tour = $matches[0] ?? ($tours[0] ?? null);
    if (!$tour) return ['type' => 'message', 'text' => 'Our catalogue is temporarily unavailable. Please try again in a moment.'];
    $flightDestination = $destination === 'Makkah' || $destination === 'Madinah' ? 'JED' : ($destination === 'Dubai' ? 'DXB' : ($destination === 'Baku' ? 'GYD' : ($destination === 'Istanbul' ? 'IST' : ($destination === 'Phuket' || $destination === 'Bangkok' ? 'BKK' : 'DXB'))));
    $flight = pick($catalogue['flights'] ?? [], static fn(array $item): bool => ($item['to'] ?? '') === $flightDestination);
    $hotel = pick($catalogue['hotels'] ?? [], static fn(array $item): bool => matches((string) ($item['city'] ?? ''), $destination));
    $meal = pick($catalogue['dining'] ?? [], static fn(array $item): bool => matches((string) ($item['destination'] ?? ''), $destination));
    $total = (int) ($tour['price'] ?? 0);
    $minimum = min(array_column($matches ?: [$tour], 'price'));
    if ($budget > 0 && $budget < $minimum) {
        $alternatives = array_values(array_filter($tours, static fn(array $item): bool => ($item['price'] ?? 0) <= $budget || ($item['price'] ?? 0) < $minimum));
        usort($alternatives, static fn(array $a, array $b): int => ($a['price'] ?? 0) <=> ($b['price'] ?? 0));
        return ['type' => 'alternatives', 'text' => 'This budget (PKR ' . number_format($budget) . ') is below the lowest available ' . ($destination ?: 'destination') . ' package at PKR ' . number_format($minimum) . '. I found a shorter stay, a 3-star option, or a value destination to keep the trip realistic.', 'alternatives' => array_slice($alternatives, 0, 4)];
    }
    return ['type' => 'trip', 'text' => 'I found a strong match for your brief. You can swap each building block before booking.', 'trip' => ['tour' => $tour, 'flight' => $flight, 'hotel' => $hotel, 'meal' => $meal, 'total' => $total, 'budget' => $budget]];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = catalogue(); $type = clean((string) ($_GET['type'] ?? 'all'));
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
    $result = aiRecommendation($catalogue, $message, $budget, $destination) ?? recommendation($catalogue, $message, $budget, $destination);
    respond(['ok' => true, 'reply' => $result, 'catalogueCount' => array_sum(array_map('count', $catalogue))]);
}
if ($action === 'alternatives') {
    $kind = clean((string) ($body['kind'] ?? 'tours')); $items = $catalogue[$kind] ?? [];
    respond(['ok' => true, 'items' => array_slice($items, 0, 12)]);
}
if ($action === 'booking') {
    $booking = $body['booking'] ?? [];
    foreach (['name', 'whatsapp', 'date'] as $required) if (clean((string) ($booking[$required] ?? '')) === '') respond(['ok' => false, 'error' => 'Please complete the required booking fields.'], 422);
    $file = __DIR__ . '/bookings.json'; $existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    if (!is_array($existing)) $existing = [];
    $booking['id'] = 'GG-' . date('YmdHis') . '-' . random_int(100, 999); $booking['created_at'] = date(DATE_ATOM); $existing[] = $booking;
    file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    respond(['ok' => true, 'message' => 'Your request is with the GoGlobal team. We will confirm availability on WhatsApp shortly.']);
}
respond(['ok' => false, 'error' => 'Unknown action.'], 400);
