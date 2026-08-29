<?php

/**
 * PHP скрипт для добавления оплаты студенту в Hollyhop API
 * 
 * Принимает вебхук от AmoCRM с ID транзакции (счета), получает данные через API
 * и записывает оплату в систему Hollyhop.
 * 
 * Структура вебхука:
 * - transactions.add[0].id или transactions.status[0].id - ID транзакции
 */

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
// Увеличиваем таймаут выполнения скрипта до 120 секунд
ini_set('max_execution_time', 120);
set_time_limit(120);

// Логирование ошибок
ini_set('log_errors', 1);
$error_log_file = __DIR__ . '/logs/error.log';
ini_set('error_log', $error_log_file);

// Создаем директорию для логов, если её нет
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

// Обработчик фатальных ошибок
register_shutdown_function(function () use ($log_dir) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log_file = $log_dir . '/pay.log';
        $message = "[{$error['type']}] {$error['message']} in {$error['file']} on line {$error['line']}";
        @file_put_contents($log_file, date('Y-m-d H:i:s') . " [FATAL ERROR] " . $message . "\n" . str_repeat('-', 80) . "\n", FILE_APPEND | LOCK_EX);
    }
});

// ============ КОНФИГУРАЦИЯ ============
// Подключаем единую систему логирования
require_once __DIR__ . '/logger.php';
// Подключаем функции для работы с AmoCRM API
require_once __DIR__ . '/amo_func.php';
// Загружаем конфигурацию из .env файла
require_once __DIR__ . '/config.php';

const AMO_FIELD_PROFILE_LINK = 1630807;
const AMO_CONTACT_FIELD_PHONE = 1138327;
const AMO_CONTACT_FIELD_EMAIL = 1138329;
const RECENT_SEARCH_MONTHS = 3;
const RECENT_SEARCH_PAGE_LIMIT = 8;
const EXTENDED_SEARCH_MONTHS = 6;
const EXTENDED_SEARCH_PAGE_LIMIT = 6;

// Используем index.php — он содержит полную логику поиска и создания студента
const INDEX_ENDPOINT = 'https://srm.chinatutor.ru/index.php?payment_webhook=1';

/**
 * Функции логирования для add_payment.php (запись в отдельный файл pay.log)
 */
function log_payment_message($message, $data = null, $level = 'INFO')
{
    $levels = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];
    $current_level = $levels[strtoupper($level)] ?? 1;
    $min_level = function_exists('get_config') ? (get_config('logging.level') ?? 'WARNING') : 'WARNING';
    $min_level_num = $levels[strtoupper($min_level)] ?? 2;

    if ($current_level < $min_level_num) {
        return;
    }

    $log_dir = __DIR__ . '/logs';
    $log_file = $log_dir . '/pay.log';

    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}";

    if ($data !== null) {
        if (is_string($data)) {
            $log_entry .= "\n" . $data;
        } else {
            $log_entry .= "\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    $log_entry .= "\n" . str_repeat('-', 80) . "\n";

    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function log_payment_info($message, $data = null)
{
    log_payment_message($message, $data, 'INFO');
}

function log_payment_warning($message, $data = null)
{
    log_payment_message($message, $data, 'WARNING');
}

function log_payment_error($message, $data = null)
{
    log_payment_message($message, $data, 'ERROR');
}

function log_payment_debug($message, $data = null)
{
    log_payment_message($message, $data, 'DEBUG');
}

function normalize_phone_for_match(?string $phone): string
{
    if ($phone === null) {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits) || $digits === '') {
        return '';
    }

    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }

    if (strlen($digits) >= 10) {
        return substr($digits, -10);
    }

    return $digits;
}

