<?php

/**
 * Главный обработчик вебхуков от AmoCRM и OkiDoki
 * 
 * Обрабатывает два типа событий:
 * 1. Подписание договора через OkiDoki (JSON webhook)
 * 2. События сделок AmoCRM (создание/изменение статуса)
 */

declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/amo_func.php';

// ============================================================================
// КОНСТАНТЫ
// ============================================================================

const AMO_FIELD_DISCIPLINE = 1575217;
const AMO_FIELD_LEVEL = 1576357;
const AMO_FIELD_LEARNING_TYPE = 1575221;
const AMO_FIELD_MATURITY = 1575213;
const AMO_FIELD_OFFICE_OR_COMPANY = 1596219;
const AMO_FIELD_RESPONSIBLE_USER = 1590693;
const AMO_FIELD_PROFILE_LINK = 1630807;

const AMO_CONTACT_FIELD_PHONE = 1138327;
const AMO_CONTACT_FIELD_EMAIL = 1138329;

const HOLLYHOP_TIMEOUT = 120;
const HOLLYHOP_CONNECT_TIMEOUT = 15;
const HOLLYHOP_API_TIMEOUT = 60;

const AMO_DEALS_FIELD_NAME = 'Сделки АМО';

// ============================================================================
// ОБРАБОТКА ВЕБХУКА ОТ OKIDOKI
// ============================================================================

$rawInput = file_get_contents('php://input');
$okiData = json_decode($rawInput, true);

if (isOkiDokiSignedContract($okiData)) {
    handleOkiDokiSignedContract($okiData);
    exit('OK');
}

// ============================================================================
// ОБРАБОТКА ВЕБХУКА ОТ AMOCRM
// ============================================================================

log_info("Вебхук от AmoCRM получен", $_POST, 'index.php');

$leadId = extractLeadIdFromWebhook($_POST);

if ($leadId === null) {
    log_warning("Вебхук получен, но не удалось определить lead_id", $_POST, 'index.php');
    exit;
}

