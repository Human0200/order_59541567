<?php

/**
 * PHP скрипт для добавления студента в Hollyhop API
 * 
 * Принимает POST запрос с информацией студента и добавляет его в систему.
 * Рефакторенная версия с улучшенной обработкой ошибок и логированием.
 */

declare(strict_types=1);

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/error.log');

// Обработчик фатальных ошибок
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_message("ФАТАЛЬНАЯ ОШИБКА PHP", [
            'type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ], 'CRITICAL');
    }
});

// Обработчик исключений
set_exception_handler(function ($e) {
    log_message("НЕОБРАБОТАННОЕ ИСКЛЮЧЕНИЕ", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'CRITICAL');

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
});

// ============================================================================
// ЗАГРУЗКА ЗАВИСИМОСТЕЙ
// ============================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

// Получаем параметры из конфигурации
$api_config = get_config('api');
$auth_key = $api_config['auth_key'];
$api_base_url = $api_config['base_url'];

// ============================================================================
// КОНСТАНТЫ
// ============================================================================

const AMO_DEALS_FIELD_NAME = 'Сделки АМО';
const API_TIMEOUT = 60;
const API_CONNECT_TIMEOUT = 15;

// ============================================================================
// CORS И ЗАГОЛОВКИ
// ============================================================================

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Метод не поддерживается. Используйте POST.'
    ]);
    exit;
}

// ============================================================================
// ФУНКЦИИ ПОЛУЧЕНИЯ И ВАЛИДАЦИИ ДАННЫХ
// ============================================================================

/**
 * Получение данных из POST запроса
 */
function get_post_data(): array
{
    $input = file_get_contents('php://input');

    $data = json_decode($input, true);

    if ($data === null && !empty($input)) {
        parse_str($input, $data);
    }

    if (empty($data)) {
        $data = $_POST;
    }

    return is_array($data) ? $data : [];
}

/**
 * Валидация входных данных
 */
function validate_student_data(array $data): array
{
    // Базовая валидация - все поля опциональны
    return [];
}

// ============================================================================
// ФУНКЦИИ РАБОТЫ С API
// ============================================================================

/**
 * Отправка запроса к Hollyhop API
 */
function call_hollyhop_api(string $function_name, array $params, string $auth_key, string $api_base_url): array
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
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => API_CONNECT_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    log_message("API запрос: {$function_name}", [
        'url' => $url,
        'http_code' => $http_code,
        'request_params' => array_merge($params, ['authkey' => '***hidden***'])
    ]);

    if ($curl_error) {
        throw new Exception("Ошибка подключения к API: {$curl_error}");
    }

    if ($http_code >= 400) {
        throw new Exception("Ошибка API (HTTP {$http_code}): {$response}");
    }

    $result = json_decode($response, true);

    if ($result === null) {
        throw new Exception("Некорректный ответ от API. Raw response: " . substr($response, 0, 500));
    }

    log_message("API ответ успешно получен", [
        'function' => $function_name,
        'response_structure' => is_array($result) ? array_keys($result) : 'not_array',
        'response_type' => gettype($result)
    ]);

    return $result;
}

// ============================================================================
// ФУНКЦИИ МАППИНГА ДАННЫХ
// ============================================================================

/**
 * Маппинг пола
 */
function map_gender(?string $gender): ?bool
{
    if ($gender === null || trim($gender) === '') {
        return null;
    }

    $gender = trim($gender);
    $gender_lower = strtolower($gender);

    // Женский пол (false)
    if (
        in_array($gender, ['Ж', 'ж', 'F'], true) ||
        in_array($gender_lower, ['женский', 'female'], true) ||
        in_array($gender, ['false', '0'], true)
    ) {
        log_message("Маппинг gender", ['исходное' => $gender, 'преобразовано' => 'false (женский)']);
        return false;
    }

    // Мужской пол (true)
    if (
        in_array($gender, ['М', 'м', 'M'], true) ||
        in_array($gender_lower, ['мужской', 'male'], true) ||
        in_array($gender, ['true', '1'], true)
    ) {
        log_message("Маппинг gender", ['исходное' => $gender, 'преобразовано' => 'true (мужской)']);
        return true;
    }

    return null;
}