function normalize_email_for_match(?string $email): string
{
    $email = is_string($email) ? trim(mb_strtolower($email)) : '';
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function extract_payer_data_from_catalog_element(array $catalogElement): array
{
    $payerData = [
        'contact_id' => null,
        'phones' => [],
        'emails' => [],
        'name' => null,
    ];

    foreach ($catalogElement['custom_fields_values'] ?? [] as $field) {
        $fieldCode = $field['field_code'] ?? $field['code'] ?? null;
        if ($fieldCode !== 'PAYER') {
            continue;
        }

        foreach ($field['values'] ?? [] as $valueRow) {
            $value = $valueRow['value'] ?? null;

            if (!is_array($value)) {
                continue;
            }

            if (($value['entity_type'] ?? null) === 'contacts' && !empty($value['entity_id'])) {
                $payerData['contact_id'] = (int) $value['entity_id'];
            }

            if (!empty($value['name']) && !$payerData['name']) {
                $payerData['name'] = trim((string) $value['name']);
            }

            $normalizedPhone = normalize_phone_for_match($value['phone'] ?? null);
            if ($normalizedPhone !== '') {
                $payerData['phones'][$normalizedPhone] = $normalizedPhone;
            }

            $normalizedEmail = normalize_email_for_match($value['email'] ?? null);
            if ($normalizedEmail !== '') {
                $payerData['emails'][$normalizedEmail] = $normalizedEmail;
            }
        }
    }

    $payerData['phones'] = array_values($payerData['phones']);
    $payerData['emails'] = array_values($payerData['emails']);

    return $payerData;
}

function fetch_contact_lead_ids(string $subdomain, array $data, int $contactId): array
{
    $response = get($subdomain, '/api/v4/contacts/' . $contactId . '?with=leads', $data);
    $leads = $response['_embedded']['leads'] ?? [];

    if (!is_array($leads) || empty($leads)) {
        return [];
    }

    return array_values(array_filter(array_map(static function ($lead) {
        $leadId = (int) ($lead['id'] ?? 0);
        return $leadId > 0 ? $leadId : null;
    }, $leads)));
}

function extract_contact_channels(array $contact): array
{
    $phones = [];
    $emails = [];

    foreach ($contact['custom_fields_values'] ?? [] as $field) {
        $fieldCode = $field['field_code'] ?? $field['code'] ?? null;
        if (!in_array($fieldCode, ['PHONE', 'EMAIL'], true)) {
            continue;
        }

        foreach ($field['values'] ?? [] as $valueRow) {
            $rawValue = $valueRow['value'] ?? null;
            if (!is_string($rawValue) || trim($rawValue) === '') {
                continue;
            }

            if ($fieldCode === 'PHONE') {
                $normalizedPhone = normalize_phone_for_match($rawValue);
                if ($normalizedPhone !== '') {
                    $phones[$normalizedPhone] = $normalizedPhone;
                }
            } elseif ($fieldCode === 'EMAIL') {
                $normalizedEmail = normalize_email_for_match($rawValue);
                if ($normalizedEmail !== '') {
                    $emails[$normalizedEmail] = $normalizedEmail;
                }
            }
        }
    }

    return [
        'phones' => array_values($phones),
        'emails' => array_values($emails),
    ];
}

function search_contacts_by_queries(string $subdomain, array $data, array $queries): array
{
    $foundContacts = [];

    foreach ($queries as $query) {
        $query = trim((string) $query);
        if ($query === '') {
            continue;
        }

        $response = get(
            $subdomain,
            '/api/v4/contacts?with=leads&limit=250&query=' . urlencode($query),
            $data
        );

        foreach ($response['_embedded']['contacts'] ?? [] as $contact) {
            $contactId = (int) ($contact['id'] ?? 0);
            if ($contactId > 0) {
                $foundContacts[$contactId] = $contact;
            }
        }
    }

    return array_values($foundContacts);
}

function find_contacts_by_payer_data(string $subdomain, array $data, array $payerData): array
{
    $queries = array_merge($payerData['emails'] ?? [], $payerData['phones'] ?? []);
    $contacts = search_contacts_by_queries($subdomain, $data, $queries);

    if (empty($contacts)) {
        return [];
    }

    $wantedPhones = array_flip($payerData['phones'] ?? []);
    $wantedEmails = array_flip($payerData['emails'] ?? []);
    $exactMatches = [];

    foreach ($contacts as $contact) {
        $channels = extract_contact_channels($contact);
        $matchedPhone = false;
        foreach ($channels['phones'] as $phone) {
            if (isset($wantedPhones[$phone])) {
                $matchedPhone = true;
                break;
            }
        }

        $matchedEmail = false;
        foreach ($channels['emails'] as $email) {
            if (isset($wantedEmails[$email])) {
                $matchedEmail = true;
                break;
            }
        }

        if ($matchedPhone || $matchedEmail) {
            $exactMatches[] = $contact;
        }
    }

    if (!empty($exactMatches)) {
        return $exactMatches;
    }

    if (count($contacts) === 1) {
        return $contacts;
    }

    return [];
}

function find_lead_by_catalog_element_links(
    string $subdomain,
    array $data,
    int $catalog_element_id,
    ?int $catalog_id = null
): ?array {
    if (!$catalog_id) {
        return null;
    }

    $response = get(
        $subdomain,
        '/api/v4/catalogs/' . $catalog_id . '/elements/' . $catalog_element_id . '/links',
        $data
    );

    $links = $response['_embedded']['links'] ?? [];
    if (!is_array($links) || empty($links)) {
        return null;
    }

    foreach ($links as $link) {
        $leadId = (int) ($link['to_entity_id'] ?? 0);
        if (($link['to_entity_type'] ?? null) === 'leads' && $leadId > 0) {
            return [
                'lead_id' => $leadId,
                'checked_leads' => 1,
                'strategy' => 'catalog_element_links',
            ];
        }
    }

    return null;
}

function find_lead_by_catalog_link(
    string $subdomain,
    array $data,
    int $catalog_element_id,
    ?int $catalog_id = null,
    ?array $catalogElement = null
): ?array {
    $started_at = microtime(true);

    $directLinkResult = find_lead_by_catalog_element_links($subdomain, $data, $catalog_element_id, $catalog_id);
    if ($directLinkResult) {
        log_payment_info("✓ Сделка найдена через links элемента счета", [
            'lead_id' => $directLinkResult['lead_id'],
            'catalog_element_id' => $catalog_element_id,
            'catalog_id' => $catalog_id,
            'elapsed_seconds' => round(microtime(true) - $started_at, 2),
        ]);
        return $directLinkResult;
    }

    if ($catalogElement === null) {
        if (!$catalog_id) {
            return null;
        }

        $catalogElement = get($subdomain, '/api/v4/catalogs/' . $catalog_id . '/elements/' . $catalog_element_id, $data);
    }

    $payerData = extract_payer_data_from_catalog_element($catalogElement);
    $payerContactId = $payerData['contact_id'];

    log_payment_info("Поиск сделки по счету через плательщика счета", [
        'catalog_element_id' => $catalog_element_id,
        'catalog_id' => $catalog_id,
        'payer_contact_id' => $payerContactId,
        'payer_phones' => $payerData['phones'],
        'payer_emails' => $payerData['emails'],
    ]);

    $candidateLeadIds = [];
    $matchedContactIds = [];

    if ($payerContactId) {
        $matchedContactIds[] = $payerContactId;
        $candidateLeadIds = fetch_contact_lead_ids($subdomain, $data, $payerContactId);
    } elseif (!empty($payerData['phones']) || !empty($payerData['emails'])) {
        $matchedContacts = find_contacts_by_payer_data($subdomain, $data, $payerData);

        foreach ($matchedContacts as $contact) {
            $contactId = (int) ($contact['id'] ?? 0);
            if ($contactId <= 0) {
                continue;
            }

            $matchedContactIds[] = $contactId;
            foreach (($contact['_embedded']['leads'] ?? []) as $lead) {
                $leadId = (int) ($lead['id'] ?? 0);
                if ($leadId > 0) {
                    $candidateLeadIds[$leadId] = $leadId;
                }
            }
        }

        log_payment_info("Результат поиска контакта плательщика по данным счета", [
            'catalog_element_id' => $catalog_element_id,
            'catalog_id' => $catalog_id,
            'matched_contact_ids' => $matchedContactIds,
            'matched_contacts_count' => count($matchedContactIds),
        ]);
    } else {
        log_payment_warning("У счета не найден ни contact_id, ни контакты плательщика", [
            'catalog_element_id' => $catalog_element_id,
            'catalog_id' => $catalog_id,
        ]);
        return null;
    }

    $candidateLeadIds = array_values(array_unique(array_map('intval', (array) $candidateLeadIds)));

    log_payment_info("Получены сделки контакта-плательщика", [
        'catalog_element_id' => $catalog_element_id,
        'catalog_id' => $catalog_id,
        'payer_contact_id' => $payerContactId,
        'matched_contact_ids' => $matchedContactIds,
        'candidate_leads_count' => count($candidateLeadIds),
        'candidate_lead_ids' => $candidateLeadIds,
    ]);

    if (empty($candidateLeadIds)) {
        return null;
    }

    $checkedLeads = 0;
    foreach (array_chunk($candidateLeadIds, 50) as $leadChunk) {
        $queryParts = [];

        foreach ($leadChunk as $leadId) {
            $queryParts[] = 'filter[entity_id][]=' . urlencode((string) $leadId);
        }

        $queryParts[] = 'filter[to_entity_id]=' . urlencode((string) $catalog_element_id);
        $queryParts[] = 'filter[to_entity_type]=' . urlencode('catalog_elements');
        if ($catalog_id) {
            $queryParts[] = 'filter[to_catalog_id]=' . urlencode((string) $catalog_id);
        }

        $linksApiUrl = '/api/v4/leads/links?' . implode('&', $queryParts);
        $linksResponse = get($subdomain, $linksApiUrl, $data);

        $checkedLeads += count($leadChunk);

        if (empty($linksResponse['_embedded']['links']) || !is_array($linksResponse['_embedded']['links'])) {
            continue;
        }

        foreach ($linksResponse['_embedded']['links'] as $foundLink) {
            $leadIdFromLink = (int) ($foundLink['entity_id'] ?? 0);
            $linkCatalogElementId = (int) ($foundLink['to_entity_id'] ?? 0);
            $linkCatalogId = (int) ($foundLink['metadata']['catalog_id'] ?? 0);

            if (
                $leadIdFromLink > 0 &&
                ($foundLink['entity_type'] ?? null) === 'leads' &&
                ($foundLink['to_entity_type'] ?? null) === 'catalog_elements' &&
                $linkCatalogElementId === $catalog_element_id &&
                (!$catalog_id || !$linkCatalogId || $linkCatalogId === $catalog_id)
            ) {
                log_payment_info("✓ Сделка найдена через плательщика счета и API links", [
                    'lead_id' => $leadIdFromLink,
                    'catalog_element_id' => $catalog_element_id,
                    'catalog_id' => $catalog_id,
                    'payer_contact_id' => $payerContactId,
                    'matched_contact_ids' => $matchedContactIds,
                    'checked_leads' => $checkedLeads,
                    'elapsed_seconds' => round(microtime(true) - $started_at, 2),
                ]);

                return [
                    'lead_id' => $leadIdFromLink,
                    'checked_leads' => $checkedLeads,
                    'strategy' => $payerContactId ? 'payer_contact_links' : 'payer_query_links',
                ];
            }
        }
    }

    log_payment_warning("Сделка не найдена через плательщика счета", [
        'catalog_element_id' => $catalog_element_id,
        'catalog_id' => $catalog_id,
        'payer_contact_id' => $payerContactId,
        'matched_contact_ids' => $matchedContactIds,
        'candidate_leads_count' => count($candidateLeadIds),
        'elapsed_seconds' => round(microtime(true) - $started_at, 2),
    ]);

    return null;
}

// Получаем параметры из конфигурации
$api_config = get_config('api');
$auth_key = $api_config['auth_key'];
$api_base_url = $api_config['base_url'];
$hollyhop_subdomain = $api_config['subdomain'] ?? null;

// Допустимые методы запроса
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    log_payment_warning("Получен запрос с неподдерживаемым методом", ['method' => $_SERVER['REQUEST_METHOD']]);
    echo json_encode([
        'success' => false,
        'error' => 'Метод не поддерживается. Используйте POST.'
    ]);
    exit;
}