try {
    processAmoCrmLead($leadId);
} catch (Exception $e) {
    log_error("Критическая ошибка при обработке сделки", [
        'lead_id' => $leadId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'index.php');
    die("Ошибка: " . $e->getMessage());
}

// ============================================================================
// ФУНКЦИИ ОБРАБОТКИ OKIDOKI
// ============================================================================

/**
 * Проверяет, является ли вебхук событием подписания договора OkiDoki
 */
function isOkiDokiSignedContract(?array $data): bool
{
    return isset($data['status']) && $data['status'] === 'signed';
}

/**
 * Обрабатывает событие подписания договора OkiDoki
 */
function handleOkiDokiSignedContract(array $okiData): void
{
    global $subdomain, $data;

    $leadId = $okiData['lead_id'] ?? null;
    $fio = $okiData['extra_fields']['ФИО клиента'] ?? '';
    $email = $okiData['extra_fields']['E-Mail клиента'] ?? '';

    if (!$leadId) {
        log_warning("OkiDoki вебхук без lead_id", $okiData, 'index.php');
        return;
    }

    log_info("Договор подписан! ФИО: $fio, Email: $email", $okiData, 'index.php');

    $contactId = getContactIdFromLead($leadId);

    if ($contactId !== null) {
        updateContactInfo($contactId, $fio, $email);
    }
}

/**
 * Получает ID контакта из сделки
 */
function getContactIdFromLead(int $leadId): ?int
{
    global $subdomain, $data;

    try {
        $leadInfo = get($subdomain, "/api/v4/leads/$leadId?with=contacts", $data);
        return $leadInfo['_embedded']['contacts'][0]['id'] ?? null;
    } catch (Exception $e) {
        log_error("Ошибка при получении контакта из сделки", [
            'lead_id' => $leadId,
            'error' => $e->getMessage()
        ], 'index.php');
        return null;
    }
}

/**
 * Обновляет информацию о контакте
 */
function updateContactInfo(int $contactId, string $name, string $email): void
{
    global $subdomain, $data;

    $contactUpdate = [
        'name' => $name,
        'custom_fields_values' => [
            [
                'field_code' => 'EMAIL',
                'values' => [['value' => $email, 'enum_code' => 'WORK']]
            ]
        ]
    ];

    try {
        post_or_patch($subdomain, $contactUpdate, "/api/v4/contacts/$contactId", $data, 'PATCH');
        log_info("Контакт обновлен", ['contact_id' => $contactId, 'name' => $name], 'index.php');
    } catch (Exception $e) {
        log_error("Ошибка при обновлении контакта", [
            'contact_id' => $contactId,
            'error' => $e->getMessage()
        ], 'index.php');
    }
}

// ============================================================================
// ФУНКЦИИ ОБРАБОТКИ AMOCRM
// ============================================================================

/**
 * Извлекает ID сделки из вебхука AmoCRM
 */
function extractLeadIdFromWebhook(array $post): ?int
{
    if (isset($post["leads"]["add"][0]["id"])) {
        $leadId = (int) $post["leads"]["add"][0]["id"];
        log_info("Обработка события: создание новой сделки", ['lead_id' => $leadId], 'index.php');
        return $leadId;
    }

    if (isset($post["leads"]["status"][0]["id"])) {
        $leadId = (int) $post["leads"]["status"][0]["id"];
        log_info("Обработка события: изменение статуса сделки", ['lead_id' => $leadId], 'index.php');
        return $leadId;
    }

    return null;
}

/**
 * Основная функция обработки сделки AmoCRM
 */
function processAmoCrmLead(int $leadId): void
{
    $lead = fetchLeadData($leadId);

    if (!hasRequiredLeadData($lead)) {
        log_warning("Сделка не содержит достаточно данных для обработки", [
            'lead_id' => $leadId
        ], 'index.php');
        return;
    }

    $studentData = buildStudentDataFromLead($lead, $leadId);

    if (!isset($studentData["firstName"])) {
        log_warning("Имя студента не указано, пропуск отправки", [
            'json' => $studentData,
            'lead_id' => $leadId
        ], 'index.php');
        return;
    }

    $hollyhopResponse = sendStudentToHollyhop($studentData);

    if ($hollyhopResponse !== null) {
        processHollyhopResponse($hollyhopResponse, $leadId, $lead);
    }
}

/**
 * Получает данные сделки из AmoCRM
 */
function fetchLeadData(int $leadId): array
{
    global $subdomain, $data;

    $apiUrl = "/api/v4/leads/{$leadId}?with=contacts";

    try {
        $lead = get($subdomain, $apiUrl, $data);
        log_info("Данные сделки получены из AmoCRM", ['lead_id' => $leadId], 'index.php');
        return $lead;
    } catch (Exception $e) {
        log_error("Ошибка при получении данных сделки из AmoCRM", [
            'lead_id' => $leadId,
            'error' => $e->getMessage()
        ], 'index.php');
        throw $e;
    }
}

/**
 * Проверяет наличие необходимых данных в сделке
 */
function hasRequiredLeadData(array $lead): bool
{
    $customFieldsValues = $lead["custom_fields_values"] ?? [];

    if (empty($customFieldsValues) || !is_array($customFieldsValues)) {
        log_warning("Сделка не содержит кастомных полей или они пусты", [
            'lead_id' => $lead['id'] ?? 'unknown',
            'has_custom_fields' => isset($lead["custom_fields_values"]),
            'custom_fields_is_null' => ($lead["custom_fields_values"] ?? null) === null,
            'custom_fields_type' => gettype($lead["custom_fields_values"] ?? null)
        ], 'index.php');
        return false;
    }

    return true;
}

/**
 * Формирует данные студента из данных сделки и контакта
 */
function buildStudentDataFromLead(array $lead, int $leadId): array
{
    global $subdomain;

    $studentData = [
        'Status' => 'В наборе',
        'link' => "https://{$subdomain}.amocrm.ru/leads/detail/{$leadId}",
        'gender' => 'F',
        'amo_lead_id' => $leadId,
        'amo_subdomain' => $subdomain,

        // 🔥 ВАЖНО ДЛЯ КОНВЕРТОВ
        'useMobileBySystem' => false,
        'useEMailBySystem'  => false
    ];

    // Извлекаем кастомные поля сделки
    $customFieldsValues = $lead["custom_fields_values"] ?? [];
    $studentData = array_merge($studentData, extractLeadCustomFields($customFieldsValues));

    // Извлекаем данные контакта
    $contactId = $lead["_embedded"]["contacts"][0]["id"] ?? null;
    if ($contactId !== null) {
        $contactData = extractContactData($contactId);
        $studentData = array_merge($studentData, $contactData);
    }

    return $studentData;
}

/**
 * Извлекает кастомные поля сделки
 */
function extractLeadCustomFields(array $customFieldsValues): array
{
    $fields = [];

    foreach ($customFieldsValues as $field) {
        $fieldId = $field["field_id"] ?? null;
        $value = $field["values"][0]["value"] ?? null;

        if ($fieldId === null || $value === null) {
            continue;
        }

        switch ($fieldId) {
            case AMO_FIELD_DISCIPLINE:
                $fields["discipline"] = $value;
                break;
            case AMO_FIELD_LEVEL:
                $fields["level"] = $value;
                break;
            case AMO_FIELD_LEARNING_TYPE:
                $fields["learningType"] = $value;
                break;
            case AMO_FIELD_MATURITY:
                $fields["maturity"] = $value;
                break;
            case AMO_FIELD_OFFICE_OR_COMPANY:
                $fields["officeOrCompanyId"] = $value;
                break;
            case AMO_FIELD_RESPONSIBLE_USER:
                $fields["responsible_user"] = $value;
                break;
        }
    }

    return $fields;
}

/**
 * Извлекает данные контакта
 */
function extractContactData(int $contactId): array
{
    global $subdomain, $data;

    try {
        $contact = get($subdomain, "/api/v4/contacts/{$contactId}", $data);

        $contactData = [];

        // Разбираем имя на части
        $nameParts = explode(" ", $contact["name"] ?? "");
        if (isset($nameParts[0])) {
            $contactData["firstName"] = $nameParts[0];
        }
        if (isset($nameParts[1])) {
            $contactData["lastName"] = $nameParts[1];
        }

        // Извлекаем кастомные поля контакта
        $customFieldsValues = $contact["custom_fields_values"] ?? [];
        if (is_array($customFieldsValues)) {
            foreach ($customFieldsValues as $field) {
                $fieldId = $field["field_id"] ?? null;
                $value = $field["values"][0]["value"] ?? null;

                if ($fieldId === AMO_CONTACT_FIELD_PHONE && $value !== null) {
                    $contactData["phone"] = $value;
                }
                if ($fieldId === AMO_CONTACT_FIELD_EMAIL && $value !== null) {
                    $contactData["email"] = $value;
                }
            }
        }

        return $contactData;
    } catch (Exception $e) {
        log_error("Ошибка при получении данных контакта", [
            'contact_id' => $contactId,
            'error' => $e->getMessage()
        ], 'index.php');
        return [];
    }
}

// ============================================================================
// ФУНКЦИИ РАБОТЫ С HOLLYHOP
// ============================================================================

/**
 * Отправляет данные студента в Hollyhop
 */
function sendStudentToHollyhop(array $studentData): ?array
{
    $jsonData = json_encode($studentData, JSON_UNESCAPED_UNICODE);
    log_info("Данные подготовлены для отправки в Hollyhop", $studentData, 'index.php');

    echo $jsonData . "<br><br>";

    $url = 'https://srm.chinatutor.ru/add_student.php';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => HOLLYHOP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => HOLLYHOP_CONNECT_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $startTime = microtime(true);
    log_info("Начало вызова add_student.php", ['url' => $url, 'timeout' => HOLLYHOP_TIMEOUT], 'index.php');

    $response = curl_exec($ch);
    $executionTime = round(microtime(true) - $startTime, 2);

    log_info("Завершение вызова add_student.php", ['execution_time_seconds' => $executionTime], 'index.php');

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $curlError = curl_error($ch);
        log_error("Ошибка cURL при отправке данных в Hollyhop", [
            'error' => $curlError,
            'url' => $url
        ], 'index.php');
        echo 'Ошибка cURL: ' . $curlError . "\n";
        curl_close($ch);
        return null;
    }

    log_info("Ответ от Hollyhop API получен", [
        'http_code' => $httpCode,
        'response' => $response
    ], 'index.php');

    echo "HTTP-код: $httpCode\n";
    echo "Ответ сервера: " . ($response ?: 'пустой ответ') . "\n";

    curl_close($ch);

    $result = json_decode(trim($response), true);

    if ($result === null) {
        log_warning("Не удалось декодировать ответ от Hollyhop", [
            'response' => $response
        ], 'index.php');
        return null;
    }

    return $result;
}