/**
 * Маппинг уровня
 */
function map_level(string $level): string
{
    $mapping = [
        'С нуля' => 'A0',
        'Индивидуальные' => 'Индивидуальный'
    ];

    if (isset($mapping[$level])) {
        log_message("level: найден в маппинге", ['исходное' => $level, 'преобразовано' => $mapping[$level]]);
        return $mapping[$level];
    }

    return $level;
}

/**
 * Маппинг типа обучения
 */
function map_learning_type(string $learningType): string
{
    $mapping = [
        'Мини группа' => 'Мини-группа',
        'Мини-группа' => 'Мини-группа',
        'Минигруппа' => 'Мини-группа',
        'Стандарт' => 'Стандарт',
        'Индивидуальный' => 'Индивидуальный',
        'Индивидуальные' => 'Индивидуальный',
        'Группа' => 'Группа',
        'Полная группа' => 'Полная группа',
        'Общий' => 'Общий',
        'Интенсивный' => 'Интенсивный'
    ];

    if (isset($mapping[$learningType])) {
        log_message("learningType: найден в маппинге", ['исходное' => $learningType, 'преобразовано' => $mapping[$learningType]]);
        return $mapping[$learningType];
    }

    log_message("learningType: ⚠️ не найдено в маппинге, передаём как есть", ['value' => $learningType]);
    return $learningType;
}

/**
 * Маппинг возрастной группы
 */
function map_maturity(string $maturity): string
{
    $mapping = [
        'Дошкольники' => '4-6 лет',
        'Подростки' => 'Ст. школьники'
    ];

    if (isset($mapping[$maturity])) {
        log_message("maturity: найден в маппинге", ['исходное' => $maturity, 'преобразовано' => $mapping[$maturity]]);
        return $mapping[$maturity];
    }

    return $maturity;
}

/**
 * Маппинг офиса
 */
function map_office($officeValue)
{
    $mapping = [
        'Выезд' => 7,
        'Красная Пресня' => 4,
        'Кр пресня' => 4,
        'Курская' => 2,
        'Ломоносовский проспект' => 45,
        'Немчиновка' => 30,
        'Октябрьская' => 5,
        'Онлайн-платформа' => 36,
        'Онлайн' => 36,
        'онлайн' => 36,
        'ООО Сфера-Строй М' => 66,
        'Таганская/Цветной бульвар' => 53,
        'Территория Смоленка' => 46
    ];

    if (is_numeric($officeValue)) {
        log_message("officeOrCompanyId: используем числовой ID", ['value' => (int)$officeValue]);
        return (int)$officeValue;
    }

    $officeStr = (string)$officeValue;

    if (isset($mapping[$officeStr])) {
        log_message("officeOrCompanyId: найден в маппинге", ['название' => $officeStr, 'ID' => $mapping[$officeStr]]);
        return $mapping[$officeStr];
    }

    log_message("officeOrCompanyId: ⚠️ не найдено в маппинге, передаём как есть", ['value' => $officeStr]);
    return $officeStr;
}

/**
 * Маппинг ответственного пользователя
 */
function map_responsible_user(string $responsibleUser): string
{
    $mapping = [
        'Наталья' => 'Наталья Владимировна старший администратор',
        'Александра' => 'Гид по обучению Александра',
        'Альбина' => 'Гид по обучению Альбина',
        'Елена' => 'Гид по обучению Елена',
        'Резервный менеджер' => 'Гид по обучению резервный'
    ];

    if (isset($mapping[$responsibleUser])) {
        log_message("responsible_user: найден в маппинге", [
            'исходное' => $responsibleUser,
            'преобразовано' => $mapping[$responsibleUser]
        ]);
        return $mapping[$responsibleUser];
    }

    return $responsibleUser;
}