/**
 * Отправка запроса к Hollyhop API
 */
function call_hollyhop_api($function_name, $params, $auth_key, $api_base_url)
{
    $url = $api_base_url . '/' . $function_name;

    $params['authkey'] = $auth_key;
    $post_data = json_encode($params);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_data,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($post_data)
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    curl_close($ch);

    if ($curl_error) {
        log_payment_error("cURL ошибка: {$curl_error}", ['function' => $function_name]);
        throw new Exception("Ошибка подключения к API: {$curl_error}");
    }

    if ($http_code >= 400) {
        log_payment_error("API ошибка (HTTP {$http_code})", ['function' => $function_name, 'response' => $response]);
        throw new Exception("Ошибка API (HTTP {$http_code}): {$response}");
    }

    $result = json_decode($response, true);

    if ($result === null) {
        log_payment_error("Ошибка декодирования JSON", [
            'function' => $function_name,
            'raw_response' => $response,
            'json_error' => json_last_error_msg(),
            'http_code' => $http_code
        ]);
        throw new Exception("Некорректный ответ от API. Raw response: " . substr($response, 0, 500));
    }

    return $result;
}

function perform_hollyhop_web_request(string $url, string $cookie_file, ?array $post_pairs = null): array
{
    $ch = curl_init();

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookie_file,
        CURLOPT_COOKIEFILE => $cookie_file,
    ];

    if ($post_pairs !== null) {
        $encoded_pairs = [];
        foreach ($post_pairs as [$key, $value]) {
            $encoded_pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        $post_body = implode('&', $encoded_pairs);

        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $post_body;
        $options[CURLOPT_HTTPHEADER] = [
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($post_body),
        ];
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    curl_close($ch);

    if ($curl_error) {
        throw new Exception("Ошибка web-запроса Hollyhop: {$curl_error}");
    }

    if ($http_code >= 400) {
        throw new Exception("Hollyhop web-запрос вернул HTTP {$http_code}: " . substr((string) $response, 0, 500));
    }

    return [
        'http_code' => $http_code,
        'body' => (string) $response,
    ];
}

function create_hollyhop_web_session(string $hollyhop_subdomain, string $auth_key): string
{
    $cookie_dir = __DIR__ . '/logs';
    if (!is_dir($cookie_dir) && !@mkdir($cookie_dir, 0755, true) && !is_dir($cookie_dir)) {
        throw new Exception('Не удалось создать директорию для cookie-файлов Hollyhop.');
    }

    try {
        $random_suffix = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $random_suffix = uniqid('', true);
    }

    $cookie_file = $cookie_dir . '/hollyhop_web_' . $random_suffix . '.cookie';
    if (@file_put_contents($cookie_file, '') === false) {
        throw new Exception('Не удалось создать cookie-файл Hollyhop в директории проекта.');
    }

    $auth_url = "https://{$hollyhop_subdomain}.t8s.ru/Account/AuthByKey?authkey=" . rawurlencode($auth_key);
    perform_hollyhop_web_request($auth_url, $cookie_file);

    return $cookie_file;
}

function extract_hollyhop_form_value(string $html, string $name, ?string $default = null): ?string
{
    $quoted_name = preg_quote($name, '/');
    $patterns = [
        '/<input[^>]*name="' . $quoted_name . '"[^>]*value="([^"]*)"/ui',
        '/<textarea[^>]*name="' . $quoted_name . '"[^>]*>(.*?)<\/textarea>/uis',
        '/<select[^>]*name="' . $quoted_name . '"[^>]*>.*?<option[^>]*selected(?:="selected"|=\'selected\')?[^>]*value="([^"]*)"/uis',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return $default;
}

function create_hollyhop_refund_via_web_form(
    string $hollyhop_subdomain,
    string $auth_key,
    int $payer_id,
    int $office_id,
    float $amount,
    int $payment_method_id,
    string $description,
    string $date_iso
): array {
    $cookie_file = create_hollyhop_web_session($hollyhop_subdomain, $auth_key);

    try {
        $form_url = "https://{$hollyhop_subdomain}.t8s.ru/Payment/EditStudyRefund?payerId={$payer_id}";
        $form_response = perform_hollyhop_web_request($form_url, $cookie_file);
        $form_html = $form_response['body'];

        $bill_number = extract_hollyhop_form_value($form_html, 'BillNumber');
        if ($bill_number === null || $bill_number === '') {
            throw new Exception('Не удалось получить BillNumber из формы возврата Hollyhop.');
        }

        $clndr_begin_date = extract_hollyhop_form_value($form_html, 'ClndrUnits.BeginDate', date('d.m.Y 0:00:00', strtotime($date_iso)));
        $date_for_form = date('Y-m-d\T00:00:00', strtotime($date_iso));
        $amount_for_form = number_format(abs($amount), 2, ',', '');

        $post_pairs = [
            ['Id', '0'],
            ['PayerId', (string) $payer_id],
            ['StudentId', ''],
            ['Date', $date_for_form],
            ['BillNumber', $bill_number],
            ['AbstractSchoolId', (string) $office_id],
            ['PriceType', 'Hourly'],
            ['PriceType', 'Packet'],
            ['EstimatedDate', ''],
            ['Units.Units', '0'],
            ['Units.UnitsMultiplier', '1'],
            ['Units.PartialUnits', '1'],
            ['Units.NonPartialMultiplier', '1'],
            ['Units.NonPartialUnits', '1'],
            ['Units.AcademHourId', '1'],
            ['ClndrUnits.BeginDate', $clndr_begin_date],
            ['ClndrUnits.Months', '0'],
            ['ClndrUnits.Days', '1'],
            ['ClndrUnits.ClndrMultiplier', '1'],
            ['ClndrUnits.PartialMonths', '0'],
            ['ClndrUnits.PartialDays', '1'],
            ['ClndrUnits.ClndrNonPartialMultiplier', '1'],
            ['ClndrUnits.ClndrNonPartialUnits', ''],
            ['Value', $amount_for_form],
            ['RestoredValue', '0'],
            ['CurrencyAllId', '0'],
            ['CurrencyRealId', '0'],
            ['Currency.Id', '0'],
            ['PaymentMethod.Id', (string) $payment_method_id],
            ['PaymentMethod.Id', ''],
            ['Description', $description],
        ];

        $submit_url = "https://{$hollyhop_subdomain}.t8s.ru/Payment/EditStudyRefund";
        $submit_response = perform_hollyhop_web_request($submit_url, $cookie_file, $post_pairs);
        $response_body = $submit_response['body'];

        $success_redirect = '/Payment/Payer/' . $payer_id;
        if (!str_contains($response_body, $success_redirect)) {
            $validation_error = null;
            if (preg_match('/<span[^>]*field-validation-error[^>]*>(.*?)<\/span>/uis', $response_body, $matches)) {
                $validation_error = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            if (!$validation_error && preg_match('/<div[^>]*validation-summary-errors[^>]*>.*?<li[^>]*>(.*?)<\/li>/uis', $response_body, $matches)) {
                $validation_error = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            throw new Exception(
                'Hollyhop не подтвердил создание возврата через web-форму.'
                . ($validation_error ? ' Ошибка: ' . $validation_error : '')
            );
        }

        return [
            'billNumber' => $bill_number,
            'request' => $post_pairs,
            'responsePreview' => substr($response_body, 0, 300),
        ];
    } finally {
        if (is_file($cookie_file)) {
            @unlink($cookie_file);
        }
    }
}

/**
 * Извлекает ID профиля студента из объекта студента Hollyhop.
 */
function extract_student_profile_id(array $student): ?int
{
    $profile_id = $student['Id'] ?? $student['id'] ?? null;
    if ($profile_id === null || $profile_id === '') {
        return null;
    }

    return is_numeric($profile_id) ? (int) $profile_id : null;
}

/**
 * Формирует ссылку на профиль Hollyhop.
 */
function build_hollyhop_profile_link(int $profile_id, string $hollyhop_subdomain): string
{
    return "https://{$hollyhop_subdomain}.t8s.ru/Profile/{$profile_id}";
}

/**
 * Обновляет поле "Ссылка на профиль" в сделке AmoCRM.
 */
function update_lead_profile_link_in_amocrm(int $lead_id, string $profile_link, string $subdomain, array $data): void
{
    $lead_update = [
        'id' => $lead_id,
        'custom_fields_values' => [
            [
                'field_id' => AMO_FIELD_PROFILE_LINK,
                'values' => [
                    ['value' => $profile_link]
                ]
            ]
        ]
    ];

    post_or_patch($subdomain, $lead_update, "/api/v4/leads/{$lead_id}", $data, 'PATCH');
}

function normalize_pipeline_name(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace('ё', 'е', $value);
    return (string) preg_replace('/\s+/u', ' ', $value);
}

function fetch_pipeline_name_by_id(string $subdomain, array $data, ?int $pipeline_id): ?string
{
    if (!$pipeline_id) {
        return null;
    }

    try {
        $pipeline = get($subdomain, '/api/v4/leads/pipelines/' . $pipeline_id, $data);
    } catch (Exception $e) {
        log_payment_warning("Не удалось получить название воронки по pipeline_id", [
            'pipeline_id' => $pipeline_id,
            'error' => $e->getMessage(),
        ]);
        return null;
    }

    $pipeline_name = trim((string) ($pipeline['name'] ?? ''));
    return $pipeline_name !== '' ? $pipeline_name : null;
}

function resolve_payment_type_by_pipeline(?int $pipeline_id, ?string $pipeline_name = null): ?string
{
    if ($pipeline_id) {
        $payment_type_by_id = match ($pipeline_id) {
            9719942 => 'языковой лагерь за рубежом',
            8117846 => 'обучение за рубежом. поступление в вуз',
            default => null,
        };

        if ($payment_type_by_id !== null) {
            return $payment_type_by_id;
        }
    }

    $normalized_name = $pipeline_name !== null ? normalize_pipeline_name($pipeline_name) : '';
    if ($normalized_name === '') {
        return null;
    }

    return match (true) {
        str_contains($normalized_name, 'лагер') && str_contains($normalized_name, 'рубеж') => 'языковой лагерь за рубежом',
        str_contains($normalized_name, 'поступлен') && str_contains($normalized_name, 'вуз') => 'обучение за рубежом. поступление в вуз',
        str_contains($normalized_name, 'обучение за рубежом') => 'обучение за рубежом. поступление в вуз',
        str_contains($normalized_name, 'курс') || str_contains($normalized_name, 'общая') => 'обучение',
        default => null,
    };
}

function has_non_empty_amo_field(array $custom_fields_values, int $target_field_id): bool
{
    foreach ($custom_fields_values as $field) {
        if ((int) ($field['field_id'] ?? 0) !== $target_field_id) {
            continue;
        }

        foreach (($field['values'] ?? []) as $value_row) {
            $value = $value_row['value'] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return true;
            }

            if (is_numeric($value)) {
                return true;
            }

            if (is_array($value) && !empty($value)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Вызывает index.php передавая lead_id в формате вебхука AmoCRM (leads add).
 * index.php содержит полную логику поиска существующего студента и создания нового.
 * После вызова index.php ищем студента в Hollyhop по телефону/email контакта сделки,
 * либо по ссылке на профиль в сделке AmoCRM.
 */
function call_index_for_student(int $lead_id, array $lead, string $subdomain, array $amo_data, string $auth_key, string $api_base_url): array
{
    log_payment_info("Вызов index.php для создания/поиска студента", ['lead_id' => $lead_id]);

    // Отправляем вебхук в формате AmoCRM leads add
    $post_fields = http_build_query([
        'leads' => [
            'add' => [
                0 => ['id' => $lead_id]
            ]
        ]
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => INDEX_ENDPOINT,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post_fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new Exception("Ошибка вызова index.php: {$curl_error}");
    }

    if ($http_code >= 400) {
        throw new Exception("index.php вернул HTTP {$http_code}: " . substr((string) $response, 0, 500));
    }

    log_payment_info("index.php вызван, ответ получен", [
        'lead_id'   => $lead_id,
        'http_code' => $http_code,
        'response_preview' => substr((string) $response, 0, 300)
    ]);

    // index.php возвращает clientId и Id профиля сразу. Не ждем, пока
    // повторное чтение сделки из AmoCRM увидит записанную ссылку.
    $index_payload = null;
    $response_json = trim((string) $response);
    $decoded_response = json_decode($response_json, true);
    if (is_array($decoded_response)) {
        $index_payload = $decoded_response;
    } elseif (preg_match('/(\{.*\})/s', $response_json, $matches)) {
        $decoded_response = json_decode($matches[1], true);
        if (is_array($decoded_response)) {
            $index_payload = $decoded_response;
        }
    }

    $response_client_id = is_array($index_payload)
        ? ($index_payload['clientId'] ?? $index_payload['client_id'] ?? null)
        : null;
    $response_profile_id = is_array($index_payload)
        ? ($index_payload['Id'] ?? $index_payload['id'] ?? $index_payload['profile_id'] ?? null)
        : null;
    $response_profile_link = is_array($index_payload)
        ? ($index_payload['link'] ?? $index_payload['profile_link'] ?? null)
        : null;

    if ($response_client_id) {
        log_payment_info("clientId получен непосредственно из ответа index.php", [
            'lead_id' => $lead_id,
            'clientId' => $response_client_id,
            'profile_id' => $response_profile_id,
            'profile_link' => $response_profile_link,
        ]);
    }

    // После вызова index.php проверяем, записал ли он ссылку на профиль
    // обратно в сделку AmoCRM.

    // Небольшая пауза чтобы index.php успел записать данные
    sleep(2);

    // Перечитываем сделку — index.php должен был записать ссылку на профиль
    $updated_lead = get($subdomain, '/api/v4/leads/' . $lead_id, $amo_data);

    $profile_link     = null;
    $resolved_profile_id = null;

    if ($response_profile_link) {
        $profile_link = trim((string) $response_profile_link);
    }
    if ($response_profile_id && is_numeric($response_profile_id)) {
        $resolved_profile_id = (int) $response_profile_id;
    } elseif ($profile_link && preg_match('/\/Profile\/(\d+)/', $profile_link, $matches)) {
        $resolved_profile_id = (int) $matches[1];
    }

    foreach ($updated_lead['custom_fields_values'] ?? [] as $field) {
        if (($field['field_id'] ?? null) == AMO_FIELD_PROFILE_LINK && !empty($field['values'][0]['value'])) {
            $profile_link = trim((string) $field['values'][0]['value']);
            if (preg_match('/\/Profile\/(\d+)/', $profile_link, $matches)) {
                $resolved_profile_id = (int) $matches[1];
            }
            break;
        }
    }

    if (!$profile_link && !$response_client_id) {
        log_payment_warning("После вызова index.php ссылка на профиль в сделке не появилась", [
            'lead_id' => $lead_id
        ]);
        throw new Exception("index.php не записал ссылку на профиль в сделку {$lead_id}");
    }

    log_payment_info($profile_link
        ? "Ссылка на профиль найдена после index.php"
        : "Ссылка еще не видна в AmoCRM, используем clientId из ответа index.php", [
        'lead_id'    => $lead_id,
        'profile_link' => $profile_link,
        'profile_id' => $resolved_profile_id,
        'clientId_from_response' => $response_client_id,
    ]);

    // Получаем clientId из Hollyhop по profile_id
    $client_id = $response_client_id ? (int) $response_client_id : null;
    if ($resolved_profile_id) {
        try {
            $api_response = call_hollyhop_api('GetStudents', ['Id' => $resolved_profile_id], $auth_key, $api_base_url);

            $student_info = null;
            if (is_array($api_response)) {
                if (isset($api_response['ClientId']) || isset($api_response['clientId'])) {
                    $student_info = $api_response;
                } elseif (isset($api_response['Students']) && is_array($api_response['Students'])) {
                    foreach ($api_response['Students'] as $student) {
                        if (is_array($student) && (($student['Id'] ?? $student['id'] ?? null) == $resolved_profile_id)) {
                            $student_info = $student;
                            break;
                        }
                    }
                }
            }

            if ($student_info) {
                $client_id = $student_info['ClientId'] ?? $student_info['clientId'] ?? null;
                log_payment_info("clientId получен из Hollyhop по profile_id", [
                    'profile_id' => $resolved_profile_id,
                    'clientId'   => $client_id
                ]);
            }
        } catch (Exception $e) {
            log_payment_warning("Не удалось получить clientId по profile_id после index.php", [
                'profile_id' => $resolved_profile_id,
                'error'      => $e->getMessage()
            ]);
        }
    }

    if (!$client_id) {
        throw new Exception("Не удалось получить clientId после вызова index.php для сделки {$lead_id}");
    }

    return [
        'client_id'    => $client_id,
        'profile_link' => $profile_link,
        'profile_id'   => $resolved_profile_id,
    ];
}

/**
 * Основная логика скрипта
 */
try {
    $transaction_id      = null;
    $lead_id_from_webhook = null;
    $catalog_element_id  = null;
    $catalog_id          = null;
    $event_type          = null;
    $payment_link        = null;

    // Определяем тип вебхука
    if (isset($_POST["transactions"]["add"][0]["id"])) {
        $transaction_id = (int) $_POST["transactions"]["add"][0]["id"];
        $event_type = 'transaction_add';
        log_payment_info("Вебхук: транзакция создана", ['transaction_id' => $transaction_id]);
    } elseif (isset($_POST["transactions"]["status"][0]["id"])) {
        $transaction_id = (int) $_POST["transactions"]["status"][0]["id"];
        $event_type = 'transaction_status';
        log_payment_info("Вебхук: статус транзакции изменен", ['transaction_id' => $transaction_id]);
    } elseif (isset($_POST["leads"]["add"][0]["id"])) {
        $lead_id_from_webhook = (int) $_POST["leads"]["add"][0]["id"];
        $event_type = 'lead_add';
        log_payment_info("Вебхук: сделка создана", ['lead_id' => $lead_id_from_webhook]);
    } elseif (isset($_POST["leads"]["status"][0]["id"])) {
        $lead_id_from_webhook = (int) $_POST["leads"]["status"][0]["id"];
        $event_type = 'lead_status';
        log_payment_info("Вебхук: статус сделки изменен", ['lead_id' => $lead_id_from_webhook]);
    } elseif (isset($_POST["catalogs"]["update"][0]["id"])) {
        $catalog_element_id = (int) $_POST["catalogs"]["update"][0]["id"];
        $catalog_id = isset($_POST["catalogs"]["update"][0]["catalog_id"])
            ? (int) $_POST["catalogs"]["update"][0]["catalog_id"]
            : null;
        $event_type = 'catalog_update';
        log_payment_info("Вебхук: счет обновлен", ['catalog_element_id' => $catalog_element_id, 'catalog_id' => $catalog_id]);
    } elseif (isset($_POST["catalogs"]["add"][0]["id"])) {
        $catalog_element_id = (int) $_POST["catalogs"]["add"][0]["id"];
        $catalog_id = isset($_POST["catalogs"]["add"][0]["catalog_id"])
            ? (int) $_POST["catalogs"]["add"][0]["catalog_id"]
            : null;
        $event_type = 'catalog_add';
        log_payment_info("Вебхук: счет создан", ['catalog_element_id' => $catalog_element_id, 'catalog_id' => $catalog_id]);
    } else {
        log_payment_warning("Вебхук получен, но не удалось определить transaction_id, lead_id или catalog_id");
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не удалось определить ID транзакции, сделки или счета из вебхука'
        ]);
        exit;
    }

    // =========================================================================
    // ОБРАБОТКА СЧЁТА (каталог)
    // =========================================================================
    if ($catalog_element_id && $catalog_id) {

        try {
            $catalog_data = $_POST["catalogs"]["update"][0] ?? $_POST["catalogs"]["add"][0] ?? null;

            if (!$catalog_data) {
                throw new Exception("Не удалось извлечь данные счета из вебхука");
            }

            $custom_fields     = $catalog_data['custom_fields'] ?? [];
            $bill_status       = null;
            $bill_price        = null;
            $bill_payment_date = null;

            foreach ($custom_fields as $field) {
                $code     = $field['code'] ?? null;
                $field_id = $field['id'] ?? null;
                $values   = $field['values'] ?? [];

                if ($code === 'BILL_STATUS') {
                    $bill_status = is_array($values[0]) ? ($values[0]['value'] ?? null) : ($values[0] ?? null);
                } elseif ($code === 'BILL_PRICE') {
                    $bill_price = is_array($values[0]) ? ($values[0]['value'] ?? null) : ($values[0] ?? null);
                } elseif ($code === 'BILL_PAYMENT_DATE') {
                    if (is_array($values[0])) {
                        $bill_payment_date = $values[0]['value'] ?? $values[0][0] ?? null;
                    } else {
                        $bill_payment_date = $values[0] ?? null;
                    }
                    if (is_array($values) && count($values) > 0 && !is_array($values[0])) {
                        $bill_payment_date = $values[0];
                    }
                } elseif ($code === 'INVOICE_HASH_LINK' || $field_id == 1622603 || $field_id == 1630781) {
                    $payment_link_raw = is_array($values[0]) ? ($values[0]['value'] ?? null) : ($values[0] ?? null);
                    $payment_link = $payment_link_raw ? trim($payment_link_raw) : null;
                    if ($payment_link === '') {
                        $payment_link = null;
                    }
                }

                if ($bill_status && $bill_price && $bill_payment_date) {
                    break;
                }
            }

            log_payment_info("Извлечены поля счета из вебхука", [
                'catalog_element_id' => $catalog_element_id,
                'catalog_id' => $catalog_id,
                'bill_status' => $bill_status,
                'bill_price' => $bill_price,
                'bill_payment_date' => $bill_payment_date,
            ]);

            // Проверяем, что счет оплачен или является возвратом
            $status_lower = mb_strtolower(trim($bill_status ?? ''));
            $is_paid   = str_contains($status_lower, 'оплачен') && !str_contains($status_lower, 'не оплачен');
            $is_refund = str_contains($status_lower, 'возврат');
            if (!$is_paid && !$is_refund) {
                log_payment_info("Счет не оплачен, пропускаем обработку", ['bill_status' => $bill_status]);
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Счет не оплачен. Обработка будет выполнена при оплате.',
                    'catalog_element_id' => $catalog_element_id,
                    'bill_status' => $bill_status
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!$bill_price || !is_numeric($bill_price)) {
                throw new Exception("Не удалось извлечь сумму оплаты из счета");
            }

            // Получаем данные счета через API
            $api_url = '/api/v4/catalogs/' . $catalog_id . '/elements/' . $catalog_element_id;
            $CATALOG_ELEMENT = get($subdomain, $api_url, $data);

            // Единственный путь поиска: через официальный API связей amoCRM.
            $lead_id_from_catalog = null;

            try {
                $lead_search_result = find_lead_by_catalog_link($subdomain, $data, $catalog_element_id, $catalog_id, $CATALOG_ELEMENT);

                if ($lead_search_result) {
                    $lead_id_from_catalog = (int) ($lead_search_result['lead_id'] ?? 0);
                    log_payment_info("Сделка найдена", [
                        'lead_id' => $lead_id_from_catalog,
                        'search_strategy' => $lead_search_result['strategy'] ?? null,
                        'checked_leads' => $lead_search_result['checked_leads'] ?? null,
                        'source_url' => $lead_search_result['source_url'] ?? null,
                        'total_checked' => $lead_search_result['total_checked'] ?? null,
                        'total_processed' => $lead_search_result['total_processed'] ?? null,
                    ]);
                }
            } catch (Exception $mass_api_e) {
                log_payment_warning("Ошибка при использовании API links", [
                    'catalog_element_id' => $catalog_element_id,
                    'catalog_id' => $catalog_id,
                    'error' => $mass_api_e->getMessage()
                ]);
            }

            if (!$lead_id_from_catalog) {
                log_payment_error("Не удалось найти связанную сделку для счета", [
                    'catalog_element_id' => $catalog_element_id,
                    'catalog_id' => $catalog_id
                ]);
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Не удалось найти связанную сделку для счета.',
                    'catalog_element_id' => $catalog_element_id,
                    'catalog_id' => $catalog_id
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Извлекаем ссылку на оплату из API ответа если не было в вебхуке
            if (!$payment_link && isset($CATALOG_ELEMENT['custom_fields_values'])) {
                foreach ($CATALOG_ELEMENT['custom_fields_values'] as $field) {
                    $field_code = $field['code'] ?? null;
                    $field_id   = $field['field_id'] ?? null;
                    if ($field_code === 'INVOICE_HASH_LINK' || $field_id == 1622603 || $field_id == 1630781) {
                        $field_values = $field['values'] ?? [];
                        if (!empty($field_values)) {
                            $payment_link_raw = is_array($field_values[0]) ? ($field_values[0]['value'] ?? null) : ($field_values[0] ?? null);
                            $payment_link = $payment_link_raw ? trim($payment_link_raw) : null;
                            if ($payment_link === '') $payment_link = null;
                        }
                        break;
                    }
                }
            }

            $amount = (float) $bill_price;
            if (is_array($bill_payment_date)) {
                $payment_date = !empty($bill_payment_date) ? (int) $bill_payment_date[0] : time();
            } else {
                $payment_date = $bill_payment_date ? (int) $bill_payment_date : time();
            }
            $lead_id  = $lead_id_from_catalog;
            $date_iso = date('c', $payment_date);

        } catch (Exception $e) {
            log_payment_error("✗ Ошибка при обработке счета", [
                'catalog_element_id' => $catalog_element_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка при обработке счета: ' . $e->getMessage()
            ]);
            exit;
        }

        if (isset($lead_id) && $lead_id) {
            goto get_lead_data;
        }
    }

    // =========================================================================
    // ОБРАБОТКА ВЕБХУКА ОТ СДЕЛКИ (поиск транзакции)
    // =========================================================================
    if ($lead_id_from_webhook && !$transaction_id) {
        log_payment_info("Получен вебхук от сделки, ищем транзакции в сделке", [
            'lead_id' => $lead_id_from_webhook
        ]);

        try {
            $api_url = '/api/v4/leads/' . $lead_id_from_webhook . '?with=transactions';
            $LEAD_WITH_TRANSACTIONS = get($subdomain, $api_url, $data);

            if (
                isset($LEAD_WITH_TRANSACTIONS['_embedded']['transactions']) &&
                is_array($LEAD_WITH_TRANSACTIONS['_embedded']['transactions']) &&
                !empty($LEAD_WITH_TRANSACTIONS['_embedded']['transactions'])
            ) {
                $transactions     = $LEAD_WITH_TRANSACTIONS['_embedded']['transactions'];
                $last_transaction = end($transactions);
                $transaction_id   = $last_transaction['id'] ?? null;

                if ($transaction_id) {
                    log_payment_info("✓ Найдена транзакция в сделке", [
                        'lead_id'        => $lead_id_from_webhook,
                        'transaction_id' => $transaction_id
                    ]);
                }
            } else {
                $api_url = '/api/v4/leads/' . $lead_id_from_webhook . '/transactions';
                $TRANSACTIONS_RESPONSE = get($subdomain, $api_url, $data);

                if (
                    isset($TRANSACTIONS_RESPONSE['_embedded']['transactions']) &&
                    !empty($TRANSACTIONS_RESPONSE['_embedded']['transactions'])
                ) {
                    $transactions     = $TRANSACTIONS_RESPONSE['_embedded']['transactions'];
                    $last_transaction = end($transactions);
                    $transaction_id   = $last_transaction['id'] ?? null;
                }
            }

            if (!$transaction_id) {
                log_payment_info("В сделке не найдено транзакций — пропускаем", ['lead_id' => $lead_id_from_webhook]);
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'В сделке нет транзакций. Обработка будет выполнена при создании транзакции.',
                    'lead_id' => $lead_id_from_webhook
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (Exception $e) {
            log_payment_error("✗ Ошибка при поиске транзакций в сделке", [
                'lead_id' => $lead_id_from_webhook,
                'error'   => $e->getMessage()
            ]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // =========================================================================
    // ОБРАБОТКА ТРАНЗАКЦИИ
    // =========================================================================
    $api_url = '/api/v4/transactions/' . $transaction_id;
    try {
        $TRANSACTION = get($subdomain, $api_url, $data);
    } catch (Exception $e) {
        log_payment_error("✗ Ошибка при получении данных транзакции", [
            'transaction_id' => $transaction_id,
            'error'          => $e->getMessage()
        ]);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Ошибка при получении данных транзакции: ' . $e->getMessage()]);
        exit;
    }

    $lead_id          = $TRANSACTION['lead_id'] ?? null;
    $amount           = $TRANSACTION['price'] ?? $TRANSACTION['value'] ?? null;
    $transaction_date = $TRANSACTION['created_at'] ?? $TRANSACTION['date'] ?? time();

    if (!$lead_id) {
        log_payment_error("✗ Транзакция не содержит lead_id", ['transaction_id' => $transaction_id]);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Транзакция не связана со сделкой']);
        exit;
    }

    if (!$amount || !is_numeric($amount)) {
        log_payment_error("Транзакция не содержит корректную сумму", ['transaction_id' => $transaction_id]);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Транзакция не содержит корректную сумму оплаты']);
        exit;
    }

    if (is_numeric($transaction_date)) {
        $date_iso = date('c', $transaction_date);
    } else {
        $date_timestamp = strtotime($transaction_date);
        $date_iso = $date_timestamp !== false ? date('c', $date_timestamp) : date('c');
    }

    // =========================================================================
    // ПОЛУЧЕНИЕ ДАННЫХ СДЕЛКИ
    // =========================================================================
    get_lead_data:
    $api_url = '/api/v4/leads/' . $lead_id;
    try {
        $LEAD = get($subdomain, $api_url, $data);
    } catch (Exception $e) {
        log_payment_error("✗ Ошибка при получении данных сделки", [
            'lead_id' => $lead_id,
            'error'   => $e->getMessage()
        ]);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Ошибка при получении данных сделки: ' . $e->getMessage()]);
        exit;
    }

    // =========================================================================
    // ПОИСК clientId СТУДЕНТА
    // =========================================================================
    $client_id           = null;
    $profile_link        = null;
    $resolved_profile_id = null;
    $custom_fields_values = $LEAD['custom_fields_values'] ?? [];

    // Шаг 1: ищем ссылку на профиль в кастомных полях сделки
    foreach ($custom_fields_values as $field) {
        $field_id    = $field['field_id'] ?? null;
        $field_value = $field['values'][0]['value'] ?? null;

        if ($field_id == AMO_FIELD_PROFILE_LINK && !empty($field_value)) {
            $profile_link = trim((string) $field_value);
            if (preg_match('/\/Profile\/(\d+)/', $profile_link, $matches)) {
                $resolved_profile_id = (int) $matches[1];
            }
        }
    }

    // Шаг 2: если есть ссылка на профиль — получаем clientId через GetStudents
    if (!$client_id && $profile_link && $resolved_profile_id) {
        try {
            $api_response = call_hollyhop_api('GetStudents', ['Id' => $resolved_profile_id], $auth_key, $api_base_url);

            $student_info = null;
            if (is_array($api_response)) {
                if (isset($api_response['ClientId']) || isset($api_response['clientId'])) {
                    $student_info = $api_response;
                } elseif (isset($api_response['Students']) && is_array($api_response['Students'])) {
                    foreach ($api_response['Students'] as $student) {
                        if (is_array($student) && (($student['Id'] ?? $student['id'] ?? null) == $resolved_profile_id)) {
                            $student_info = $student;
                            break;
                        }
                    }
                }
            }

            if ($student_info) {
                $client_id = $student_info['ClientId'] ?? $student_info['clientId'] ?? null;
                if ($client_id) {
                    log_payment_info("clientId получен через GetStudents по profile_id", [
                        'profile_id' => $resolved_profile_id,
                        'clientId'   => $client_id
                    ]);
                }
            }
        } catch (Exception $e) {
            log_payment_error("✗ Ошибка при получении clientId через GetStudents", [
                'profile_id' => $resolved_profile_id,
                'error'      => $e->getMessage()
            ]);
        }
    }

    // Шаг 3: если clientId не найден — вызываем index.php
    // index.php содержит полную логику: поиск по телефону, создание студента,
    // маппинг полей, запись ссылки на профиль в сделку AmoCRM.
    if (!$client_id) {
        log_payment_info("clientId не найден, вызываем index.php для создания/поиска студента", [
            'lead_id'      => $lead_id,
            'profile_link' => $profile_link
        ]);

        try {
            $index_result = call_index_for_student($lead_id, $LEAD, $subdomain, $data, $auth_key, $api_base_url);
            $client_id           = $index_result['client_id'] ?? null;
            $resolved_profile_id = $index_result['profile_id'] ?? $resolved_profile_id;
            if (!empty($index_result['profile_link'])) {
                $profile_link = $index_result['profile_link'];
            }

            if ($client_id) {
                log_payment_info("clientId получен через index.php", [
                    'lead_id'  => $lead_id,
                    'clientId' => $client_id
                ]);
            }
        } catch (Exception $index_e) {
            log_payment_warning("Не удалось получить clientId через index.php", [
                'lead_id' => $lead_id,
                'error'   => $index_e->getMessage()
            ]);
        }
    }

    // Если clientId всё ещё не найден — возвращаем ошибку
    if (!$client_id) {
        log_payment_error("✗✗✗ Не удалось найти clientId ни одним из способов", [
            'lead_id'      => $lead_id,
            'profile_link' => $profile_link
        ]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Не удалось найти clientId студента. Проверьте что в сделке есть контакт с телефоном/email и что студент может быть найден или создан в Hollyhop.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    log_payment_info("clientId найден", [
        'lead_id'  => $lead_id,
        'clientId' => $client_id
    ]);

    // =========================================================================
    // ПОЛУЧЕНИЕ officeOrCompanyId СТУДЕНТА
    // =========================================================================
    $office_id    = null;
    $student_info = null;

    try {
        $student_lookup_params = $resolved_profile_id
            ? ['Id' => $resolved_profile_id]
            : ['clientId' => $client_id];

        $api_response = call_hollyhop_api('GetStudents', $student_lookup_params, $auth_key, $api_base_url);

        if (is_array($api_response)) {
            if (isset($api_response['Students']) && is_array($api_response['Students'])) {
                foreach ($api_response['Students'] as $student) {
                    $matches_client_id = ($student['ClientId'] ?? $student['clientId'] ?? null) == $client_id;
                    $matches_profile_id = $resolved_profile_id && (($student['Id'] ?? $student['id'] ?? null) == $resolved_profile_id);

                    if (is_array($student) && ($matches_client_id || $matches_profile_id)) {
                        $student_info = $student;
                        break;
                    }
                }
            } elseif (isset($api_response['ClientId']) || isset($api_response['clientId'])) {
                $student_info = $api_response;
            } else {
                foreach ($api_response as $student) {
                    $matches_client_id = ($student['ClientId'] ?? $student['clientId'] ?? null) == $client_id;
                    $matches_profile_id = $resolved_profile_id && (($student['Id'] ?? $student['id'] ?? null) == $resolved_profile_id);

                    if (is_array($student) && ($matches_client_id || $matches_profile_id)) {
                        $student_info = $student;
                        break;
                    }
                }
            }
        }

        if ($student_info === null) {
            throw new Exception("Студент с clientId = {$client_id} не найден в системе Hollyhop");
        }

        $resolved_profile_id = $resolved_profile_id ?: extract_student_profile_id($student_info);

        $office_id = $student_info['OfficeOrCompanyId'] ??
            $student_info['officeOrCompanyId'] ??
            $student_info['OfficeOrCompany'] ??
            $student_info['officeOrCompany'] ?? null;

        if ($office_id === null && isset($student_info['OfficesAndCompanies']) && is_array($student_info['OfficesAndCompanies'])) {
            $first_office = $student_info['OfficesAndCompanies'][0] ?? null;
            if (is_array($first_office)) {
                $office_id = $first_office['Id'] ??
                    $first_office['id'] ??
                    $first_office['OfficeOrCompanyId'] ??
                    $first_office['officeOrCompanyId'] ?? null;
            }
        }

        if ($office_id === null) {
            log_payment_warning("Не удалось получить officeOrCompanyId для студента с clientId = {$client_id}. Используем филиал по умолчанию (36).", [
                'clientId' => $client_id
            ]);
            $office_id = 36; // Филиал по умолчанию
        }

        log_payment_info("Определен филиал ученика для платежа", [
            'clientId' => $client_id,
            'profileId' => $resolved_profile_id,
            'student_lookup_params' => $student_lookup_params,
            'officeId' => $office_id,
            'offices' => $student_info['OfficesAndCompanies'] ?? null,
        ]);
    } catch (Exception $e) {
        if (!isset($office_id) || $office_id === null) {
            log_payment_error("✗ Ошибка при получении officeOrCompanyId", [
                'clientId' => $client_id,
                'error'    => $e->getMessage()
            ]);
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Восстанавливаем ссылку на профиль в сделке если её нет
    if (!$profile_link && $resolved_profile_id) {
        $hollyhop_subdomain = $api_config['subdomain'] ?? null;
        if (!empty($hollyhop_subdomain)) {
            $profile_link = build_hollyhop_profile_link($resolved_profile_id, $hollyhop_subdomain);
            try {
                update_lead_profile_link_in_amocrm($lead_id, $profile_link, $subdomain, $data);
                log_payment_info("Ссылка на профиль восстановлена в сделке", [
                    'lead_id'      => $lead_id,
                    'profile_link' => $profile_link
                ]);
            } catch (Exception $e) {
                log_payment_warning("Не удалось сохранить ссылку на профиль", [
                    'lead_id' => $lead_id,
                    'error'   => $e->getMessage()
                ]);
            }
        }
    }

    // =========================================================================
    // ОПРЕДЕЛЕНИЕ TYPE ПЛАТЕЖА ПО ВОРОНКЕ
    // =========================================================================
    $payment_type = null;
    $pipeline_id = isset($LEAD['pipeline_id']) ? (int) $LEAD['pipeline_id'] : null;
    $pipeline_name = fetch_pipeline_name_by_id($subdomain, $data, $pipeline_id);
    $payment_type = resolve_payment_type_by_pipeline($pipeline_id, $pipeline_name);
    $use_studyridge_acquiring = has_non_empty_amo_field($custom_fields_values, 1638129);

    if ($payment_type) {
        log_payment_info("Определен type платежа по воронке", [
            'pipeline_id' => $pipeline_id,
            'pipeline_name' => $pipeline_name,
            'payment_type' => $payment_type,
        ]);
    } else {
        log_payment_warning("Не удалось определить type платежа по воронке", [
            'pipeline_id' => $pipeline_id,
            'pipeline_name' => $pipeline_name,
        ]);
    }

    // =========================================================================
    // ОБРАБОТКА ВОЗВРАТА: создаем отдельный платеж возврата в Hollyhop
    // =========================================================================
    if ($is_refund ?? false) {
        log_payment_info("Обработка возврата: готовим отдельный платеж возврата в Hollyhop", [
            'clientId' => $client_id,
            'amount'   => $amount,
        ]);

        $payments_result = call_hollyhop_api('GetPayments', [
            'clientId' => $client_id,
        ], $auth_key, $api_base_url);

        $existing_payments = $payments_result['Items'] ?? $payments_result['Payments'] ?? $payments_result['items'] ?? [];

        // Ищем последний Paid-платёж с совпадающей суммой
        $target_payment = null;
        foreach (array_reverse($existing_payments) as $pay) {
            $pay_state = $pay['State'] ?? $pay['state'] ?? null;
            $pay_value = (float)($pay['Value'] ?? $pay['value'] ?? 0);
            if ($pay_state === 'Paid' && abs($pay_value - (float)$amount) < 0.01) {
                $target_payment = $pay;
                break;
            }
        }

        // Если по сумме не нашли — берём просто последний Paid
        if (!$target_payment) {
            foreach (array_reverse($existing_payments) as $pay) {
                $pay_state = $pay['State'] ?? $pay['state'] ?? null;
                if ($pay_state === 'Paid') {
                    $target_payment = $pay;
                    break;
                }
            }
        }

        if (!$target_payment) {
            log_payment_warning("Возврат: исходный оплаченный платёж не найден, создаем возврат без привязки к исходному paymentId", [
                'clientId'       => $client_id,
                'payments_count' => count($existing_payments),
            ]);
        }

        $source_payment_id = $target_payment['Id'] ?? $target_payment['id'] ?? null;
        if ($source_payment_id) {
            log_payment_info("Возврат: найден исходный платёж", [
                'paymentId' => $source_payment_id,
                'value'     => $target_payment['Value'] ?? $target_payment['value'] ?? null,
                'state'     => $target_payment['State'] ?? $target_payment['state'] ?? null,
            ]);
        }

        $refund_payment_method_id = $target_payment['PaymentMethodId'] ?? $payment_method_id ?? (!empty($payment_link) ? 23 : 19);
        $refund_description_parts = ['Возврат'];

        if (empty($hollyhop_subdomain)) {
            throw new Exception('Не задан subdomain Hollyhop для создания возврата через web-форму.');
        }

        if ($source_payment_id) {
            $refund_description_parts[] = 'по платежу #' . $source_payment_id;
        }

        $refund_description = implode(' ', $refund_description_parts);
        $refund_result = create_hollyhop_refund_via_web_form(
            $hollyhop_subdomain,
            $auth_key,
            (int) $client_id,
            (int) $office_id,
            (float) $amount,
            (int) $refund_payment_method_id,
            $refund_description,
            $date_iso
        );

        log_payment_info("✓ Возврат: платеж успешно создан через внутреннюю web-форму Hollyhop", [
            'clientId'  => $client_id,
            'sourcePaymentId' => $source_payment_id,
            'paymentMethodId' => $refund_payment_method_id,
            'billNumber' => $refund_result['billNumber'] ?? null,
        ]);

        http_response_code(200);
        echo json_encode([
            'success'      => true,
            'message'      => 'Возврат: платеж успешно создан через внутреннюю web-форму Hollyhop',
            'sourcePaymentId' => $source_payment_id,
            'billNumber'   => $refund_result['billNumber'] ?? null,
            'api_response' => $refund_result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // =========================================================================
    // ФОРМИРОВАНИЕ И ОТПРАВКА ОПЛАТЫ
    // =========================================================================

    $payment_state = 'Paid';
    $payment_method_id = $use_studyridge_acquiring
        ? 25
        : (!empty($payment_link) ? 23 : 19);

    log_payment_info("Формирование параметров для AddPayment", [
        'clientId'           => $client_id,
        'officeOrCompanyId'  => $office_id,
        'amount'             => $amount,
        'date_iso'           => $date_iso,
        'payment_method_id'  => $payment_method_id,
        'payment_method_name' => $payment_method_id === 25
            ? 'ОП. ООО Стадиридж эквайринг (25)'
            : ($payment_method_id === 23 ? 'ОП. Эквайринг. Тбанк (23)' : 'ОП. ООО ЦПВ "Евразия". безналичные (19)'),
        'payment_state'      => $payment_state,
        'payment_type'       => $payment_type,
        'pipeline_id'        => $pipeline_id,
        'field_1638129_filled' => $use_studyridge_acquiring,
    ]);

    $payment_params = [
        'clientId'          => $client_id,
        'officeOrCompanyId' => $office_id,
        'date'              => $date_iso,
        'value'             => (float) $amount,
        'state'             => $payment_state,
        'paymentMethodId'   => $payment_method_id
    ];

    if ($payment_type) {
        $payment_params['type'] = $payment_type;
    }

    $result = call_hollyhop_api('AddPayment', $payment_params, $auth_key, $api_base_url);

    log_payment_info("✓ Оплата успешно записана", [
        'clientId'          => $client_id,
        'amount'            => $amount,
        'paymentMethodId'   => $payment_method_id
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Оплата успешно записана',
        'payment' => [
            'clientId'          => $client_id,
            'officeOrCompanyId' => $office_id,
            'date'              => $date_iso,
            'value'             => (float) $amount
        ],
        'api_response' => $result
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    $error_message = $e->getMessage();
    log_payment_error("Ошибка обработки платежа", [
        'error_message' => $error_message,
        'error_file'    => $e->getFile(),
        'error_line'    => $e->getLine()
    ]);
    echo json_encode([
        'success' => false,
        'error'   => $error_message
    ], JSON_UNESCAPED_UNICODE);
}