/**
 * Обрабатывает ответ от Hollyhop
 */
function processHollyhopResponse(array $response, int $leadId, array $lead): void
{
    $profileLink = $response["link"] ?? null;

    if ($profileLink === null) {
        log_warning("Ссылка на профиль не получена в ответе", [
            'response' => $response,
            'lead_id' => $leadId
        ], 'index.php');
        return;
    }

    log_info("Ссылка на профиль студента получена", [
        'link' => $profileLink,
        'lead_id' => $leadId
    ], 'index.php');

    updateLeadProfileLink($leadId, $profileLink);

    $clientId = $response["clientId"] ?? null;
    if ($clientId !== null) {
        updateHollyhopAmoDeal($clientId, $leadId, $lead);
    } else {
        log_warning("clientId не найден в ответе от add_student.php", [
            'response' => $response
        ], 'index.php');
    }
}

/**
 * Обновляет ссылку на профиль в AmoCRM
 */
function updateLeadProfileLink(int $leadId, string $profileLink): void
{
    global $subdomain, $data;

    $leadsData = [
        'id' => $leadId,
        'custom_fields_values' => [
            [
                'field_id' => AMO_FIELD_PROFILE_LINK,
                'values' => [
                    ['value' => $profileLink]
                ]
            ]
        ]
    ];

    try {
        $amoRes = post_or_patch(
            $subdomain,
            $leadsData,
            "/api/v4/leads/{$leadId}",
            $data,
            'PATCH'
        );

        log_info("Сделка в AmoCRM обновлена ссылкой на профиль", [
            'lead_id' => $leadId,
            'link' => $profileLink
        ], 'index.php');

        echo print_r($amoRes, true);
    } catch (Exception $e) {
        log_error("Ошибка при обновлении сделки в AmoCRM", [
            'lead_id' => $leadId,
            'error' => $e->getMessage()
        ], 'index.php');
        echo "Ошибка обновления: " . $e->getMessage();
    }
}

