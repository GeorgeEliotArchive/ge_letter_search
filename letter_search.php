<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const MAX_RESULTS = 100;
const MIN_QUERY_LEN = 2;
const SOURCE_URL_BASE = 'https://georgeeliotarchive.org/items/browse';

/*
 * IMPORTANT: set this to the Omeka Collection ID for the letters collection.
 * Leave as '' only while testing; otherwise the search can span unrelated items.
 */
const LETTER_COLLECTION_ID = '';

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_spaces(string $text): string {
    return trim((string)preg_replace('/\s+/u', ' ', $text));
}

function make_snippet(string $text, string $term, int $radius = 150): string {
    $text = normalize_spaces($text);
    if ($text === '') return '';

    $pos = mb_stripos($text, $term, 0, 'UTF-8');
    if ($pos === false) {
        return mb_substr($text, 0, 320, 'UTF-8');
    }

    $termLen = mb_strlen($term, 'UTF-8');
    $textLen = mb_strlen($text, 'UTF-8');
    $start = max(0, $pos - $radius);
    $length = min($textLen - $start, $termLen + 2 * $radius);
    $snippet = mb_substr($text, $start, $length, 'UTF-8');

    if ($start > 0) $snippet = '…' . $snippet;
    if (($start + $length) < $textLen) $snippet .= '…';
    return $snippet;
}

function fetch_csv_from_omeka(string $searchTerm) {
    $params = [
        'search' => $searchTerm,
        'advanced[0][joiner]' => 'and',
        'advanced[0][element_id]' => '',
        'advanced[0][type]' => '',
        'advanced[0][terms]' => '',
        'range' => '',
        'collection' => LETTER_COLLECTION_ID,
        'output' => 'csv',
    ];

    $url = SOURCE_URL_BASE . '?' . http_build_query($params);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'header' => "User-Agent: GeorgeEliotLetterSearch/1.0\r\n",
        ]
    ]);

    return @file_get_contents($url, false, $context);
}

function parse_csv_string(string $csv): array {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream)) !== false) {
        $rows[] = $row;
    }
    fclose($stream);
    return $rows;
}

function normalize_header(string $header): string {
    $header = strip_tags($header);
    $header = strtolower(trim($header));
    return preg_replace('/[^a-z0-9]+/', '', $header);
}

function build_header_map(array $headers): array {
    $map = [];
    foreach ($headers as $i => $header) {
        $map[normalize_header((string)$header)] = $i;
    }
    return $map;
}

function first_field(array $row, array $headerMap, array $names): string {
    foreach ($names as $name) {
        $key = normalize_header($name);
        if (array_key_exists($key, $headerMap)) {
            return trim((string)($row[$headerMap[$key]] ?? ''));
        }
    }
    return '';
}

function sort_date_value(string $date): string {
    $clean = preg_replace('/[^0-9\-]/', '', $date);
    return $clean !== '' ? $clean : '9999-99-99';
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$field = isset($_GET['field']) ? trim((string)$_GET['field']) : 'all';

$allowedFields = ['all', 'text', 'sender', 'recipient', 'date', 'title'];
if (!in_array($field, $allowedFields, true)) $field = 'all';

if (mb_strlen($q, 'UTF-8') < MIN_QUERY_LEN) {
    respond(400, ['error' => 'Search term must be at least 2 characters.']);
}

$csv = fetch_csv_from_omeka($q);
if ($csv === false || trim($csv) === '') {
    respond(502, ['error' => 'Unable to retrieve source data.']);
}

$rows = parse_csv_string($csv);
if (count($rows) <= 1) {
    respond(200, ['count' => 0, 'limited' => false, 'results' => []]);
}

$headers = array_shift($rows);
$headerMap = build_header_map($headers);
$results = [];

foreach ($rows as $row) {
    if (!is_array($row)) continue;

    // Common Omeka / custom-letter header variants. Add aliases here if needed.
    $title = first_field($row, $headerMap, ['Title', 'Letter Title']);
    $date = first_field($row, $headerMap, ['Date', 'Letter Date', 'Date(s)']);
    $sender = first_field($row, $headerMap, ['Sender', 'From', 'Creator']);
    $recipient = first_field($row, $headerMap, ['Recipient', 'Receiver', 'To']);
    $place = first_field($row, $headerMap, ['Place', 'Location']);
    $source = first_field($row, $headerMap, ['Source', 'Bibliographic Citation']);
    $text = first_field($row, $headerMap, [
        'Letter Content', 'Letter Text', 'Text', 'Transcription', 'Description'
    ]);
    $itemUrl = first_field($row, $headerMap, ['Item URL', 'URL', 'Identifier']);

    $valuesByField = [
        'text' => $text,
        'sender' => $sender,
        'recipient' => $recipient,
        'date' => $date,
        'title' => $title,
    ];

    if ($field !== 'all') {
        $candidate = $valuesByField[$field] ?? '';
        if ($candidate === '' || mb_stripos($candidate, $q, 0, 'UTF-8') === false) {
            continue;
        }
    }

    $snippet = make_snippet($text, $q);
    $fullText = normalize_spaces($text);

    $result = [
        'title' => normalize_spaces($title),
        'date' => normalize_spaces($date),
        'sender' => normalize_spaces($sender),
        'recipient' => normalize_spaces($recipient),
        'place' => normalize_spaces($place),
        'source' => normalize_spaces($source),
        'snippet' => $snippet,
        'item_url' => normalize_spaces($itemUrl),
        '_date_sort' => sort_date_value($date),
    ];

    if ($fullText !== '' && $snippet !== $fullText) {
        $result['full_text'] = $fullText;
    }

    $results[] = $result;
    if (count($results) >= MAX_RESULTS) break;
}

usort($results, function ($a, $b) {
    return strcmp($a['_date_sort'], $b['_date_sort']);
});

$results = array_map(function ($item) {
    unset($item['_date_sort']);
    return $item;
}, $results);

respond(200, [
    'count' => count($results),
    'limited' => count($results) >= MAX_RESULTS,
    'results' => $results,
]);