/**
 * Подготовка параметров студента для API
 */
function prepare_student_params(array $postData): array
{
    log_message("ШАГ 3: Маппинг и подготовка данных студента", [], 'INFO');

    $params = [
        'firstName' => !empty($postData['firstName']) ? trim($postData['firstName']) : '-',
        'lastName' => !empty($postData['lastName']) ? trim($postData['lastName']) : '-'
    ];

    // Gender
    $mappedGender = map_gender($postData['gender'] ?? null);
    if ($mappedGender !== null) {
        $params['gender'] = $mappedGender;
    }

    // Базовые опциональные поля
    $optionalFields = [
        'middleName' => 'patronymic',
        'birthDate' => 'birthDate',
        'phone' => 'phone',
        'email' => 'email',
        'locationId' => 'locationId',
        'discipline' => 'discipline',
        'Status' => 'Status'
    ];

    foreach ($optionalFields as $postField => $apiField) {
        if (!empty($postData[$postField])) {
            $params[$apiField] = $postData[$postField];
        }
    }

    // Поля с маппингом
    if (!empty($postData['level'])) {
        $params['level'] = map_level($postData['level']);
    }

    if (!empty($postData['learningType'])) {
        $params['learningType'] = map_learning_type($postData['learningType']);
    }

    if (!empty($postData['maturity'])) {
        $params['maturity'] = map_maturity($postData['maturity']);
    }

    if (!empty($postData['officeOrCompanyId'])) {
        $params['officeOrCompanyId'] = map_office($postData['officeOrCompanyId']);
    }

    if (!empty($postData['responsible_user'])) {
        $params['responsible_user'] = map_responsible_user($postData['responsible_user']);
    }

    log_message("Подготовленные параметры для AddStudent API", [
        'params' => $params,
        'params_count' => count($params)
    ], 'INFO');

    return $params;
}

// ============================================================================
// ФУНКЦИИ ПОИСКА СТУДЕНТА
// ============================================================================

/**
 * Нормализация номера телефона
 */
function normalize_phone(string $phone): string
{
    if (empty($phone)) {
        return '';
    }

    $normalized = preg_replace('/\D/', '', $phone);

    if (strlen($normalized) === 11 && substr($normalized, 0, 1) === '8') {
        $normalized = '7' . substr($normalized, 1);
    }

    return $normalized;
}

/**
 * Извлечение телефона из объекта студента
 */
function get_student_phone(array $student): string
{
    $phoneFields = ['Phone', 'phone', 'Mobile', 'mobile', 'Telephone', 'telephone'];

    foreach ($phoneFields as $field) {
        if (isset($student[$field]) && !empty($student[$field])) {
            return trim($student[$field]);
        }
    }

    if (isset($student['Agents']) && is_array($student['Agents'])) {
        foreach ($student['Agents'] as $agent) {
            foreach (['Mobile', 'mobile', 'Phone', 'phone'] as $field) {
                if (isset($agent[$field]) && !empty($agent[$field])) {
                    return trim($agent[$field]);
                }
            }
        }
    }

    return '';
}

/**
 * Извлечение студентов из ответа API
 */
function extract_students_from_response($response): array
{
    if (!is_array($response)) {
        log_message("⚠️ Ответ API не является массивом", [
            'type' => gettype($response)
        ], 'WARNING');
        return [];
    }

    // Один студент напрямую
    if (
        isset($response['Id']) || isset($response['id']) ||
        isset($response['ClientId']) || isset($response['clientId'])
    ) {
        return [$response];
    }

    // Массив в поле Students
    if (isset($response['Students']) && is_array($response['Students'])) {
        return $response['Students'];
    }

    // Массив студентов напрямую
    if (isset($response[0]) && is_array($response[0])) {
        return $response;
    }

    return [];
}

/**
 * Получение ID студента
 */