/**
 * Обновляет поле "Сделки АМО" в Hollyhop
 */
function updateHollyhopAmoDeal(int $clientId, int $leadId, array $lead): void
{
    global $subdomain;

    $managerName = getManagerNameFromLead($lead);
    $amoDealUrl = "https://{$subdomain}.amocrm.ru/leads/detail/{$leadId}";
    $amoDealLink = buildHtmlLink($amoDealUrl, "{$managerName}: {$leadId}");

    try {
        $apiConfig = get_config('api');
        $authKey = $apiConfig['auth_key'];
        $apiBaseUrl = $apiConfig['base_url'];

        $student = fetchStudentFromHollyhop($clientId, $authKey, $apiBaseUrl);

        if ($student === null) {
            log_warning("Студент не найден в Hollyhop для обновления поля 'Сделки АМО'", [
                'clientId' => $clientId
            ], 'index.php');
            return;
        }

        $allExtraFields = extractAllExtraFields($student, $amoDealLink);
        updateStudentExtraFields($clientId, $allExtraFields, $authKey, $apiBaseUrl, $leadId, $amoDealLink);
    } catch (Exception $e) {
        log_error("Ошибка при обновлении поля 'Сделки АМО' в Hollyhop", [
            'error' => $e->getMessage(),
            'clientId' => $clientId,
            'lead_id' => $leadId
        ], 'index.php');
    }
}

