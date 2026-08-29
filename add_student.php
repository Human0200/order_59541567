<?php

/**
 * PHP скрипт для добавления студента в Hollyhop API
 * 
 * Принимает POST запрос с информацией студента и добавляет его в систему.
 * После успешного создания получает из GetStudents:
 * - Id (идентификатор профиля студента) ⭐
 * - ClientId (идентификатор ученика как клиента)
 * И возвращает их в ответе.
 */

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Логирование ошибок
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// ============ КОНФИГУРАЦИЯ ============
// Загружаем конфигурацию из .env файла
require_once __DIR__ . '/config.php';
// Подключаем единую систему логирования
require_once __DIR__ . '/logger.php';

// Получаем параметры из конфигурации
$api_config = get_config('api');
$auth_key = $api_config['auth_key'];
$api_base_url = $api_config['base_url'];

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
    echo json_encode([
        'success' => false,
        'error' => 'Метод не поддерживается. Используйте POST.'
    ]);
    exit;
}

/**
 * Получение данных из POST запроса
 */
function get_post_data()
{
    $input = file_get_contents('php://input');

    // Пытаемся декодировать JSON
    $data = json_decode($input, true);

    if ($data === null && !empty($input)) {
        // Если JSON не сработал, пробуем разобрать как form-data
        parse_str($input, $data);
    }

    // Если всё ещё ничего нет, используем $_POST
    if (empty($data)) {
        $data = $_POST;
    }

    return $data;
}

/**
 * Валидация входных данных
 */
function validate_student_data($data)
{
    $errors = [];

    // Имя и фамилия теперь не обязательны - если не указаны, будут заменены на прочерки

    return $errors;
}

/**
 * Отправка запроса к Hollyhop API
 */
function call_hollyhop_api($function_name, $params, $auth_key, $api_base_url)
{
    $url = $api_base_url . '/' . $function_name;

    // Добавляем authkey
    $params['authkey'] = $auth_key;

    // Подготавливаем данные для отправки
    $post_data = json_encode($params);

    // Инициализируем cURL
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
        CURLOPT_TIMEOUT => 60, // Увеличен таймаут до 60 секунд для надежной работы API
        CURLOPT_CONNECTTIMEOUT => 15, // Таймаут на установку соединения
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    curl_close($ch);

    // Логирование запроса
    log_message("API запрос: {$function_name}", [
        'url' => $url,
        'http_code' => $http_code,
        'request_params' => array_merge($params, ['authkey' => '***hidden***'])
    ]);

    if ($curl_error) {
        log_message("cURL ошибка: {$curl_error}");
        throw new Exception("Ошибка подключения к API: {$curl_error}");
    }

    if ($http_code >= 400) {
        log_message("API ошибка (HTTP {$http_code})", $response);
        throw new Exception("Ошибка API (HTTP {$http_code}): {$response}");
    }

    $result = json_decode($response, true);

    if ($result === null) {
        log_message("Ошибка декодирования JSON", [
            'raw_response' => $response,
            'json_error' => json_last_error_msg(),
            'http_code' => $http_code
        ]);
        throw new Exception("Некорректный ответ от API. Raw response: " . substr($response, 0, 500));
    }

    // Логируем успешный ответ для отладки
    log_message("API ответ успешно получен", [
        'function' => $function_name,
        'response_structure' => is_array($result) ? array_keys($result) : 'not_array',
        'response_type' => gettype($result)
    ]);

    return $result;
}

function normalize_extra_field_name($name)
{
    $name = trim((string)$name);
    $name = str_replace('ё', 'е', $name);
    $name = preg_replace('/\s+/u', ' ', $name);

    return mb_strtolower($name, 'UTF-8');
}

function normalize_holly_birth_date($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{13}$/', $value)) {
        $timestamp = (int) floor(((int) $value) / 1000);
        return date('Y-m-d', $timestamp);
    }

    if (preg_match('/^\d{9,10}$/', $value)) {
        return date('Y-m-d', (int) $value);
    }

    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $matches)) {
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    }

    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches)) {
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    }

    return $value;
}

function normalize_holly_custom_date($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{13}$/', $value)) {
        $timestamp = (int) floor(((int) $value) / 1000);
        return date('d.m.Y', $timestamp);
    }

    if (preg_match('/^\d{9,10}$/', $value)) {
        return date('d.m.Y', (int) $value);
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
        return $matches[3] . '.' . $matches[2] . '.' . $matches[1];
    }

    return $value;
}

function build_student_extra_fields_from_post_data($post_data)
{
    $extra_fields = [];

    $append_field = static function (array &$fields, string $name, $value): void {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        $fields[] = [
            'name' => $name,
            'value' => trim((string) $value)
        ];
    };

    if (empty($post_data['birthDate']) && !empty($post_data['Дата рождения'])) {
        $post_data['birthDate'] = normalize_holly_birth_date($post_data['Дата рождения']);
    }

    if (!empty($post_data['childName'])) {
        $extra_fields[] = [
            'name' => 'ФИО ребенка',
            'value' => trim((string)$post_data['childName'])
        ];
    }

    $child_birth_date = '';
    if (!empty($post_data['Дата рождения'])) {
        $child_birth_date = normalize_holly_birth_date($post_data['Дата рождения']);
    } elseif (!empty($post_data['childBirthDate'])) {
        $child_birth_date = normalize_holly_birth_date($post_data['childBirthDate']);
    } elseif (!empty($post_data['birthDate'])) {
        $child_birth_date = normalize_holly_birth_date($post_data['birthDate']);
    }

    if ($child_birth_date !== '') {
        $extra_fields[] = [
            'name' => 'Дата рождения',
            'value' => $child_birth_date
        ];
    }

    // Сохраняем учебные параметры в пользовательских полях Hollyhop.
    // Это нужно и для новых, и для уже существующих учеников.
    $append_field($extra_fields, 'Пакет (индив)', $post_data['hollyPackage'] ?? null);
    $append_field($extra_fields, 'Количество уроков (индив)', $post_data['hollyLessonsCount'] ?? null);
    $append_field($extra_fields, 'Срок пакета (индив)', $post_data['hollyPackageTerm'] ?? null);
    $append_field($extra_fields, 'Интенсивность (индив)', $post_data['hollyIntensity'] ?? null);
    $append_field($extra_fields, 'Длительность урока (индив)', $post_data['hollyLessonDuration'] ?? null);
    $append_field($extra_fields, 'Место проведения (индив)', $post_data['hollyLessonLocation'] ?? null);
    $append_field($extra_fields, 'Преподаватель (индив)', $post_data['hollyTeacher'] ?? null);
    $append_field($extra_fields, 'Фиксация слота (индив)', $post_data['hollySlotFixed'] ?? null);
    $append_field($extra_fields, 'Слот день+время (индив)', $post_data['hollySchedule'] ?? null);
    $append_field($extra_fields, 'Скидка (индив)', $post_data['hollyDiscount'] ?? null);
    $append_field($extra_fields, 'VIP (индив)', $post_data['hollyVip'] ?? null);
    $append_field($extra_fields, 'Цена 1 занятия (индив)', $post_data['hollyLessonPrice'] ?? null);
    $append_field($extra_fields, 'Итого стоимость со скидкой (индив)', $post_data['hollyTotalPrice'] ?? null);
    $append_field($extra_fields, 'Комбо Актив (индив)', $post_data['hollyComboActive'] ?? null);
    $append_field($extra_fields, 'Языковой клуб (индив)', $post_data['hollyLanguageClub'] ?? null);
    $append_field($extra_fields, 'Лимит переносов (индив)', $post_data['hollyTransferLimit'] ?? null);
    $append_field($extra_fields, 'Пауза / недель (индив)', $post_data['hollyFreePause'] ?? null);
    $append_field(
        $extra_fields,
        'Срок оплаты 50/50 (вторая часть) индив',
        normalize_holly_custom_date($post_data['hollySecondPaymentDue'] ?? null)
    );

    return $extra_fields;
}

function sanitize_holly_name(string $name): string
{
    // Hollyhop принимает только буквы, дефис, апостроф, пробел
    $clean = preg_replace('/[^\p{L}\s\-\']/u', '', $name);
    $clean = trim((string)$clean);
    return $clean !== '' ? $clean : '-';
}

function split_full_name($full_name)
{
    $full_name = trim((string)$full_name);
    if ($full_name === '') {
        return ['firstName' => '', 'lastName' => '', 'middleName' => ''];
    }

    $parts = preg_split('/\s+/u', $full_name) ?: [];

    return [
        'firstName' => $parts[0] ?? '',
        'lastName' => $parts[1] ?? '',
        'middleName' => count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : ''
    ];
}