function get_student_id($student): ?int
{
    if (!is_array($student)) {
        return null;
    }

    $idFields = ['Id', 'id', 'ClientId', 'clientId'];

    foreach ($idFields as $field) {
        if (isset($student[$field])) {
            return (int)$student[$field];
        }
    }

    return null;
}

/**
 * Поиск существующего студента по телефону
 */
function find_existing_student(string $phone, string $auth_key, string $api_base_url): ?array
{
    log_message("ШАГ 4: Поиск существующего студента по телефону", [], 'INFO');

    if (empty($phone)) {
        log_message("Телефон не указан, пропускаем проверку существующего студента");
        return null;
    }

    try {
        log_message("Поиск существующего студента по телефону", ['phone' => $phone]);

        $normalizedSearchPhone = normalize_phone($phone);
        log_message("Нормализованный номер для поиска", [
            'original' => $phone,
            'normalized' => $normalizedSearchPhone
        ]);

        // Пробуем разные параметры поиска
        $searchAttempts = [
            ['phone' => $phone],
            ['term' => $phone],
            ['search' => $phone],
            ['q' => $phone],
        ];

        $allCandidates = [];

        foreach ($searchAttempts as $attempt) {
            try {
                $paramName = array_key_first($attempt);
                log_message("Попытка поиска с параметром: {$paramName}", $attempt);

                $searchResponse = call_hollyhop_api('GetStudents', $attempt, $auth_key, $api_base_url);

                log_message("Ответ GetStudents получен для параметра {$paramName}", [
                    'type' => gettype($searchResponse),
                    'is_empty' => empty($searchResponse)
                ]);

                $candidates = extract_students_from_response($searchResponse);

                log_message("Извлечено кандидатов из ответа", [
                    'param' => $paramName,
                    'count' => count($candidates)
                ]);

                // Добавляем кандидатов (избегаем дубликатов)
                foreach ($candidates as $candidate) {
                    $candidateId = get_student_id($candidate);

                    if ($candidateId === null) {
                        continue;
                    }

                    $exists = false;
                    foreach ($allCandidates as $existing) {
                        if (get_student_id($existing) === $candidateId) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        $allCandidates[] = $candidate;
                    }
                }
            } catch (Exception $e) {
                log_message("Попытка поиска с {$paramName} не сработала", [
                    'error' => $e->getMessage()
                ], 'WARNING');
                continue;
            }
        }

        log_message("Собрано кандидатов из всех попыток", [
            'total_candidates' => count($allCandidates)
        ]);

        // Фильтруем по точному совпадению телефона
        $matchedStudents = [];

        foreach ($allCandidates as $candidate) {
            try {
                $candidatePhone = get_student_phone($candidate);

                if (empty($candidatePhone)) {
                    continue;
                }

                $normalizedCandidatePhone = normalize_phone($candidatePhone);

                if ($normalizedCandidatePhone === $normalizedSearchPhone) {
                    $matchedStudents[] = $candidate;
                }
            } catch (Exception $e) {
                log_message("Ошибка при обработке кандидата", [
                    'error' => $e->getMessage(),
                    'candidate_id' => get_student_id($candidate)
                ], 'WARNING');
                continue;
            }
        }

        log_message("Результаты поиска", [
            'total_candidates' => count($allCandidates),
            'matched_students' => count($matchedStudents)
        ]);

        if (!empty($matchedStudents)) {
            $existingStudent = $matchedStudents[0];
            log_message("✓✓✓ НАЙДЕН студент с ТОЧНЫМ совпадением телефона", [
                'Id' => get_student_id($existingStudent),
                'total_matched' => count($matchedStudents)
            ]);
            return $existingStudent;
        }

        log_message("⚠️ Студент с телефоном не найден, будет создан новый", [
            'phone' => $phone,
            'normalized' => $normalizedSearchPhone,
            'total_candidates' => count($allCandidates)
        ]);

        return null;
    } catch (Exception $e) {
        log_message("Ошибка при поиске студента по телефону", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'ERROR');
        return null;
    }
}