/**
 * Получает имя менеджера из данных сделки
 */
function getManagerNameFromLead(array $lead): string
{
    global $subdomain, $data;

    $responsibleUserId = $lead["responsible_user_id"] ?? null;

    if ($responsibleUserId === null) {
        log_warning("У сделки не указан ответственный менеджер", [
            'lead_id' => $lead['id'] ?? 'unknown'
        ], 'index.php');
        return 'Неизвестно';
    }

    try {
        $user = get($subdomain, "/api/v4/users/{$responsibleUserId}", $data);

        $managerName = extractManagerNameFromResponse($user);

        log_info("Имя менеджера получено из AmoCRM", [
            'responsible_user_id' => $responsibleUserId,
            'manager_name' => $managerName,
            'user_response_structure' => is_array($user) ? 'array' : 'object'
        ], 'index.php');

        return $managerName;
    } catch (Exception $e) {
        log_warning("Не удалось получить имя менеджера из AmoCRM", [
            'responsible_user_id' => $responsibleUserId,
            'error' => $e->getMessage()
        ], 'index.php');
        return 'Неизвестно';
    }
}

/**
 * Извлекает имя менеджера из ответа API
 */
function extractManagerNameFromResponse(array $user): string
{
    if (isset($user["name"])) {
        return $user["name"];
    }

    if (isset($user[0]["name"])) {
        return $user[0]["name"];
    }

    if (is_array($user) && !empty($user)) {
        $firstUser = reset($user);
        if (isset($firstUser["name"])) {
            return $firstUser["name"];
        }
    }

    return 'Неизвестно';
}

/**
 * Формирует HTML-ссылку
 */
function buildHtmlLink(string $url, string $text): string
{
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return "<a href=\"{$safeUrl}\" target=\"_blank\">{$safeText}</a>";
}

/**
 * Получает данные студента из Hollyhop
 */
function fetchStudentFromHollyhop(int $clientId, string $authKey, string $apiBaseUrl): ?array
{
    $getStudentParams = ['clientId' => $clientId];
    $studentData = callHollyhopApi('GetStudents', $getStudentParams, $authKey, $apiBaseUrl);

    // Извлекаем студента из ответа
    if (isset($studentData['ClientId']) || isset($studentData['clientId'])) {
        return $studentData;
    }

    if (isset($studentData['Students']) && is_array($studentData['Students'])) {
        foreach ($studentData['Students'] as $student) {
            if (($student['ClientId'] ?? $student['clientId'] ?? null) == $clientId) {
                return $student;
            }
        }
    }

    if (is_array($studentData) && isset($studentData[0])) {
        return $studentData[0];
    }

    return null;
}

/**
 * Извлекает все ExtraFields и обновляет поле "Сделки АМО"
 */