function build_agent_contacts_payload($student_info, $post_data, $client_id)
{
    $parent_name = trim((string)($post_data['parentName'] ?? ''));
    $parent_phone = trim((string)($post_data['parentPhone'] ?? ''));
    $parent_email = trim((string)($post_data['parentEmail'] ?? ''));
    $emergency_phone = trim((string)($post_data['parentEmergencyPhone'] ?? ''));

    if ($parent_name === '' && $parent_phone === '' && $parent_email === '' && $emergency_phone === '') {
        return null;
    }

    $existing_agents = [];
    if (is_array($student_info) && isset($student_info['Agents']) && is_array($student_info['Agents'])) {
        foreach ($student_info['Agents'] as $agent) {
            if (!is_array($agent)) {
                continue;
            }

            $existing_agents[] = [
                'firstName' => trim((string)($agent['FirstName'] ?? $agent['firstName'] ?? '')),
                'middleName' => trim((string)($agent['MiddleName'] ?? $agent['middleName'] ?? '')),
                'lastName' => trim((string)($agent['LastName'] ?? $agent['lastName'] ?? '')),
                'whoIs' => trim((string)($agent['WhoIs'] ?? $agent['whoIs'] ?? '')),
                'mobile' => trim((string)($agent['Mobile'] ?? $agent['mobile'] ?? '')),
                'useMobileBySystem' => (bool)($agent['UseMobileBySystem'] ?? $agent['useMobileBySystem'] ?? false),
                'phone' => trim((string)($agent['Phone'] ?? $agent['phone'] ?? '')),
                'eMail' => trim((string)($agent['EMail'] ?? $agent['eMail'] ?? '')),
                'useEMailBySystem' => (bool)($agent['UseEMailBySystem'] ?? $agent['useEMailBySystem'] ?? false),
                'jobOrStudyPlace' => trim((string)($agent['JobOrStudyPlace'] ?? $agent['jobOrStudyPlace'] ?? '')),
                'position' => trim((string)($agent['Position'] ?? $agent['position'] ?? '')),
                'isCustomer' => (bool)($agent['IsCustomer'] ?? $agent['isCustomer'] ?? false),
                'birthday' => trim((string)($agent['Birthday'] ?? $agent['birthday'] ?? ''))
            ];
        }
    }

    $parsed_parent_name = split_full_name($parent_name);
    $new_parent = [
        'firstName' => $parsed_parent_name['firstName'],
        'middleName' => $parsed_parent_name['middleName'],
        'lastName' => $parsed_parent_name['lastName'],
        'whoIs' => 'Родитель',
        'mobile' => $parent_phone,
        'useMobileBySystem' => false,
        'phone' => $parent_phone,
        'eMail' => $parent_email,
        'useEMailBySystem' => false,
        'isCustomer' => true
    ];

    $new_emergency = [
        'firstName' => 'Экстренный',
        'middleName' => '',
        'lastName' => '',
        'whoIs' => 'Экстренный',
        'useMobileBySystem' => false,
        'phone' => $emergency_phone,
        'eMail' => '',
        'useEMailBySystem' => false,
        'isCustomer' => false
    ];

    $agents = [];
    $parent_replaced = false;
    $emergency_replaced = false;
    foreach ($existing_agents as $agent) {
        $agent_first_name = trim((string)($agent['firstName'] ?? ''));
        $agent_who_is = trim((string)($agent['whoIs'] ?? ''));
        $agent_email = trim((string)($agent['eMail'] ?? ''));
        $agent_phone = trim((string)($agent['phone'] ?? $agent['mobile'] ?? ''));
        $same_email = $parent_email !== '' && $agent_email === $parent_email;
        $same_customer = !empty($agent['isCustomer']);
        $same_parent_phone = $parent_phone !== '' && $agent_phone === $parent_phone;
        $is_emergency_contact = $agent_first_name === 'Экстренный' || $agent_who_is === 'Экстренный' || ($emergency_phone !== '' && $agent_phone === $emergency_phone);

        if ($emergency_phone !== '' && !$emergency_replaced && $is_emergency_contact) {
            $agents[] = array_merge($agent, array_filter($new_emergency, function ($value) {
                return $value !== '';
            }));
            $emergency_replaced = true;
            continue;
        }

        if (($parent_name !== '' || $parent_phone !== '' || $parent_email !== '') && !$parent_replaced && ($same_email || $same_customer || $same_parent_phone)) {
            $agents[] = array_merge($agent, array_filter($new_parent, function ($value) {
                return $value !== '';
            }));
            $parent_replaced = true;
            continue;
        }

        $agents[] = $agent;
    }

    if (($parent_name !== '' || $parent_phone !== '' || $parent_email !== '') && !$parent_replaced) {
        $agents[] = $new_parent;
    }

    if ($emergency_phone !== '' && !$emergency_replaced) {
        $agents[] = $new_emergency;
    }

    return [
        'studentClientId' => $client_id,
        'agents' => $agents
    ];
}

function build_ind_client_params_payload($post_data, $client_id)
{
    if (empty($client_id)) {
        return null;
    }

    $payload = [
        'studentClientId' => $client_id
    ];

    if (!empty($post_data['discipline'])) {
        $payload['discipline'] = trim((string)$post_data['discipline']);
    }

    if (!empty($post_data['level'])) {
        $payload['level'] = trim((string)$post_data['level']);
    }

    if (count($payload) === 1) {
        return null;
    }

    return $payload;
}

function build_extra_fields_update_payload($student_info, $post_data, $client_id)
{
    if (empty($client_id) || !is_array($student_info)) {
        return null;
    }

    $source_system = trim((string)($post_data['sourceSystem'] ?? ''));
    if ($source_system !== 'okidoki') {
        return null;
    }

    $level = trim((string)($post_data['level'] ?? ''));
    if ($level === '') {
        return null;
    }

    $field_name = 'Уровень языка из OkiDoki';
    $fields = [];
    $field_replaced = false;

    if (isset($student_info['ExtraFields']) && is_array($student_info['ExtraFields'])) {
        foreach ($student_info['ExtraFields'] as $field) {
            if (!is_array($field) || !isset($field['Name'])) {
                continue;
            }

            $name = trim((string)$field['Name']);
            if ($name === '') {
                continue;
            }

            if ($name === $field_name) {
                $fields[] = [
                    'name' => $field_name,
                    'value' => $level
                ];
                $field_replaced = true;
                continue;
            }

            $fields[] = [
                'name' => $name,
                'value' => $field['Value'] ?? ''
            ];
        }
    }

    if (!$field_replaced) {
        $fields[] = [
            'name' => $field_name,
            'value' => $level
        ];
    }

    if (empty($fields)) {
        return null;
    }

    return [
        'studentClientId' => $client_id,
        'fields' => $fields
    ];
}

/**
 * Основная логика скрипта
 */
try {
    $script_start_time = microtime(true);
    log_message("═══════════════════════════════════════════════════════════", [], 'INFO');
    log_message("НАЧАЛО ОБРАБОТКИ ЗАПРОСА add_student.php", [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
    ], 'INFO');
    log_message("═══════════════════════════════════════════════════════════", [], 'INFO');

    // Получаем данные из POST запроса
    log_message("ШАГ 1: Получение данных из POST запроса", [], 'INFO');
    $post_data = get_post_data();

    log_message("Получены POST данные", [
        'has_data' => !empty($post_data),
        'data_keys' => is_array($post_data) ? array_keys($post_data) : 'not_array',
        'data_preview' => is_array($post_data) ? json_encode($post_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string)$post_data
    ], 'INFO');

    // Валидируем данные
    log_message("ШАГ 2: Валидация входных данных", [], 'INFO');
    $validation_errors = validate_student_data($post_data);

    if (!empty($validation_errors)) {
        log_message("ОШИБКА ВАЛИДАЦИИ: Запрос отклонен", [
            'errors' => $validation_errors,
            'post_data_keys' => is_array($post_data) ? array_keys($post_data) : 'not_array'
        ], 'ERROR');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'errors' => $validation_errors
        ]);
        exit;
    }
    log_message("Валидация пройдена успешно", [], 'INFO');

    // Маппинг gender: Ж/ж/женский/female/F → false (женский), М/м/мужской/male/M → true (мужской)
    log_message("ШАГ 3: Маппинг и подготовка данных студента", [], 'INFO');
    $mapped_gender = null;
    if (isset($post_data['gender']) && !empty($post_data['gender'])) {
        $gender = trim((string)$post_data['gender']);
        $gender_lower = strtolower($gender);

        // Женский пол (false)
        if ($gender === 'Ж' || $gender === 'ж' || $gender_lower === 'женский' || $gender_lower === 'female' || $gender === 'F' || $gender === 'false' || $gender === '0') {
            $mapped_gender = false;
            log_message("Маппинг gender", ['исходное' => $gender, 'преобразовано' => 'false (женский)']);
        }
        // Мужской пол (true)
        elseif ($gender === 'М' || $gender === 'м' || $gender_lower === 'мужской' || $gender_lower === 'male' || $gender === 'M' || $gender === 'true' || $gender === '1') {
            $mapped_gender = true;
            log_message("Маппинг gender", ['исходное' => $gender, 'преобразовано' => 'true (мужской)']);
        }
    }

    $student_name = !empty($post_data['childName'])
        ? split_full_name($post_data['childName'])
        : [
            'firstName' => !empty($post_data['firstName']) ? trim($post_data['firstName']) : '',
            'lastName' => !empty($post_data['lastName']) ? trim($post_data['lastName']) : '',
            'middleName' => !empty($post_data['middleName']) ? trim($post_data['middleName']) : ''
        ];

    // Подготавливаем параметры для API
    // Если имя или фамилия не указаны, используем прочерки
    $firstName = sanitize_holly_name($student_name['firstName'] !== '' ? $student_name['firstName'] : '-');
    $lastName  = sanitize_holly_name($student_name['lastName']  !== '' ? $student_name['lastName']  : '-');

    $student_params = [
        'firstName' => $firstName,
        'lastName' => $lastName
    ];

    // Добавляем gender если был преобразован (булев формат: true/false)
    if ($mapped_gender !== null) {
        $student_params['gender'] = $mapped_gender;
    }

    // Базовые опциональные поля с маппингом имён (input => API)
    $optional_fields = [
        'middleName' => 'middleName',
        'birthDate' => 'birthday',
        'phone' => 'phone',
        'email' => 'email',
        'locationId' => 'locationId'
    ];

    if (!empty($student_name['middleName'])) {
        $post_data['middleName'] = $student_name['middleName'];
    }
    if (empty($post_data['birthDate']) && !empty($post_data['Дата рождения'])) {
        $post_data['birthDate'] = normalize_holly_birth_date($post_data['Дата рождения']);
    }
    if (empty($post_data['birthDate']) && !empty($post_data['childBirthDate'])) {
        $post_data['birthDate'] = normalize_holly_birth_date($post_data['childBirthDate']);
    }
    if (!empty($post_data['birthDate'])) {
        $post_data['birthDate'] = normalize_holly_birth_date($post_data['birthDate']);
    }

    foreach ($optional_fields as $post_field => $api_field) {
        if (!empty($post_data[$post_field])) {
            $value = $post_data[$post_field];
            if ($post_field === 'birthDate') {
                $value = normalize_holly_birth_date($value);
            }
            $student_params[$api_field] = $value;
        }
    }

    // Дополнительные поля для обучения
    if (!empty($post_data['discipline'])) {
        $student_params['discipline'] = $post_data['discipline'];
    }

    // level - маппинг названия в значение
    if (!empty($post_data['level'])) {
        // Маппинг level
        $level_mapping = [
            'С нуля' => 'A0',  // Латиница, а не кириллица
            'Индивидуальные' => 'Индивидуальный'
        ];

        $level_value = (string)$post_data['level'];

        // Если найдено в маппинге, используем маппированное значение
        if (isset($level_mapping[$level_value])) {
            $student_params['level'] = $level_mapping[$level_value];
            log_message("level: найден в маппинге", ['исходное' => $level_value, 'преобразовано' => $level_mapping[$level_value]]);
        }
        // Если не найдено, используем как есть
        else {
            $student_params['level'] = $level_value;
        }
    }

    // learningType - маппинг названия в значение
    if (!empty($post_data['learningType'])) {
        // Маппинг learningType
        $learning_type_mapping = [
            'Мини группа' => 'Мини-группа',
            'Мини-группа' => 'Мини-группа',
            'Минигруппа' => 'Мини-группа',
            'Стандарт' => 'Стандарт',
            'Индивидуальный' => 'Индивидуальный',
            'Индивидуально' => 'Индивидуальный',
            'Индивидуально онлайн' => 'Онлайн. Индивидуально',
            'Индивидуально очно' => 'Индивидуальный',
            'Индивидуальные' => 'Индивидуальный',
            'Курсы за рубежом' => 'Обучение за рубежом',
            'Группа' => 'Группа',
            'В группе' => 'Группа',
            'Группа онлайн' => 'Онлайн.Группа',
            'Группа очно' => 'Группа',
            'Полная группа' => 'Группа',
            'Общий' => 'Общий',
            'Интенсивный' => 'Интенсивный',
            'Спецкурс' => 'Спецкурс',
            'Бизнес' => 'Бизнес',
            'Летний лагерь в Москве' => 'Летний лагерь в Москве',
            'Корпоративное обучение' => 'Корпоративное обучение',
            'Лагерь за рубежом' => 'Лагерь за рубежом',
            'Обучение за рубежом' => 'Обучение за рубежом',
            'Языковой клуб' => 'Языковой клуб',
            'Поступление в вуз' => 'Поступление в ВУЗ',
            'Поступление в ВУЗ' => 'Поступление в ВУЗ',
            'Самостятельно/Актив' => 'Самостоятельно на платформе',
            'Самостоятельно/Актив' => 'Самостоятельно на платформе',
            'Самостоятельно с педагогом/ актив+' => 'Самостоятельно на платформе',
        ];

        $learning_type_value = (string)$post_data['learningType'];

        // Если найдено в маппинге, используем маппированное значение
        if (isset($learning_type_mapping[$learning_type_value])) {
            $student_params['learningType'] = $learning_type_mapping[$learning_type_value];
            log_message("learningType: найден в маппинге", ['исходное' => $learning_type_value, 'преобразовано' => $learning_type_mapping[$learning_type_value]]);
        }
        // Если не найдено, используем как есть
        else {
            $student_params['learningType'] = $learning_type_value;
            log_message("learningType: ⚠️ не найдено в маппинге, передаём как есть", ['value' => $learning_type_value]);
        }
    }

    // maturity - маппинг названия в значение
    if (!empty($post_data['maturity'])) {
        // Маппинг maturity
        $maturity_mapping = [
            'Дошкольники' => '4-6 лет',
            'Подростки' => 'Ст. школьники'
        ];

        $maturity_value = (string)$post_data['maturity'];

        // Если найдено в маппинге, используем маппированное значение
        if (isset($maturity_mapping[$maturity_value])) {
            $student_params['maturity'] = $maturity_mapping[$maturity_value];
            log_message("maturity: найден в маппинге", ['исходное' => $maturity_value, 'преобразовано' => $maturity_mapping[$maturity_value]]);
        }
        // Если не найдено, используем как есть
        else {
            $student_params['maturity'] = $maturity_value;
        }
    }

    // officeOrCompanyId - маппинг названия в ID