// ============================================================================
// ФУНКЦИИ СОЗДАНИЯ/ОБНОВЛЕНИЯ СТУДЕНТА
// ============================================================================

/**
 * Создание нового студента
 */
function create_new_student(array $params, string $auth_key, string $api_base_url): array
{
    log_message("ШАГ 5: Создание нового студента", [], 'INFO');

    unset($params['Id'], $params['ClientId']);

    $result = call_hollyhop_api('AddStudent', $params, $auth_key, $api_base_url);

    if (!isset($result)) {
        throw new Exception("Не удалось получить результат от API (AddStudent)");
    }

    log_message("Ответ от AddStudent API получен", [
        'result_type' => gettype($result),
        'result_keys' => is_array($result) ? array_keys($result) : 'not_array'
    ], 'INFO');

    return $result;
}

/**
 * Обновление контактов студента
 */
function update_student_contacts(?int $clientId, array $postData, string $auth_key, string $api_base_url): void
{
    log_message("ШАГ 7: Обновление контактных данных", [
        'has_client_id' => $clientId !== null,
        'has_phone' => !empty($postData['phone']),
        'has_email' => !empty($postData['email'])
    ], 'INFO');

    if ($clientId === null || (empty($postData['phone']) && empty($postData['email']))) {
        return;
    }

    try {
        $editContactsParams = [
            'StudentClientId' => $clientId,
            'useMobileBySystem' => false,
            'useEMailBySystem' => false
        ];

        if (!empty($postData['phone'])) {
            $editContactsParams['mobile'] = trim($postData['phone']);
            $editContactsParams['useMobileBySystem'] = true;
        }

        if (!empty($postData['email'])) {
            $editContactsParams['eMail'] = trim($postData['email']);
            $editContactsParams['useEMailBySystem'] = true;
        }

        log_message("Вызов EditContacts", $editContactsParams);

        $result = call_hollyhop_api('EditContacts', $editContactsParams, $auth_key, $api_base_url);

        log_message("EditContacts выполнен успешно", $result);
    } catch (Exception $e) {
        log_message("Ошибка при обновлении контактов через EditContacts", [
            'error' => $e->getMessage(),
            'client_id' => $clientId
        ], 'ERROR');
    }
}

/**
 * Извлечение ID студента из разных источников
 */
function extract_student_ids($existingStudent, $apiResult): array
{
    log_message("ШАГ 6: Извлечение ID студента", [], 'INFO');

    $studentId = null;
    $clientId = null;

    // Из существующего студента
    if ($existingStudent !== null) {
        $studentId = $existingStudent['Id'] ?? $existingStudent['id'] ?? null;
        $clientId = $existingStudent['ClientId'] ?? $existingStudent['clientId'] ?? null;

        if ($studentId === null) {
            $studentId = $clientId;
        }
        if ($clientId === null) {
            $clientId = $studentId;
        }

        log_message("Используем данные существующего студента", [
            'Id' => $studentId,
            'ClientId' => $clientId
        ]);

        return [$studentId, $clientId];
    }

    // Из результата API
    if ($apiResult !== null) {
        $idFields = ['Id', 'id', 'ID', 'studentId', 'ClientId', 'clientId'];

        foreach ($idFields as $field) {
            if (isset($apiResult[$field])) {
                $studentId = $apiResult[$field];
                break;
            }
        }

        if ($studentId === null && is_numeric($apiResult)) {
            $studentId = $apiResult;
        }

        if ($studentId === null && is_array($apiResult) && !empty($apiResult)) {
            $firstItem = $apiResult[0] ?? null;
            if ($firstItem !== null) {
                foreach (['Id', 'id', 'ClientId', 'clientId'] as $field) {
                    if (isset($firstItem[$field])) {
                        $studentId = $firstItem[$field];
                        break;
                    }
                }
            }
        }

        if ($studentId === null) {
            $responseKeys = is_array($apiResult) ? array_keys($apiResult) : 'not_array';
            throw new Exception("API не вернул Id студента. Найдены поля: " . implode(', ', $responseKeys));
        }

        // Получаем ClientId
        $clientId = $apiResult['ClientId'] ?? $apiResult['clientId'] ?? $studentId;

        log_message("ID извлечены из ответа API", [
            'Id' => $studentId,
            'ClientId' => $clientId
        ]);
    }

    return [(int)$studentId, (int)$clientId];
}