function extractAllExtraFields(array $student, string $newAmoDealLink): array
{
    $allExtraFields = [];
    $currentAmoDeals = '';
    $amoDealsfieldFound = false;

    log_info("Начало обработки ExtraFields для обновления поля 'Сделки АМО'", [
        'clientId' => $student['ClientId'] ?? $student['clientId'] ?? 'unknown',
        'has_ExtraFields' => isset($student['ExtraFields']),
        'ExtraFields_count' => isset($student['ExtraFields']) && is_array($student['ExtraFields'])
            ? count($student['ExtraFields'])
            : 0
    ], 'index.php');

    $extraFields = $student['ExtraFields'] ?? [];

    if (!is_array($extraFields)) {
        log_warning("ExtraFields не является массивом", [
            'ExtraFields_type' => gettype($extraFields)
        ], 'index.php');
        $extraFields = [];
    }

    foreach ($extraFields as $field) {
        $fieldName = $field['Name'] ?? $field['name'] ?? '';
        $fieldValue = $field['Value'] ?? $field['value'] ?? '';

        if (isAmoDealField($fieldName)) {
            $currentAmoDeals = $fieldValue;
            $amoDealsfieldFound = true;

            log_info("Найдено поле 'Сделки АМО'", [
                'field_name' => $fieldName,
                'current_value_length' => strlen($fieldValue)
            ], 'index.php');
        } else {
            $allExtraFields[] = [
                'name' => $fieldName,
                'value' => $fieldValue
            ];
        }
    }

    if (!$amoDealsfieldFound) {
        log_warning("Поле 'Сделки АМО' не найдено в ExtraFields", [
            'available_fields' => array_map(fn($f) => $f['name'] ?? 'unknown', $allExtraFields)
        ], 'index.php');
    }

    $newAmoDealsValue = mergeAmoDealLinks($currentAmoDeals, $newAmoDealLink);

    $allExtraFields[] = [
        'name' => AMO_DEALS_FIELD_NAME,
        'value' => $newAmoDealsValue
    ];

    return $allExtraFields;
}

/**
 * Проверяет, является ли поле полем "Сделки АМО"
 */
function isAmoDealField(string $fieldName): bool
{
    $normalizedName = trim($fieldName);
    $lowerName = mb_strtolower($normalizedName, 'UTF-8');

    $exactMatches = ['Сделки АМО', 'Ссылки АМО'];
    if (in_array($normalizedName, $exactMatches, true)) {
        return true;
    }

    $containsSdelki = mb_stripos($lowerName, 'сделки', 0, 'UTF-8') !== false;
    $containsSsilki = mb_stripos($lowerName, 'ссылки', 0, 'UTF-8') !== false;
    $containsAmo = mb_stripos($lowerName, 'амо', 0, 'UTF-8') !== false ||
        mb_stripos($lowerName, 'amo', 0, 'UTF-8') !== false;

    return ($containsSdelki || $containsSsilki) && $containsAmo;
}

/**
 * Объединяет существующие ссылки с новой ссылкой
 */
function mergeAmoDealLinks(string $currentLinks, string $newLink): string
{
    $currentLinksClean = trim($currentLinks);

    // Извлекаем URL из новой ссылки
    preg_match('/href=["\']([^"\']+)["\']/', $newLink, $newLinkMatches);
    $newLinkUrl = $newLinkMatches[1] ?? '';

    log_info("Обработка текущего значения поля 'Сделки АМО'", [
        'original_value_preview' => substr($currentLinks, 0, 200),
        'new_link_to_add' => $newLink,
        'new_link_url' => $newLinkUrl
    ], 'index.php');

    // Разбиваем существующие ссылки
    $existingLinks = parseExistingLinks($currentLinksClean);

    log_info("Разбор существующих ссылок", [
        'existing_links_count' => count($existingLinks),
        'new_link_url' => $newLinkUrl
    ], 'index.php');

    // Проверяем наличие дубликата
    if (linkExists($existingLinks, $newLinkUrl)) {
        log_info("Ссылка уже существует в поле, оставляем без изменений", [
            'final_value_preview' => substr(implode("<br>", $existingLinks), 0, 200)
        ], 'index.php');
        return implode("<br>", $existingLinks);
    }

    // Добавляем новую ссылку
    $existingLinks[] = $newLink;

    log_info("Добавлена новая ссылка к существующим", [
        'final_value_preview' => substr(implode("<br>", $existingLinks), 0, 200),
        'total_links' => count($existingLinks)
    ], 'index.php');

    return implode("<br>", $existingLinks);
}

/**
 * Разбирает строку со ссылками на отдельные элементы
 */