// officeOrCompanyId - маппинг названия в ID
if (!empty($post_data['officeOrCompanyId'])) {
    // Маппинг названия офиса в ID (с поддержкой разных регистров)
    $office_mapping = [
        'выезд' => 7,
        'Выезд' => 7,
        'ВЫЕЗД' => 7,
        'Красная Пресня' => 4,
        'красная пресня' => 4,
        'КР красная пресня' => 4,
        'Кр пресня' => 4,
        'кр пресня' => 4,
        'Курская' => 2,
        'курская' => 2,
        'Ломоносовский проспект' => 45,
        'ломоносовский проспект' => 45,
        'Немчиновка' => 30,
        'немчиновка' => 30,
        'Октябрьская' => 5,
        'октябрьская' => 5,
        'Онлайн-платформа' => 36,
        'онлайн-платформа' => 36,
        'Онлайн' => 36,
        'онлайн' => 36,
        'ООО Сфера-Строй М' => 66,
        'ооо сфера-строй м' => 66,
        'Таганская/Цветной бульвар' => 53,
        'таганская/цветной бульвар' => 53,
        'Территория Смоленка' => 46,
        'территория смоленка' => 46
    ];

    $office_value = trim((string)$post_data['officeOrCompanyId']);
    
    log_message("Маппинг officeOrCompanyId", [
        'original_value' => $office_value,
        'is_numeric' => is_numeric($office_value)
    ], 'INFO');
    
    // Если это число, используем как ID
    if (is_numeric($office_value)) {
        $student_params['officeOrCompanyId'] = (int)$office_value;
        log_message("officeOrCompanyId: используем числовой ID", [
            'value' => (int)$office_value
        ], 'INFO');
    }
    // Если строка, ищем в маппинге (регистронезависимо)
    else {
        // Пробуем найти точное совпадение
        if (isset($office_mapping[$office_value])) {
            $student_params['officeOrCompanyId'] = $office_mapping[$office_value];
            log_message("officeOrCompanyId: найдено точное совпадение", [
                'название' => $office_value,
                'ID' => $office_mapping[$office_value]
            ], 'INFO');
        } 
        // Пробуем найти без учета регистра
        else {
            $office_value_lower = mb_strtolower($office_value, 'UTF-8');
            $found = false;
            foreach ($office_mapping as $key => $id) {
                if (mb_strtolower($key, 'UTF-8') === $office_value_lower) {
                    $student_params['officeOrCompanyId'] = $id;
                    $found = true;
                    log_message("officeOrCompanyId: найдено совпадение без учета регистра", [
                        'название' => $office_value,
                        'найдено_по_ключу' => $key,
                        'ID' => $id
                    ], 'INFO');
                    break;
                }
            }
            
            // Если не найдено, пробуем частичное совпадение
            if (!$found) {
                foreach ($office_mapping as $key => $id) {
                    if (mb_stripos($office_value, $key, 0, 'UTF-8') !== false || 
                        mb_stripos($key, $office_value, 0, 'UTF-8') !== false) {
                        $student_params['officeOrCompanyId'] = $id;
                        $found = true;
                        log_message("officeOrCompanyId: найдено частичное совпадение", [
                            'название' => $office_value,
                            'найдено_по_ключу' => $key,
                            'ID' => $id
                        ], 'INFO');
                        break;
                    }
                }
            }
            
            if (!$found) {
                log_message("officeOrCompanyId: ⚠️ не найдено в маппинге, передаём как есть", [
                    'value' => $office_value
                ], 'WARNING');
                $student_params['officeOrCompanyId'] = $office_value;
            }
        }
    }
}

    // responsible_user - маппинг названия в значение
    if (!empty($post_data['responsible_user'])) {
        // Маппинг responsible_user
        $responsible_user_mapping = [
            'Наталья' => 'Наталья Владимировна старший администратор',
            'Александра' => 'Гид по обучению Александра',
            'Альбина' => 'Гид по обучению Альбина',
            'Елена' => 'Гид по обучению Елена',
            'Резервный менеджер' => 'Гид по обучению резервный'
        ];

        $responsible_user_value = (string)$post_data['responsible_user'];

        // Если найдено в маппинге, используем маппированное значение
        if (isset($responsible_user_mapping[$responsible_user_value])) {
            $student_params['responsible_user'] = $responsible_user_mapping[$responsible_user_value];
            log_message("responsible_user: найден в маппинге", ['исходное' => $responsible_user_value, 'преобразовано' => $responsible_user_mapping[$responsible_user_value]]);
        }
        // Если не найдено, используем как есть
        else {
            $student_params['responsible_user'] = $responsible_user_value;
        }
    }

    if (!empty($post_data['Status'])) {
        $student_params['status'] = $post_data['Status'];
    }

    // Логируем все параметры для отладки
    log_message("Подготовленные параметры для AddStudent API", [
        'params' => $student_params,
        'params_count' => count($student_params),
        'has_gender' => isset($student_params['gender']),
        'has_level' => isset($student_params['level']),
        'has_learningType' => isset($student_params['learningType']),
        'has_officeOrCompanyId' => isset($student_params['officeOrCompanyId'])
    ], 'INFO');

    // Мьютекс по телефону/lead_id: предотвращает дубли при параллельных вебхуках.
    // Второй процесс ждёт здесь, пока первый не создаст студента и не снимет блокировку.
    // После захвата блокировки поиск повторяется — чтобы найти студента, созданного первым процессом.
    $_lock_key = '';
    if (!empty($post_data['phone'])) {
        $_lp = preg_replace('/\D/', '', (string)$post_data['phone']);
        if (strlen($_lp) === 11 && substr($_lp, 0, 1) === '8') {
            $_lp = '7' . substr($_lp, 1);
        }
        $_lock_key = $_lp;
    } elseif (!empty($post_data['amo_lead_id'])) {
        $_lock_key = 'lead_' . (int)$post_data['amo_lead_id'];
    }
    $_lock_fp = null;
    if ($_lock_key !== '') {
        $_lock_dir = __DIR__ . '/locks';
        if (!is_dir($_lock_dir)) {
            mkdir($_lock_dir, 0755, true);
        }
        $_lock_file = $_lock_dir . '/student_' . md5($_lock_key) . '.lock';
        log_message("Попытка открыть lock-файл", ['lock_file' => $_lock_file, 'lock_key' => $_lock_key], 'INFO');
        $_lock_fp = fopen($_lock_file, 'w');
        if ($_lock_fp) {
            log_message("Ожидание мьютекса (anti-duplicate lock)", ['lock_key' => $_lock_key], 'INFO');
            flock($_lock_fp, LOCK_EX);
            log_message("Мьютекс захвачен", ['lock_key' => $_lock_key], 'INFO');
        } else {
            log_message("Не удалось открыть lock-файл, продолжаем без блокировки", ['lock_file' => $_lock_file], 'WARNING');
        }
    }

    // Проверяем, существует ли уже студент с таким же именем и телефоном
    log_message("ШАГ 4: Поиск существующего студента по телефону", [], 'INFO');
    $existing_student = null;
    $is_update = false;

    // Если в AMO-сделке уже есть ссылка на профиль Холи — используем этот профиль напрямую.
    // Это защита от дублей: когда AMO присылает два вебхука для одной сделки почти одновременно,
    // второй запрос найдёт уже записанный профиль и не создаст нового студента.
    $existing_profile_id = isset($post_data['existing_profile_id']) ? (int)$post_data['existing_profile_id'] : 0;
    if ($existing_profile_id > 0) {
        log_message("Найден existing_profile_id в данных — ищем студента по Id", [
            'existing_profile_id' => $existing_profile_id
        ], 'INFO');
        try {
            $profile_response = call_hollyhop_api('GetStudents', ['Id' => $existing_profile_id], $auth_key, $api_base_url);
            $profile_students = $profile_response['Students'] ?? (isset($profile_response['Id']) ? [$profile_response] : []);
            foreach ((array)$profile_students as $s) {
                $sid = $s['Id'] ?? $s['id'] ?? null;
                if ((int)$sid === $existing_profile_id) {
                    $existing_student = $s;
                    $is_update = true;
                    log_message("✓ Студент найден по existing_profile_id, пропускаем создание", [
                        'Id' => $existing_profile_id,
                        'ClientId' => $s['ClientId'] ?? $s['clientId'] ?? null
                    ], 'INFO');
                    break;
                }
            }
        } catch (Exception $e) {
            log_message("Не удалось найти студента по existing_profile_id, продолжаем поиск по телефону", [
                'existing_profile_id' => $existing_profile_id,
                'error' => $e->getMessage()
            ], 'WARNING');
        }
    }

    $search_phones = [];
    $candidate_search_phones = [
        $post_data['phone'] ?? '',
        $post_data['parentPhone'] ?? '',
        $post_data['parentEmergencyPhone'] ?? ''
    ];
    foreach ($candidate_search_phones as $candidate_phone) {
        $candidate_phone = trim((string)$candidate_phone);
        if ($candidate_phone !== '' && !in_array($candidate_phone, $search_phones, true)) {
            $search_phones[] = $candidate_phone;
        }
    }

    if (!empty($search_phones) && $existing_student === null) {
        try {
            log_message("Поиск существующего студента по телефонам", [
                'phones' => $search_phones
            ]);

            // Нормализуем телефон для сравнения (как в search_student_by_phone.php)
            $normalize_phone = function ($phone) {
                if (empty($phone)) return '';
                // Убираем все кроме цифр
                $normalized = preg_replace('/\D/', '', $phone);
                // Если телефон начинается с 8, заменяем на 7
                if (strlen($normalized) === 11 && substr($normalized, 0, 1) === '8') {
                    $normalized = '7' . substr($normalized, 1);
                }
                return $normalized;
            };

            // Получение всех телефонов из объекта студента и его контактов
            $get_student_phones = function ($student) {
                if (!is_array($student)) {
                    return [];
                }

                $phones = [];
                $phone_fields = ['Phone', 'phone', 'Mobile', 'mobile', 'Telephone', 'telephone'];

                foreach ($phone_fields as $field) {
                    if (isset($student[$field]) && !empty($student[$field])) {
                        $phone = trim((string)$student[$field]);
                        if ($phone !== '' && !in_array($phone, $phones, true)) {
                            $phones[] = $phone;
                        }
                    }
                }

                if (isset($student['Agents']) && is_array($student['Agents']) && !empty($student['Agents'])) {
                    foreach ($student['Agents'] as $agent) {
                        foreach ($phone_fields as $field) {
                            if (isset($agent[$field]) && !empty($agent[$field])) {
                                $phone = trim((string)$agent[$field]);
                                if ($phone !== '' && !in_array($phone, $phones, true)) {
                                    $phones[] = $phone;
                                }
                            }
                        }
                    }
                }

                return $phones;
            };

            $all_candidates = [];

            foreach ($search_phones as $search_phone) {
                $normalized_search_phone = $normalize_phone($search_phone);
                log_message("Нормализованный номер для поиска", [
                    'original' => $search_phone,
                    'normalized' => $normalized_search_phone
                ]);

                $search_attempts = [
                    ['phone' => $search_phone],
                    ['term' => $search_phone],
                    ['search' => $search_phone],
                    ['q' => $search_phone],
                ];

                foreach ($search_attempts as $attempt) {
                    $param_name = array_key_first($attempt);
                    try {
                        log_message("Попытка поиска с параметром: {$param_name}", $attempt);

                        $search_response = call_hollyhop_api('GetStudents', $attempt, $auth_key, $api_base_url);

                        log_message("Ответ GetStudents получен для параметра {$param_name}", [
                            'type' => gettype($search_response),
                            'is_empty' => empty($search_response),
                            'is_array' => is_array($search_response)
                        ]);

                        $candidates = [];

                        if (is_array($search_response)) {
                            if (isset($search_response[0]) && is_array($search_response[0])) {
                                $candidates = $search_response;
                            } elseif (
                                isset($search_response['Id']) || isset($search_response['id']) ||
                                isset($search_response['ClientId']) || isset($search_response['clientId'])
                            ) {
                                $candidates = [$search_response];
                            } elseif (isset($search_response['Students']) && is_array($search_response['Students'])) {
                                $candidates = $search_response['Students'];
                            }
                        }

                        // Если поиск по параметру `phone` вернул результаты — доверяем им
                        // напрямую. Телефон в Hollyhop хранится в системе Agents/Contacts
                        // и не всегда присутствует в полях student-объекта, поэтому
                        // повторная проверка по полям Phone/phone ниже может дать 0 совпадений
                        // и привести к созданию дубликата.
                        if ($param_name === 'phone' && count($candidates) === 1) {
                            $trusted = $candidates[0];
                            if (is_array($trusted)) {
                                log_message("✓ phone-поиск вернул 1 студента, используем напрямую без перепроверки телефона", [
                                    'Id'       => $trusted['Id'] ?? $trusted['id'] ?? null,
                                    'ClientId' => $trusted['ClientId'] ?? $trusted['clientId'] ?? null,
                                ]);
                                $existing_student = $trusted;
                                $is_update = true;
                                break 2; // выходим из foreach $search_phones и foreach $search_attempts
                            }
                        }

                        foreach ($candidates as $candidate) {
                            if (!is_array($candidate)) {
                                continue;
                            }

                            $candidate_id = $candidate['Id'] ?? $candidate['id'] ??
                                $candidate['ClientId'] ?? $candidate['clientId'] ?? null;

                            $exists = false;
                            foreach ($all_candidates as $existing) {
                                $existing_id = $existing['Id'] ?? $existing['id'] ??
                                    $existing['ClientId'] ?? $existing['clientId'] ?? null;
                                if ($existing_id && $candidate_id && $existing_id == $candidate_id) {
                                    $exists = true;
                                    break;
                                }
                            }

                            if (!$exists) {
                                $all_candidates[] = $candidate;
                            }
                        }
                    } catch (Exception $attempt_e) {
                        log_message("Попытка поиска с {$param_name} не сработала: " . $attempt_e->getMessage());
                        continue;
                    }
                }
            }

            $normalized_search_phones = [];
            foreach ($search_phones as $search_phone) {
                $normalized_search_phone = $normalize_phone($search_phone);
                if ($normalized_search_phone !== '' && !in_array($normalized_search_phone, $normalized_search_phones, true)) {
                    $normalized_search_phones[] = $normalized_search_phone;
                }
            }

            $matched_students = [];

            foreach ($all_candidates as $candidate) {
                $candidate_phones = $get_student_phones($candidate);
                foreach ($candidate_phones as $candidate_phone) {
                    $normalized_candidate_phone = $normalize_phone($candidate_phone);
                    if ($normalized_candidate_phone !== '' && in_array($normalized_candidate_phone, $normalized_search_phones, true)) {
                        $matched_students[] = $candidate;
                        break;
                    }
                }
            }

            log_message("Результаты поиска", [
                'total_candidates' => count($all_candidates),
                'matched_students' => count($matched_students),
                'search_phones' => $search_phones
            ]);

            if (!empty($matched_students)) {
                $existing_student = $matched_students[0];
                $is_update = true;
                log_message("✓✓✓ НАЙДЕН студент с ТОЧНЫМ совпадением одного из телефонов", [
                    'Id' => $existing_student['Id'] ?? $existing_student['id'] ?? 'не найден',
                    'ClientId' => $existing_student['ClientId'] ?? $existing_student['clientId'] ?? 'не найден',
                    'total_matched' => count($matched_students),
                    'search_phones' => $search_phones
                ]);
            } else {
                log_message("⚠️ Студент не найден ни по одному из телефонов, будет создан новый", [
                    'phones' => $search_phones,
                    'normalized_phones' => $normalized_search_phones,
                    'total_candidates' => count($all_candidates)
                ]);
            }
        } catch (Exception $e) {
            log_message("Ошибка при поиске студента по телефонам: " . $e->getMessage() . ". Создаём нового.");
        }
    } else {
        log_message("Телефоны не указаны, пропускаем проверку существующего студента");
    }

    // Если найден существующий студент - пропускаем обновление, просто используем его данные
    if ($is_update && $existing_student) {
        $existing_id = $existing_student['Id'] ?? $existing_student['id'] ?? null;
        $existing_client_id = $existing_student['ClientId'] ?? $existing_student['clientId'] ?? null;

        if ($existing_id || $existing_client_id) {
            log_message("Студент найден по телефону, данные не обновляем, используем существующие", [
                'Id' => $existing_id,
                'ClientId' => $existing_client_id
            ]);

            // Не вызываем EditPersonal - просто используем существующие данные
            // Результат не нужен, так как мы не вызываем API
            $result = null;
        } else {
            log_message("⚠️ Не удалось определить Id существующего студента, создаём нового");
            $is_update = false;
            $existing_student = null;
        }
    }

    // Если студент не найден - создаём нового
    log_message("ШАГ 5: Создание или обновление студента", [
        'is_update' => $is_update,
        'has_existing_student' => $existing_student !== null
    ], 'INFO');

    if (!$is_update) {
        log_message("Создание нового студента через AddStudent API", [], 'INFO');
        // Убираем Id и ClientId из параметров, если они были добавлены
        unset($student_params['Id']);
        unset($student_params['ClientId']);

        // Отправляем запрос к API для создания студента
        log_message("AddStudent params", $student_params, 'INFO');
        $result = call_hollyhop_api('AddStudent', $student_params, $auth_key, $api_base_url);

        // Проверяем, что результат получен
        if (!isset($result)) {
            log_message("КРИТИЧЕСКАЯ ОШИБКА: AddStudent API не вернул результат", [], 'ERROR');
            throw new Exception("Не удалось получить результат от API (AddStudent)");
        }

        // Логируем полный ответ для отладки
        log_message("Ответ от AddStudent API получен", [
            'result_type' => gettype($result),
            'result_keys' => is_array($result) ? array_keys($result) : 'not_array',
            'result_preview' => is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string)$result
        ], 'INFO');
    } else {
        log_message("Пропуск создания студента - используется существующий", [
            'existing_id' => $existing_student['Id'] ?? $existing_student['id'] ?? 'не найден',
            'existing_clientId' => $existing_student['ClientId'] ?? $existing_student['clientId'] ?? 'не найден'
        ], 'INFO');
    }

    // Снимаем мьютекс — студент создан (или найден), следующий параллельный процесс может продолжать
    if ($_lock_fp) {
        flock($_lock_fp, LOCK_UN);
        fclose($_lock_fp);
        log_message("Мьютекс освобождён", ['lock_key' => $_lock_key], 'INFO');
    }

    // Инициализируем переменные для ID студента
    log_message("ШАГ 6: Извлечение ID студента из ответа", [], 'INFO');
    $student_id = null;
    $client_id = null;

    // Если это существующий студент, используем данные из найденного студента
    if ($is_update && $existing_student) {
        $student_id = $existing_student['Id'] ?? $existing_student['id'] ?? null;
        $client_id = $existing_student['ClientId'] ?? $existing_student['clientId'] ?? null;

        if (!$student_id) {
            $student_id = $client_id; // Fallback на ClientId
        }
        if (!$client_id) {
            $client_id = $student_id; // Fallback на Id
        }

        log_message("Используем данные существующего студента", [
            'Id' => $student_id,
            'ClientId' => $client_id
        ]);
    }

    // Получаем Id созданного студента из ответа AddStudent (только если студент был создан)
    // Пробуем разные варианты названия поля (Id, id, ID, studentId, clientId)
    if (!$student_id && isset($result)) {
        if (isset($result['Id'])) {
            $student_id = $result['Id'];
        } elseif (isset($result['id'])) {
            $student_id = $result['id'];
        } elseif (isset($result['ID'])) {
            $student_id = $result['ID'];
        } elseif (isset($result['studentId'])) {
            $student_id = $result['studentId'];
        } elseif (isset($result['ClientId'])) {
            $student_id = $result['ClientId'];
        } elseif (isset($result['clientId'])) {
            $student_id = $result['clientId'];
        } elseif (is_numeric($result)) {
            // Если ответ - просто число
            $student_id = $result;
        } elseif (is_array($result) && count($result) > 0) {
            // Если ответ - массив, пробуем взять первый элемент
            $first_item = $result[0];
            if (isset($first_item['Id'])) {
                $student_id = $first_item['Id'];
            } elseif (isset($first_item['id'])) {
                $student_id = $first_item['id'];
            } elseif (isset($first_item['ClientId'])) {
                $student_id = $first_item['ClientId'];
            }
        }
    }

    if (!$student_id) {
        // Логируем полный ответ для отладки
        $response_keys = is_array($result) ? array_keys($result) : 'not_array';
        $response_preview = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string)$result;

        log_message("ОШИБКА: Не удалось извлечь Id из ответа API", [
            'response' => $result,
            'response_type' => gettype($result),
            'response_keys' => $response_keys,
            'response_preview' => substr($response_preview, 0, 1000) // Первые 1000 символов
        ]);

        // Формируем понятное сообщение об ошибке
        $error_message = "API не вернул Id студента. ";
        if (is_array($result) && !empty($result)) {
            $error_message .= "Найдены поля: " . implode(', ', $response_keys) . ". ";
            $error_message .= "Проверьте логи для деталей.";
        } else {
            $error_message .= "Ответ: " . substr($response_preview, 0, 200);
        }

        throw new Exception($error_message);
    }

    if ($is_update) {
        log_message("Используется существующий студент (данные не обновляются), Id: {$student_id}");
    } else {
        log_message("Студент успешно добавлен, Id: {$student_id}", $result);

        // Получаем ClientId из ответа AddStudent (только для нового студента)
        if (isset($result['ClientId'])) {
            $client_id = $result['ClientId'];
        } elseif (isset($result['clientId'])) {
            $client_id = $result['clientId'];
        }

        log_message("ClientId полученный из API: {$client_id}");
    }

    // Если ClientId всё ещё не был получен, используем student_id как fallback
    if (!$client_id && $student_id) {
        $client_id = $student_id;
        log_message("Используем student_id как clientId (fallback): {$client_id}");
    }

    // Обновляем контактные данные через EditContacts для новых и существующих студентов.
    // Иначе при повторной синхронизации телефон и email в Hollyhop могут оставаться старыми.
    log_message("ШАГ 7: Обновление контактных данных", [
        'is_update' => $is_update,
        'has_client_id' => !empty($client_id),
        'has_phone' => !empty($post_data['phone']),
        'has_email' => !empty($post_data['email']),
        'has_telegram' => !empty($post_data['telegram'])
    ], 'INFO');

    $student_contact_phone = trim((string)($post_data['phone'] ?? $post_data['parentPhone'] ?? ''));
    $student_contact_email = trim((string)($post_data['email'] ?? $post_data['parentEmail'] ?? ''));

    if ($client_id && (
        $student_contact_phone !== ''
        || $student_contact_email !== ''
        || !empty($post_data['telegram'])
    )) {
        log_message("Вызов EditContacts для обновления контактных данных студента", [
            'client_id' => $client_id
        ], 'INFO');
        try {
            $edit_contacts_params = [
                'StudentClientId' => $client_id,
                'useMobileBySystem' => false, // По умолчанию false
                'useEMailBySystem' => false   // Обязательное поле, даже если email не указан
            ];

            // Добавляем мобильный телефон, если указан
            if ($student_contact_phone !== '') {
                $edit_contacts_params['mobile'] = $student_contact_phone;
                $edit_contacts_params['useMobileBySystem'] = false; // запрещаем использование системой
                log_message("Подготовка обновления контактов: телефон указан", ['phone' => $edit_contacts_params['mobile']]);
            }

            // Добавляем email, если указан
            if ($student_contact_email !== '') {
                $edit_contacts_params['eMail'] = $student_contact_email;
                $edit_contacts_params['useEMailBySystem'] = false; // запрещаем использование системой
                log_message("Подготовка обновления контактов: email указан", ['email' => $edit_contacts_params['eMail']]);
            }

            if (!empty($post_data['telegram'])) {
                $telegram_value = trim((string)$post_data['telegram']);
                if ($telegram_value !== '') {
                    if (!preg_match('~^https?://~i', $telegram_value)) {
                        $telegram_value = ltrim($telegram_value, '@');
                        $telegram_value = 'https://t.me/' . $telegram_value;
                    }
                    $edit_contacts_params['socialNetworkPage'] = $telegram_value;
                    log_message("Подготовка обновления контактов: соцсеть указана", [
                        'socialNetworkPage' => $edit_contacts_params['socialNetworkPage']
                    ]);
                }
            }

            // useEMailBySystem должно быть всегда передано (обязательное поле API)
            // Если email не указан, оставляем false

            log_message("Вызов EditContacts для обновления контактных данных", $edit_contacts_params);
            $edit_contacts_result = call_hollyhop_api('EditContacts', $edit_contacts_params, $auth_key, $api_base_url);
            log_message("EditContacts выполнен успешно", $edit_contacts_result);
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            log_error("Ошибка при обновлении контактов через EditContacts", [
                'error' => $e->getMessage(),
                'client_id' => $client_id,
                'params' => $edit_contacts_params ?? []
            ]);
        }
    }

    // Получаем полный профиль студента для получения настоящего id (как в URL профиля)
    log_message("ШАГ 8: Получение полного профиля студента через GetStudents", [
        'client_id' => $client_id,
        'student_id' => $student_id
    ], 'INFO');

    $student_id_from_api = null;
    $student_info = null;
    try {
        log_message("Запрос GetStudents с clientId: {$client_id}", [], 'INFO');

        // Запрашиваем GetStudents для поиска студента по clientId
        // Используем clientId вместо studentId - это работает для прямого поиска
        $get_student_params = [
            'clientId' => $client_id
        ];

        log_message("Запрос GetStudents с параметром clientId (прямой поиск)");
        $api_response = call_hollyhop_api('GetStudents', $get_student_params, $auth_key, $api_base_url);

        // API может вернуть:
        // 1. Объект с полем Students (массив студентов)
        // 2. Массив студентов напрямую
        // 3. Один объект студента (при использовании clientId)
        $all_students = null;
        $direct_student = null;

        if (is_array($api_response)) {
            // Проверяем, это массив студентов или объект с полем Students
            if (isset($api_response['Students']) && is_array($api_response['Students'])) {
                $all_students = $api_response['Students'];
                log_message("Ответ GetStudents: объект с полем Students", [
                    'students_count' => count($all_students),
                    'now' => $api_response['Now'] ?? 'не указано'
                ]);
            } elseif (isset($api_response['ClientId']) || isset($api_response['clientId'])) {
                // Это один студент (при использовании clientId)
                $direct_student = $api_response;
                log_message("Ответ GetStudents: один студент (прямой ответ)", [
                    'ClientId' => $direct_student['ClientId'] ?? $direct_student['clientId'] ?? 'не найден',
                    'Id' => $direct_student['Id'] ?? $direct_student['id'] ?? 'не найден'
                ]);
            } else {
                // Это массив студентов напрямую
                $all_students = $api_response;
                log_message("Ответ GetStudents: массив студентов напрямую", [
                    'count' => count($all_students)
                ]);
            }
        } else {
            log_message("⚠️ ВНИМАНИЕ: GetStudents вернул не массив", [
                'type' => gettype($api_response)
            ]);
        }

        log_message("Ответ от GetStudents API получен", [
            'has_direct_student' => $direct_student !== null,
            'is_array' => is_array($all_students),
            'count' => is_array($all_students) ? count($all_students) : 'не массив'
        ]);

        // Проверяем, получили ли мы студента напрямую (при использовании clientId)
        if ($direct_student !== null) {
            log_message("✓ Получен студент напрямую через clientId");
            $student_info = $direct_student;

            // Извлекаем Id профиля
            if (isset($direct_student['Id'])) {
                $student_id_from_api = $direct_student['Id'];
                log_message("✓ Получен Id профиля: {$student_id_from_api}");
            } elseif (isset($direct_student['id'])) {
                $student_id_from_api = $direct_student['id'];
                log_message("✓ Получен id профиля (малые буквы): {$student_id_from_api}");
            } else {
                $student_id_from_api = $client_id;
                log_message("⚠ Id профиля не найден, используем ClientId: {$student_id_from_api}");
            }
        }
        // GetStudents возвращает массив студентов - ищем студента с нужным ClientId
        elseif ($all_students === null) {
            log_message("⚠️ ВНИМАНИЕ: Не удалось извлечь массив студентов из ответа GetStudents");
            $student_id_from_api = $client_id;
        } elseif (is_array($all_students)) {
            if (empty($all_students)) {
                log_message("⚠ ВНИМАНИЕ: GetStudents вернул пустой массив!");
            } else {
                log_message("Поиск студента с ClientId = {$client_id} в массиве из " . count($all_students) . " студентов");

                // Логируем информацию о первых 3 студентах для отладки
                for ($i = 0; $i < min(3, count($all_students)); $i++) {
                    $student = $all_students[$i];
                    if (is_array($student)) {
                        $first_keys = array_keys($student);
                        log_message("Структура студента #{$i}", [
                            'keys' => $first_keys,
                            'ClientId' => $student['ClientId'] ?? $student['clientId'] ?? 'не найден',
                            'Id' => $student['Id'] ?? $student['id'] ?? 'не найден'
                        ]);
                    }
                }

                // Проходим по всем студентам в ответе
                foreach ($all_students as $idx => $student) {
                    if (!is_array($student)) {
                        continue;
                    }

                    // Проверяем ClientId студента
                    $student_client_id = $student['ClientId'] ?? $student['clientId'] ?? null;
                    $student_id_field = $student['Id'] ?? $student['id'] ?? 'не найден';

                    // Логируем каждую проверку (но не все, чтобы не загромождать логи)
                    if ($idx < 5 || $student_client_id == $client_id) {
                        log_message("Студент #{$idx}: ClientId={$student_client_id}, Id={$student_id_field}");
                    }

                    if ($student_client_id == $client_id) {
                        log_message("✓ НАЙДЕН студент #{$idx} с ClientId = {$client_id}");
                        $student_info = $student;

                        // Извлекаем Id профиля из найденного студента
                        if (isset($student['Id'])) {
                            $student_id_from_api = $student['Id'];
                            log_message("✓ Получен Id профиля: {$student_id_from_api}");
                        } elseif (isset($student['id'])) {
                            $student_id_from_api = $student['id'];
                            log_message("✓ Получен id профиля (малые буквы): {$student_id_from_api}");
                        }

                        break; // Нашли студента, выходим из цикла
                    }
                }

                // Если студент не найден по ClientId
                if (!$student_info) {
                    log_message("⚠ ВНИМАНИЕ: Студент с ClientId = {$client_id} НЕ НАЙДЕН в массиве из " . count($all_students) . " студентов");

                    // Логируем ClientIds всех студентов для отладки и находим min/max
                    $all_client_ids = [];
                    foreach ($all_students as $student) {
                        if (is_array($student)) {
                            $cid = $student['ClientId'] ?? $student['clientId'] ?? null;
                            if ($cid) {
                                $all_client_ids[] = $cid;
                            }
                        }
                    }

                    if (!empty($all_client_ids)) {
                        $min_cid = min($all_client_ids);
                        $max_cid = max($all_client_ids);
                        log_message("Диапазон ClientIds в ответе", [
                            'min' => $min_cid,
                            'max' => $max_cid,
                            'ищем' => $client_id,
                            'всего_студентов' => count($all_students)
                        ]);

                        if ($client_id > $max_cid) {
                            log_message("⚠⚠⚠ КРИТИЧНО: Искомый ClientId ({$client_id}) больше максимального ({$max_cid})!");
                            log_message("Студент находится за пределами первых " . count($all_students) . " студентов.");
                            log_message("API вернул только первые " . count($all_students) . " студентов, но студент с ClientId={$client_id} находится дальше.");
                            log_message("РЕШЕНИЕ: Используем параметр count=10000, но если студентов больше - нужна пагинация.");
                        }
                    }

                    log_message("ClientIds в ответе GetStudents (первые 20)", array_slice($all_client_ids, 0, 20));

                    // Берём первого студента как fallback
                    if (isset($all_students[0])) {
                        log_message("Используем первого студента из ответа как fallback");
                        $student_info = $all_students[0];

                        if (isset($student_info['Id'])) {
                            $student_id_from_api = $student_info['Id'];
                        } elseif (isset($student_info['id'])) {
                            $student_id_from_api = $student_info['id'];
                        }
                    }
                }
            }
        } else {
            log_message("⚠ ВНИМАНИЕ: GetStudents вернул не массив, а: " . gettype($all_students));
        }

        if (!$student_id_from_api) {
            log_message("⚠ ВНИМАНИЕ: Id профиля не найден в ответе GetStudents. Fallback на ClientId");
            $student_id_from_api = $client_id;
        }
    } catch (Exception $e) {
        // Если не удалось получить id, используем ClientId как fallback
        log_message("Ошибка при запросе GetStudents: " . $e->getMessage());
        $student_id_from_api = $client_id;
    }

    $ind_client_params_payload = build_ind_client_params_payload($post_data, $client_id);
    if ($ind_client_params_payload !== null) {
        try {
            log_message("Обновление параметров ученика через EditIndClientParams", $ind_client_params_payload, 'INFO');
            $edit_ind_client_params_result = call_hollyhop_api('EditIndClientParams', $ind_client_params_payload, $auth_key, $api_base_url);
            log_message("EditIndClientParams выполнен успешно", $edit_ind_client_params_result, 'INFO');
        } catch (Exception $e) {
            log_message("Ошибка при обновлении параметров ученика через EditIndClientParams", [
                'error' => $e->getMessage(),
                'client_id' => $client_id,
                'params' => $ind_client_params_payload
            ], 'WARNING');
        }
    }

    $extra_fields_update_payload = build_extra_fields_update_payload($student_info, $post_data, $client_id);
    if ($extra_fields_update_payload !== null) {
        try {
            log_message("Обновление пользовательских полей ученика через EditUserExtraFields", [
                'studentClientId' => $extra_fields_update_payload['studentClientId'],
                'fields' => $extra_fields_update_payload['fields']
            ], 'INFO');
            $edit_user_extra_fields_result = call_hollyhop_api('EditUserExtraFields', $extra_fields_update_payload, $auth_key, $api_base_url);
            log_message("EditUserExtraFields выполнен успешно", $edit_user_extra_fields_result, 'INFO');
        } catch (Exception $e) {
            log_message("Ошибка при обновлении пользовательских полей через EditUserExtraFields", [
                'error' => $e->getMessage(),
                'client_id' => $client_id,
                'field_names' => array_map(function ($field) {
                    return $field['name'] ?? 'unknown';
                }, $extra_fields_update_payload['fields'] ?? [])
            ], 'WARNING');
        }
    }

    if ($client_id && (!empty($post_data['childName']) || !empty($post_data['birthDate']) || !empty($post_data['childBirthDate']))) {
        $edit_personal_params = [
            'studentClientId' => $client_id,
            'firstName' => $firstName,
            'lastName' => $lastName
        ];

        if (!empty($student_name['middleName'])) {
            $edit_personal_params['middleName'] = $student_name['middleName'];
        }
        if ($mapped_gender !== null) {
            $edit_personal_params['gender'] = $mapped_gender;
        }
        $normalized_personal_birthday = normalize_holly_birth_date(
            $post_data['birthDate'] ?? $post_data['childBirthDate'] ?? $post_data['Дата рождения'] ?? ''
        );
        if ($normalized_personal_birthday !== '') {
            $edit_personal_params['birthday'] = $normalized_personal_birthday;
        }

        try {
            log_message("Обновление персональных данных ученика через EditPersonal", $edit_personal_params, 'INFO');
            $edit_personal_result = call_hollyhop_api('EditPersonal', $edit_personal_params, $auth_key, $api_base_url);
            log_message("EditPersonal выполнен успешно", $edit_personal_result, 'INFO');
        } catch (Exception $e) {
            log_message("Ошибка при обновлении персональных данных через EditPersonal", [
                'error' => $e->getMessage(),
                'client_id' => $client_id
            ], 'WARNING');
        }
    }

    $agent_contacts_payload = build_agent_contacts_payload($student_info, $post_data, $client_id);
    if ($agent_contacts_payload !== null) {
        try {
            log_message("Обновление контактного лица через EditAgentContacts", $agent_contacts_payload, 'INFO');
            $edit_agent_contacts_result = call_hollyhop_api('EditAgentContacts', $agent_contacts_payload, $auth_key, $api_base_url);
            log_message("EditAgentContacts выполнен успешно", $edit_agent_contacts_result, 'INFO');
        } catch (Exception $e) {
            log_message("Ошибка при обновлении контактного лица через EditAgentContacts", [
                'error' => $e->getMessage(),
                'client_id' => $client_id
            ], 'WARNING');
        }
    }

    // Формируем ответ в новом формате
    log_message("ШАГ 9: Формирование ответа", [
        'student_id_from_api' => $student_id_from_api,
        'client_id' => $client_id,
        'is_update' => $is_update
    ], 'INFO');

    $subdomain = $api_config['subdomain'];
    $profile_id = $student_id_from_api ?: $client_id;

    // Определяем статус операции
    $operation = $is_update ? 'updated' : 'created';
    $operation_text = $is_update ? 'Студент обновлен' : 'Студент создан';

    $response_data = [
        'success' => true,
        'operation' => $operation,
        'operation_text' => $operation_text,
        'search_result' => [
            'found' => $is_update,
            'found_text' => $is_update ? 'Студент найден в базе' : 'Студент не найден, создан новый',
            'phone' => $post_data['phone'] ?? null
        ],
        'clientId' => $client_id,
        'Id' => $profile_id,
        'link' => "https://{$subdomain}.t8s.ru/Profile/{$profile_id}"
    ];

    if ($is_update && $existing_student) {
        $response_data['existing_student'] = [
            'firstName' => $existing_student['FirstName'] ?? $existing_student['firstName'] ?? '',
            'lastName' => $existing_student['LastName'] ?? $existing_student['lastName'] ?? '',
            'phone' => $existing_student['Phone'] ?? $existing_student['phone'] ?? $existing_student['Mobile'] ?? $existing_student['mobile'] ?? ''
        ];
    }

    $student_extra_fields = build_student_extra_fields_from_post_data($post_data);
    $student_extra_field_names = array_map(function ($field) {
        return normalize_extra_field_name($field['name'] ?? '');
    }, $student_extra_fields);

    // Обновляем поле "Сделки АМО" в Hollyhop, если передан lead_id из AmoCRM
    log_message("ШАГ 10: Обновление поля 'Сделки АМО' в Hollyhop", [
        'has_amo_lead_id' => isset($post_data['amo_lead_id']) && !empty($post_data['amo_lead_id']),
        'has_client_id' => !empty($client_id),
        'amo_lead_id' => $post_data['amo_lead_id'] ?? null
    ], 'INFO');

    if (isset($post_data['amo_lead_id']) && !empty($post_data['amo_lead_id']) && $client_id) {
        log_message("Начало обработки поля 'Сделки АМО'", [
            'amo_lead_id' => $post_data['amo_lead_id'],
            'client_id' => $client_id
        ], 'INFO');

        $amo_lead_id = (int)$post_data['amo_lead_id'];
        $amo_subdomain = $post_data['amo_subdomain'] ?? 'directorchinatutorru';

        // Получаем имя ответственного менеджера из AmoCRM
        log_message("Получение имени менеджера из AmoCRM", [
            'amo_lead_id' => $amo_lead_id,
            'amo_subdomain' => $amo_subdomain
        ], 'INFO');

        $manager_name = 'Неизвестно';
        if (file_exists(__DIR__ . '/amo_func.php')) {
            require_once __DIR__ . '/amo_func.php';
            try {
                // Получаем данные сделки
                log_message("Запрос данных сделки из AmoCRM", [
                    'api_url' => '/api/v4/leads/' . $amo_lead_id
                ], 'INFO');

                $api_url = '/api/v4/leads/' . $amo_lead_id;
                $LEAD = get($amo_subdomain, $api_url, $data);

                log_message("Данные сделки получены из AmoCRM", [
                    'has_responsible_user_id' => isset($LEAD["responsible_user_id"]),
                    'responsible_user_id' => $LEAD["responsible_user_id"] ?? null
                ], 'INFO');

                if (isset($LEAD["responsible_user_id"])) {
                    $responsible_user_id = (int)$LEAD["responsible_user_id"];
                    $api_url = '/api/v4/users/' . $responsible_user_id;
                    $USER = get($amo_subdomain, $api_url, $data);

                    // API может вернуть объект напрямую или в массиве
                    if (isset($USER["name"])) {
                        $manager_name = $USER["name"];
                    } elseif (isset($USER[0]["name"])) {
                        $manager_name = $USER[0]["name"];
                    } elseif (is_array($USER) && !empty($USER)) {
                        // Если вернулся массив пользователей, берем первого
                        $first_user = reset($USER);
                        if (isset($first_user["name"])) {
                            $manager_name = $first_user["name"];
                        }
                    }

                    log_message("Имя менеджера получено из AmoCRM", [
                        'responsible_user_id' => $responsible_user_id,
                        'manager_name' => $manager_name,
                        'lead_id' => $amo_lead_id,
                        'user_response_structure' => is_array($USER) ? 'array' : 'object'
                    ], 'INFO');
                } else {
                    log_message("У сделки не указан ответственный менеджер", [
                        'lead_id' => $amo_lead_id
                    ], 'WARNING');
                }
            } catch (Exception $e) {
                log_message("Не удалось получить имя менеджера из AmoCRM", [
                    'lead_id' => $amo_lead_id,
                    'error' => $e->getMessage()
                ], 'WARNING');
            }
        } else {
            log_message("Файл amo_func.php не найден, используем значение по умолчанию для менеджера", [], 'WARNING');
        }

        // Формируем HTML ссылку в формате "Менеджер: ID сделки"
        $amo_deal_url = "https://{$amo_subdomain}.amocrm.ru/leads/detail/{$amo_lead_id}";
        $amo_deal_link = '<a href="' . htmlspecialchars($amo_deal_url, ENT_QUOTES, 'UTF-8') . '" target="_blank">' .
            htmlspecialchars($manager_name, ENT_QUOTES, 'UTF-8') . ': ' . $amo_lead_id . '</a>';

        // ID поля можно указать в .env (HOLLYHOP_AMO_DEALS_FIELD_ID), но не обязательно
        $hollyhop_amo_deals_field_id = getenv('HOLLYHOP_AMO_DEALS_FIELD_ID') ?: null;

        try {
            // Получаем текущие данные студента
            log_message("Получение текущих данных студента для обновления поля 'Сделки АМО'", [
                'client_id' => $client_id
            ], 'INFO');

            $get_student_params = ['clientId' => $client_id];
            $student_data = call_hollyhop_api('GetStudents', $get_student_params, $auth_key, $api_base_url);

            log_message("Данные студента получены", [
                'has_student_data' => !empty($student_data),
                'data_type' => gettype($student_data),
                'has_ExtraFields' => isset($student_data['ExtraFields']) || (is_array($student_data) && isset($student_data[0]['ExtraFields']))
            ], 'INFO');

            // Извлекаем студента из ответа
            $student = null;
            if (isset($student_data['ClientId']) || isset($student_data['clientId'])) {
                $student = $student_data;
            } elseif (isset($student_data['Students']) && is_array($student_data['Students'])) {
                foreach ($student_data['Students'] as $s) {
                    if (($s['ClientId'] ?? $s['clientId'] ?? null) == $client_id) {
                        $student = $s;
                        break;
                    }
                }
            } elseif (is_array($student_data) && isset($student_data[0])) {
                $student = $student_data[0];
            }

            if ($student) {
                // Получаем все текущие ExtraFields
                $all_extra_fields = [];
                $current_amo_deals = '';
                $amo_deals_field_found = false;

                log_message("Начало обработки ExtraFields для обновления поля 'Сделки АМО'", [
                    'clientId' => $client_id,
                    'has_ExtraFields' => isset($student['ExtraFields']),
                    'ExtraFields_count' => isset($student['ExtraFields']) && is_array($student['ExtraFields']) ? count($student['ExtraFields']) : 0
                ]);

                if (isset($student['ExtraFields']) && is_array($student['ExtraFields'])) {
                    foreach ($student['ExtraFields'] as $field) {
                        $field_name = $field['Name'] ?? $field['name'] ?? '';
                        $field_value = $field['Value'] ?? $field['value'] ?? '';

                        // Ищем поле "Сделки АМО" - проверяем точное совпадение или частичное
                        // Используем более надежную проверку с нормализацией пробелов
                        $field_name_normalized = trim($field_name);
                        $field_name_lower = mb_strtolower($field_name_normalized, 'UTF-8');

                        // Проверяем точное совпадение (с учетом возможных пробелов)
                        $is_amo_deals_field = (
                            $field_name_normalized === 'Сделки АМО' ||
                            $field_name_normalized === 'Ссылки АМО' ||
                            $field_name_normalized === 'Сделки АМО ' ||
                            $field_name_normalized === ' Сделки АМО' ||
                            // Частичное совпадение
                            (mb_stripos($field_name_lower, 'сделки', 0, 'UTF-8') !== false && mb_stripos($field_name_lower, 'амо', 0, 'UTF-8') !== false) ||
                            (mb_stripos($field_name_lower, 'ссылки', 0, 'UTF-8') !== false && mb_stripos($field_name_lower, 'амо', 0, 'UTF-8') !== false) ||
                            (mb_stripos($field_name_lower, 'сделки', 0, 'UTF-8') !== false && mb_stripos($field_name_lower, 'amo', 0, 'UTF-8') !== false) ||
                            (mb_stripos($field_name_lower, 'ссылки', 0, 'UTF-8') !== false && mb_stripos($field_name_lower, 'amo', 0, 'UTF-8') !== false)
                        );

                        // Логируем каждое проверяемое поле для отладки (только если поле похоже на "Сделки АМО")
                        if (
                            mb_stripos($field_name_lower, 'сделки', 0, 'UTF-8') !== false ||
                            mb_stripos($field_name_lower, 'ссылки', 0, 'UTF-8') !== false ||
                            mb_stripos($field_name_lower, 'амо', 0, 'UTF-8') !== false ||
                            mb_stripos($field_name_lower, 'amo', 0, 'UTF-8') !== false
                        ) {
                            log_message("Проверка поля ExtraField (похоже на 'Сделки АМО')", [
                                'field_name' => $field_name,
                                'field_name_normalized' => $field_name_normalized,
                                'field_name_lower' => $field_name_lower,
                                'is_amo_deals_field' => $is_amo_deals_field,
                                'exact_match_сделки_амо' => ($field_name_normalized === 'Сделки АМО'),
                                'exact_match_ссылки_амо' => ($field_name_normalized === 'Ссылки АМО'),
                                'contains_сделки' => (mb_stripos($field_name_lower, 'сделки', 0, 'UTF-8') !== false),
                                'contains_ссылки' => (mb_stripos($field_name_lower, 'ссылки', 0, 'UTF-8') !== false),
                                'contains_амо' => (mb_stripos($field_name_lower, 'амо', 0, 'UTF-8') !== false),
                                'contains_amo' => (mb_stripos($field_name_lower, 'amo', 0, 'UTF-8') !== false)
                            ], 'INFO');
                        }

                        if ($is_amo_deals_field) {
                            $current_amo_deals = $field_value;
                            $amo_deals_field_found = true;
                            log_message("Найдено поле 'Сделки АМО'", [
                                'field_name' => $field_name,
                                'field_name_normalized' => $field_name_normalized,
                                'field_name_lower' => $field_name_lower,
                                'current_value_raw' => $field_value,
                                'current_value_length' => strlen($field_value),
                                'is_exact_match' => ($field_name_normalized === 'Сделки АМО' || $field_name_normalized === 'Ссылки АМО')
                            ]);
                        } else {
                            if (in_array(normalize_extra_field_name($field_name), $student_extra_field_names, true)) {
                                continue;
                            }

                            // Сохраняем все остальные поля
                            $all_extra_fields[] = [
                                'name' => $field_name,
                                'value' => $field_value
                            ];
                        }
                    }
                }

                if (!$amo_deals_field_found) {
                    log_message("Поле 'Сделки АМО' не найдено в ExtraFields", [
                        'clientId' => $client_id,
                        'available_fields' => array_map(function ($f) {
                            return $f['name'] ?? 'unknown';
                        }, $all_extra_fields)
                    ], 'WARNING');
                }

                // Добавляем новую ссылку к существующим значениям
                // Сохраняем существующие HTML-ссылки как есть, не удаляя теги
                $current_amo_deals_clean = trim($current_amo_deals);

                // Извлекаем URL из новой ссылки для проверки дубликатов
                preg_match('/href=["\']([^"\']+)["\']/', $amo_deal_link, $new_link_matches);
                $new_link_url = $new_link_matches[1] ?? '';

                log_message("Обработка текущего значения поля 'Сделки АМО'", [
                    'original_value_preview' => substr($current_amo_deals, 0, 200),
                    'new_link_to_add' => $amo_deal_link,
                    'new_link_url' => $new_link_url
                ], 'INFO');

                // Разбиваем существующее значение на отдельные ссылки
                // Может быть несколько форматов: HTML-ссылки, обычный текст, смешанный
                $existing_links = [];
                if (!empty($current_amo_deals_clean)) {
                    // Разбиваем по <br> тегам, переносам строк или множественным пробелам
                    $parts = preg_split('/<br\s*\/?>|\s*\r?\n\s*|\s{2,}/i', $current_amo_deals_clean);
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if (!empty($part)) {
                            $existing_links[] = $part;
                        }
                    }
                }

                log_message("Разбор существующих ссылок", [
                    'existing_links_count' => count($existing_links),
                    'new_link_url' => $new_link_url
                ], 'INFO');

                // Проверяем, нет ли уже этой ссылки (по URL)
                $link_exists = false;
                foreach ($existing_links as $existing_link) {
                    // Извлекаем URL из существующей ссылки (может быть HTML или обычный текст)
                    $existing_url = '';
                    if (preg_match('/href=["\']([^"\']+)["\']/', $existing_link, $matches)) {
                        // Это HTML-ссылка, извлекаем URL
                        $existing_url = $matches[1];
                    } else {
                        // Это обычный текст, возможно это уже URL
                        $existing_url = $existing_link;
                    }

                    // Нормализуем URL для сравнения (убираем trailing slash, приводим к нижнему регистру)
                    $existing_url_normalized = rtrim(strtolower($existing_url), '/');
                    $new_link_url_normalized = rtrim(strtolower($new_link_url), '/');

                    if ($existing_url_normalized === $new_link_url_normalized) {
                        $link_exists = true;
                        log_message("Ссылка уже существует в поле", [
                            'existing_url' => $existing_url,
                            'new_url' => $new_link_url
                        ], 'INFO');
                        break;
                    }
                }

                // Формируем новое значение: все существующие ссылки + новая (если её еще нет)
                // Используем <br> для визуального переноса строки в HTML
                if ($link_exists) {
                    // Ссылка уже есть, оставляем как есть (сохраняем все существующие ссылки в HTML-формате)
                    $new_amo_deals_value = implode("<br>", $existing_links);
                    log_message("Ссылка уже существует в поле, оставляем без изменений", [
                        'final_value_preview' => substr($new_amo_deals_value, 0, 200)
                    ], 'INFO');
                } else {
                    // Добавляем новую ссылку
                    $existing_links[] = $amo_deal_link;
                    $new_amo_deals_value = implode("<br>", $existing_links);
                    log_message("Добавлена новая ссылка к существующим", [
                        'final_value_preview' => substr($new_amo_deals_value, 0, 200),
                        'total_links' => count($existing_links)
                    ], 'INFO');
                }

                // Добавляем обновленное поле "Сделки АМО" к остальным полям
                $all_extra_fields[] = [
                    'name' => 'Сделки АМО',
                    'value' => $new_amo_deals_value
                ];

                foreach ($student_extra_fields as $extra_field) {
                    $all_extra_fields[] = $extra_field;
                }

                // Обновляем все поля через EditUserExtraFields
                // ВАЖНО: Метод требует отправки ВСЕХ полей сразу, иначе они будут удалены
                $update_success = false;
                $last_error = null;

                log_message("Подготовка к обновлению через EditUserExtraFields", [
                    'clientId' => $client_id,
                    'total_fields_to_send' => count($all_extra_fields),
                    'amo_deals_field_value' => $new_amo_deals_value,
                    'amo_deals_field_value_length' => strlen($new_amo_deals_value),
                    'student_extra_fields' => $student_extra_fields
                ]);

                try {
                    $update_params = [
                        'studentClientId' => $client_id,
                        'fields' => $all_extra_fields
                    ];

                    log_message("Отправка запроса EditUserExtraFields", [
                        'studentClientId' => $client_id,
                        'fields_count' => count($all_extra_fields),
                        'amo_deals_in_fields' => in_array('Сделки АМО', array_map(function ($f) {
                            return $f['name'] ?? '';
                        }, $all_extra_fields)),
                        'all_fields_names' => array_map(function ($f) {
                            return $f['name'] ?? 'unknown';
                        }, $all_extra_fields)
                    ], 'INFO');

                    $update_result = call_hollyhop_api('EditUserExtraFields', $update_params, $auth_key, $api_base_url);
                    $update_success = true;

                    log_message("EditUserExtraFields выполнен успешно", [
                        'result_preview' => is_array($update_result) ? json_encode($update_result, JSON_UNESCAPED_UNICODE) : (string)$update_result
                    ], 'INFO');
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    log_message("Не удалось обновить через EditUserExtraFields", [
                        'error' => substr($last_error, 0, 200),
                        'fields_count' => count($all_extra_fields),
                        'full_error' => $e->getMessage()
                    ], 'WARNING');
                }

                if ($update_success) {
                    log_message("Поле 'Сделки АМО' обновлено в Hollyhop", [
                        'clientId' => $client_id,
                        'lead_id' => $amo_lead_id,
                        'amo_deal_link' => $amo_deal_link,
                        'method' => 'EditUserExtraFields',
                        'total_fields_updated' => count($all_extra_fields)
                    ]);
                } else {
                    log_message("Не удалось обновить поле 'Сделки АМО' через EditUserExtraFields", [
                        'clientId' => $client_id,
                        'lead_id' => $amo_lead_id,
                        'error' => substr($last_error ?? 'неизвестная ошибка', 0, 200),
                        'fields_count' => count($all_extra_fields)
                    ]);
                    // Не бросаем исключение, просто логируем - это не критичная ошибка
                }
            }
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            log_message("Ошибка при обновлении поля 'Сделки АМО' в Hollyhop", [
                'error' => $e->getMessage(),
                'clientId' => $client_id,
                'lead_id' => $amo_lead_id
            ]);
        }
    }

    // Возвращаем успешный ответ
    log_message("ШАГ 11: Формирование финального ответа", [
        'success' => true,
        'operation' => $operation,
        'clientId' => $client_id,
        'Id' => $profile_id
    ], 'INFO');

    $script_execution_time = round(microtime(true) - $script_start_time, 2);
    log_message("═══════════════════════════════════════════════════════════", [], 'INFO');
    log_message("УСПЕШНОЕ ЗАВЕРШЕНИЕ ОБРАБОТКИ ЗАПРОСА add_student.php", [
        'timestamp' => date('Y-m-d H:i:s'),
        'operation' => $operation,
        'clientId' => $client_id,
        'profile_id' => $profile_id,
        'execution_time_seconds' => $script_execution_time
    ], 'INFO');
    log_message("═══════════════════════════════════════════════════════════", [], 'INFO');

    http_response_code(200);
    echo json_encode($response_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);

    $error_message = $e->getMessage();
    log_message("═══════════════════════════════════════════════════════════", [], 'ERROR');
    log_message("КРИТИЧЕСКАЯ ОШИБКА В add_student.php", [
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $error_message,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'ERROR');
    log_message("═══════════════════════════════════════════════════════════", [], 'ERROR');

    echo json_encode([
        'success' => false,
        'error' => $error_message
    ]);
}