/**
 * Получение полного профиля студента
 */
function get_full_student_profile(?int $clientId, string $auth_key, string $api_base_url): ?int
{
    log_message("ШАГ 8: Получение полного профиля студента", [
        'client_id' => $clientId
    ], 'INFO');

    if ($clientId === null) {
        return null;
    }

    try {
        $getStudentParams = ['clientId' => $clientId];
        $apiResponse = call_hollyhop_api('GetStudents', $getStudentParams, $auth_key, $api_base_url);

        $students = extract_students_from_response($apiResponse);

        log_message("Ответ от GetStudents получен", [
            'students_count' => count($students)
        ]);

        if (empty($students)) {
            log_message("⚠️ GetStudents вернул пустой результат", [], 'WARNING');
            return $clientId;
        }

        // Ищем студента с нужным ClientId
        foreach ($students as $student) {
            $studentClientId = $student['ClientId'] ?? $student['clientId'] ?? null;

            if ($studentClientId == $clientId) {
                $profileId = $student['Id'] ?? $student['id'] ?? $clientId;
                log_message("✓ Найден профиль студента", [
                    'profile_id' => $profileId
                ]);
                return (int)$profileId;
            }
        }

        log_message("⚠️ Студент с ClientId не найден, используем ClientId как fallback", [
            'client_id' => $clientId
        ], 'WARNING');

        return $clientId;
    } catch (Exception $e) {
        log_message("Ошибка при запросе GetStudents", [
            'error' => $e->getMessage()
        ], 'ERROR');
        return $clientId;
    }
}

// ============================================================================
// ФУНКЦИИ ОБНОВЛЕНИЯ ПОЛЯ "СДЕЛКИ АМО"
// ============================================================================

/**
 * Получение имени менеджера из AmoCRM
 */
function get_manager_name(int $leadId, string $subdomain): string
{
    if (!file_exists(__DIR__ . '/amo_func.php')) {
        return 'Неизвестно';
    }

    try {
        require_once __DIR__ . '/amo_func.php';

        global $data;

        $lead = get($subdomain, "/api/v4/leads/{$leadId}", $data);

        $responsibleUserId = $lead["responsible_user_id"] ?? null;

        if ($responsibleUserId === null) {
            return 'Неизвестно';
        }

        $user = get($subdomain, "/api/v4/users/{$responsibleUserId}", $data);

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
    } catch (Exception $e) {
        log_message("Не удалось получить имя менеджера", [
            'error' => $e->getMessage()
        ], 'WARNING');
        return 'Неизвестно';
    }
}

/**
 * Проверка, является ли поле полем "Сделки АМО"
 */
function is_amo_deals_field(string $fieldName): bool
{
    $normalized = trim($fieldName);
    $lower = mb_strtolower($normalized, 'UTF-8');

    $exactMatches = ['Сделки АМО', 'Ссылки АМО'];
    if (in_array($normalized, $exactMatches, true)) {
        return true;
    }

    $hasSdelki = mb_stripos($lower, 'сделки', 0, 'UTF-8') !== false;
    $hasSsilki = mb_stripos($lower, 'ссылки', 0, 'UTF-8') !== false;
    $hasAmo = mb_stripos($lower, 'амо', 0, 'UTF-8') !== false ||
        mb_stripos($lower, 'amo', 0, 'UTF-8') !== false;

    return ($hasSdelki || $hasSsilki) && $hasAmo;
}

/**
 * Обновление поля "Сделки АМО"
 */