function parseExistingLinks(string $linksString): array
{
    if (empty($linksString)) {
        return [];
    }

    $parts = preg_split('/<br\s*\/?>|\s*\r?\n\s*|\s{2,}/i', $linksString);
    $links = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if (!empty($part)) {
            $links[] = $part;
        }
    }

    return $links;
}

/**
 * Проверяет, существует ли ссылка в списке
 */
function linkExists(array $existingLinks, string $newUrl): bool
{
    $newUrlNormalized = normalizeUrl($newUrl);

    foreach ($existingLinks as $existingLink) {
        $existingUrl = extractUrlFromLink($existingLink);
        $existingUrlNormalized = normalizeUrl($existingUrl);

        if ($existingUrlNormalized === $newUrlNormalized) {
            log_info("Ссылка уже существует в поле", [
                'existing_url' => $existingUrl,
                'new_url' => $newUrl
            ], 'index.php');
            return true;
        }
    }

    return false;
}

/**
 * Извлекает URL из HTML-ссылки или возвращает саму строку
 */
function extractUrlFromLink(string $link): string
{
    if (preg_match('/href=["\']([^"\']+)["\']/', $link, $matches)) {
        return $matches[1];
    }
    return $link;
}

/**
 * Нормализует URL для сравнения
 */
function normalizeUrl(string $url): string
{
    return rtrim(strtolower($url), '/');
}

/**
 * Обновляет ExtraFields студента в Hollyhop
 */
function updateStudentExtraFields(
    int $clientId,
    array $allExtraFields,
    string $authKey,
    string $apiBaseUrl,
    int $leadId,
    string $amoDealLink
): void {
    log_info("Подготовка к обновлению через EditUserExtraFields", [
        'clientId' => $clientId,
        'total_fields_to_send' => count($allExtraFields),
        'all_fields_names' => array_map(fn($f) => $f['name'] ?? 'unknown', $allExtraFields)
    ], 'index.php');

    try {
        $updateParams = [
            'studentClientId' => $clientId,
            'fields' => $allExtraFields
        ];

        log_info("Отправка запроса EditUserExtraFields", [
            'studentClientId' => $clientId,
            'fields_count' => count($allExtraFields),
            'amo_deals_in_fields' => in_array(
                AMO_DEALS_FIELD_NAME,
                array_map(fn($f) => $f['name'] ?? '', $allExtraFields)
            )
        ], 'index.php');

        $updateResult = callHollyhopApi('EditUserExtraFields', $updateParams, $authKey, $apiBaseUrl);

        log_info("EditUserExtraFields выполнен успешно", [
            'result_preview' => is_array($updateResult)
                ? json_encode($updateResult, JSON_UNESCAPED_UNICODE)
                : (string)$updateResult
        ], 'index.php');

        log_info("Поле 'Сделки АМО' обновлено в Hollyhop", [
            'clientId' => $clientId,
            'lead_id' => $leadId,
            'amo_deal_link' => $amoDealLink,
            'method' => 'EditUserExtraFields',
            'total_fields_updated' => count($allExtraFields)
        ], 'index.php');
    } catch (Exception $e) {
        log_warning("Не удалось обновить через EditUserExtraFields", [
            'error' => substr($e->getMessage(), 0, 200),
            'fields_count' => count($allExtraFields),
            'full_error' => $e->getMessage()
        ], 'index.php');
    }
}

/**
 * Вызывает метод Hollyhop API
 */
function callHollyhopApi(string $functionName, array $params, string $authKey, string $apiBaseUrl): array
{
    $url = $apiBaseUrl . '/' . $functionName;
    $params['authkey'] = $authKey;
    $postData = json_encode($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($postData)
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => HOLLYHOP_API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => HOLLYHOP_CONNECT_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Ошибка подключения к API: {$curlError}");
    }

    if ($httpCode >= 400) {
        throw new Exception("Ошибка API (HTTP {$httpCode}): {$response}");
    }

    $result = json_decode($response, true);

    if ($result === null) {
        throw new Exception("Некорректный ответ от API. Raw response: " . substr($response, 0, 500));
    }

    return $result;
}