function update_amo_deals_field(
    int $clientId,
    int $leadId,
    string $subdomain,
    string $auth_key,
    string $api_base_url
): void {
    log_message("ШАГ 10: Обновление поля 'Сделки АМО'", [
        'client_id' => $clientId,
        'lead_id' => $leadId
    ], 'INFO');

    try {
        $managerName = get_manager_name($leadId, $subdomain);

        $dealUrl = "https://{$subdomain}.amocrm.ru/leads/detail/{$leadId}";
        $dealLink = '<a href="' . htmlspecialchars($dealUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank">' .
            htmlspecialchars($managerName, ENT_QUOTES, 'UTF-8') . ': ' . $leadId . '</a>';

        // Получаем студента
        $studentData = call_hollyhop_api(
            'GetStudents',
            ['clientId' => $clientId],
            $auth_key,
            $api_base_url
        );

        $students = extract_students_from_response($studentData);

        if (empty($students)) {
            log_message("Студент не найден для обновления поля 'Сделки АМО'", [
                'client_id' => $clientId
            ], 'WARNING');
            return;
        }

        $student = $students[0];

        // Обрабатываем ExtraFields
        $allExtraFields = [];
        $currentAmoDeals = '';

        $extraFields = $student['ExtraFields'] ?? [];

        if (!is_array($extraFields)) {
            $extraFields = [];
        }

        foreach ($extraFields as $field) {
            $fieldName = $field['Name'] ?? $field['name'] ?? '';
            $fieldValue = $field['Value'] ?? $field['value'] ?? '';

            if (is_amo_deals_field($fieldName)) {
                $currentAmoDeals = $fieldValue;
            } else {
                $allExtraFields[] = [
                    'name' => $fieldName,
                    'value' => $fieldValue
                ];
            }
        }

        // Проверяем, есть ли уже эта ссылка
        $existingLinks = array_filter(
            preg_split('/<br\s*\/?>|\s*\r?\n\s*|\s{2,}/i', trim($currentAmoDeals)),
            fn($link) => !empty(trim($link))
        );

        preg_match('/href=["\']([^"\']+)["\']/', $dealLink, $newLinkMatches);
        $newLinkUrl = $newLinkMatches[1] ?? '';

        $linkExists = false;
        foreach ($existingLinks as $existingLink) {
            if (preg_match('/href=["\']([^"\']+)["\']/', $existingLink, $matches)) {
                $existingUrl = $matches[1];
            } else {
                $existingUrl = $existingLink;
            }

            if (rtrim(strtolower($existingUrl), '/') === rtrim(strtolower($newLinkUrl), '/')) {
                $linkExists = true;
                break;
            }
        }

        if (!$linkExists) {
            $existingLinks[] = $dealLink;
        }

        $newAmoDealsValue = implode("<br>", $existingLinks);

        $allExtraFields[] = [
            'name' => AMO_DEALS_FIELD_NAME,
            'value' => $newAmoDealsValue
        ];

        // Обновляем через API
        $updateParams = [
            'studentClientId' => $clientId,
            'fields' => $allExtraFields
        ];

        call_hollyhop_api('EditUserExtraFields', $updateParams, $auth_key, $api_base_url);

        log_message("Поле 'Сделки АМО' успешно обновлено", [
            'client_id' => $clientId,
            'lead_id' => $leadId,
            'total_fields' => count($allExtraFields)
        ]);
    } catch (Exception $e) {
        log_message("Ошибка при обновлении поля 'Сделки АМО'", [
            'error' => $e->getMessage(),
            'client_id' => $clientId,
            'lead_id' => $leadId
        ], 'ERROR');
    }
}

// ============================================================================
// ОСНОВНАЯ ЛОГИКА
// ============================================================================

try {
    $scriptStartTime = microtime(true);

    log_message("═══════════════════════════════════════════════════════════", [], 'INFO');
    log_message("НАЧАЛО ОБРАБОТКИ ЗАПРОСА add_student.php", [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
    ], 'INFO');
    log_message("═══════════════════════════════════════════════════════════", [], 'INFO');

    // Шаг 1: Получение данных
    log_message("ШАГ 1: Получение данных из POST запроса", [], 'INFO');
    $postData = get_post_data();

    log_message("Получены POST данные", [
        'has_data' => !empty($postData),
        'data_keys' => array_keys($postData)
    ], 'INFO');

    // Шаг 2: Валидация
    log_message("ШАГ 2: Валидация входных данных", [], 'INFO');
    $validationErrors = validate_student_data($postData);

    if (!empty($validationErrors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'errors' => $validationErrors
        ]);
        exit;
    }

    log_message("Валидация пройдена успешно", [], 'INFO');

    // Шаг 3: Подготовка параметров
    $studentParams = prepare_student_params($postData);

    // Шаг 4: Поиск существующего студента
    $existingStudent = find_existing_student(
        $postData['phone'] ?? '',
        $auth_key,
        $api_base_url
    );

    $isUpdate = ($existingStudent !== null);

    // Шаг 5: Создание или использование существующего
    $apiResult = null;
    if (!$isUpdate) {
        $apiResult = create_new_student($studentParams, $auth_key, $api_base_url);
    } else {
        log_message("Пропуск создания - используется существующий студент", [
            'existing_id' => get_student_id($existingStudent)
        ], 'INFO');
    }

    // Шаг 6: Извлечение ID
    [$studentId, $clientId] = extract_student_ids($existingStudent, $apiResult);

    // Шаг 7: Обновление контактов (только для новых)
    if (!$isUpdate) {
        update_student_contacts($clientId, $postData, $auth_key, $api_base_url);
    }

    // Шаг 8: Получение полного профиля
    $profileId = get_full_student_profile($clientId, $auth_key, $api_base_url) ?? $studentId;

    // Шаг 10: Обновление поля "Сделки АМО"
    if (!empty($postData['amo_lead_id']) && $clientId !== null) {
        update_amo_deals_field(
            $clientId,
            (int)$postData['amo_lead_id'],
            $postData['amo_subdomain'] ?? 'directorchinatutorru',
            $auth_key,
            $api_base_url
        );
    }

    // Формирование ответа
    log_message("ШАГ 11: Формирование финального ответа", [], 'INFO');

    $subdomain = $api_config['subdomain'];
    $operation = $isUpdate ? 'updated' : 'created';

    $responseData = [
        'success' => true,
        'operation' => $operation,
        'operation_text' => $isUpdate ? 'Студент обновлен' : 'Студент создан',
        'search_result' => [
            'found' => $isUpdate,
            'found_text' => $isUpdate ? 'Студент найден в базе' : 'Студент не найден, создан новый',
            'phone' => $postData['phone'] ?? null
        ],
        'clientId' => $clientId,
        'Id' => $profileId,
        'link' => "https://{$subdomain}.t8s.ru/Profile/{$profileId}"
    ];

    if ($isUpdate && $existingStudent) {
        $responseData['existing_student'] = [
            'firstName' => $existingStudent['FirstName'] ?? $existingStudent['firstName'] ?? '',
            'lastName' => $existingStudent['LastName'] ?? $existingStudent['lastName'] ?? '',
            'phone' => get_student_phone($existingStudent)
        ];
    }

    $executionTime = round(microtime(true) - $scriptStartTime, 2);

    log_message("═══════════════════════════════════════════════════════════", [], 'INFO');
    log_message("УСПЕШНОЕ ЗАВЕРШЕНИЕ ОБРАБОТКИ", [
        'operation' => $operation,
        'clientId' => $clientId,
        'profileId' => $profileId,
        'execution_time_seconds' => $executionTime
    ], 'INFO');
    log_message("═══════════════════════════════════════════════════════════", [], 'INFO');

    http_response_code(200);
    echo json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);

    log_message("═══════════════════════════════════════════════════════════", [], 'ERROR');
    log_message("КРИТИЧЕСКАЯ ОШИБКА", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'ERROR');
    log_message("═══════════════════════════════════════════════════════════", [], 'ERROR');

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
