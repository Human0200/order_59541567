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
function get_post_data() {
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
function validate_student_data($data) {
    $errors = [];
    
    // Имя и фамилия теперь не обязательны - если не указаны, будут заменены на прочерки
    
    return $errors;
}

/**
 * Отправка запроса к Hollyhop API
 */
function call_hollyhop_api($function_name, $params, $auth_key, $api_base_url) {
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
        CURLOPT_TIMEOUT => 30,
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

/**
 * Основная логика скрипта
 */
try {
    // Получаем данные из POST запроса
    $post_data = get_post_data();
    
    log_message("Новый запрос на добавление студента", $post_data);
    
    // Валидируем данные
    $validation_errors = validate_student_data($post_data);
    
    if (!empty($validation_errors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'errors' => $validation_errors
        ]);
        log_message("Ошибка валидации", $validation_errors);
        exit;
    }
    
    // Маппинг gender: Ж/ж/женский/female/F → false (женский), М/м/мужской/male/M → true (мужской)
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
    
    // Подготавливаем параметры для API
    // Если имя или фамилия не указаны, используем прочерки
    $firstName = !empty($post_data['firstName']) ? trim($post_data['firstName']) : '-';
    $lastName = !empty($post_data['lastName']) ? trim($post_data['lastName']) : '-';
    
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
        'middleName' => 'patronymic',
        'birthDate' => 'birthDate',
        'phone' => 'phone',
        'email' => 'email',
        'locationId' => 'locationId'
    ];
    
    foreach ($optional_fields as $post_field => $api_field) {
        if (!empty($post_data[$post_field])) {
            $student_params[$api_field] = $post_data[$post_field];
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
            'Мини группа' => 'Мини-группа',
            'Стандарт' => 'Стандарт',
            'Индивидуальный' => 'Индивидуальный',
            'Индивидуальные' => 'Индивидуальный',
            'Группа' => 'Группа',
            'Полная группа' => 'Полная группа',
            'Общий' => 'Общий',
            'Интенсивный' => 'Интенсивный'
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
    if (!empty($post_data['officeOrCompanyId'])) {
        // Маппинг названия офиса в ID
        $office_mapping = [
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
        
        $office_value = (string)$post_data['officeOrCompanyId'];
        
        // Если это число, используем как ID
        if (is_numeric($office_value)) {
            $student_params['officeOrCompanyId'] = (int)$office_value;
            log_message("officeOrCompanyId: используем числовой ID", ['value' => (int)$office_value]);
        }
        // Если строка, ищем в маппинге
        elseif (isset($office_mapping[$office_value])) {
            $student_params['officeOrCompanyId'] = $office_mapping[$office_value];
            log_message("officeOrCompanyId: найден в маппинге", ['название' => $office_value, 'ID' => $office_mapping[$office_value]]);
        }
        // Если не найдено в маппинге, пробуем передать как есть
        else {
            $student_params['officeOrCompanyId'] = $office_value;
            log_message("officeOrCompanyId: ⚠️ не найдено в маппинге, передаём как есть", ['value' => $office_value]);
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
        $student_params['Status'] = $post_data['Status'];
    }
    
    // Логируем все параметры для отладки
    log_message("Параметры для AddStudent API", $student_params);
    
    // Проверяем, существует ли уже студент с таким же именем и телефоном
    $existing_student = null;
    $is_update = false;
    
    if (!empty($post_data['phone'])) {
        try {
            $search_phone = trim($post_data['phone']);
            
            log_message("Поиск существующего студента по телефону", [
                'phone' => $search_phone
            ]);
            
            // Нормализуем телефон для сравнения (как в search_student_by_phone.php)
            $normalize_phone = function($phone) {
                if (empty($phone)) return '';
                // Убираем все кроме цифр
                $normalized = preg_replace('/\D/', '', $phone);
                // Если телефон начинается с 8, заменяем на 7
                if (strlen($normalized) === 11 && substr($normalized, 0, 1) === '8') {
                    $normalized = '7' . substr($normalized, 1);
                }
                return $normalized;
            };
            
            // Получение телефона из объекта студента (как в search_student_by_phone.php)
            $get_student_phone = function($student) {
                if (!is_array($student)) {
                    return '';
                }
                
                // Пробуем разные поля
                $phone_fields = ['Phone', 'phone', 'Mobile', 'mobile', 'Telephone', 'telephone'];
                
                foreach ($phone_fields as $field) {
                    if (isset($student[$field]) && !empty($student[$field])) {
                        return trim($student[$field]);
                    }
                }
                
                // Пробуем получить из Agents
                if (isset($student['Agents']) && is_array($student['Agents']) && !empty($student['Agents'])) {
                    foreach ($student['Agents'] as $agent) {
                        if (isset($agent['Mobile']) && !empty($agent['Mobile'])) {
                            return trim($agent['Mobile']);
                        } elseif (isset($agent['mobile']) && !empty($agent['mobile'])) {
                            return trim($agent['mobile']);
                        } elseif (isset($agent['Phone']) && !empty($agent['Phone'])) {
                            return trim($agent['Phone']);
                        } elseif (isset($agent['phone']) && !empty($agent['phone'])) {
                            return trim($agent['phone']);
                        }
                    }
                }
                
                return '';
            };
            
            $normalized_search_phone = $normalize_phone($search_phone);
            log_message("Нормализованный номер для поиска", [
                'original' => $search_phone,
                'normalized' => $normalized_search_phone
            ]);
            
            // Пробуем разные варианты параметров поиска (как в search_student_by_phone.php)
            $search_attempts = [
                ['phone' => $search_phone],              // Попытка 1: phone
                ['term' => $search_phone],               // Попытка 2: term
                ['search' => $search_phone],             // Попытка 3: search
                ['q' => $search_phone],                  // Попытка 4: q
            ];
            
            $all_candidates = [];
            $search_param_used = null;
            
            // Собираем ВСЕ кандидаты из всех попыток (как в search_student_by_phone.php)
            foreach ($search_attempts as $attempt) {
                try {
                    $param_name = array_key_first($attempt);
                    log_message("Попытка поиска с параметром: {$param_name}", $attempt);
                    
                    $search_response = call_hollyhop_api('GetStudents', $attempt, $auth_key, $api_base_url);
                    $search_param_used = $param_name;
                    
                    log_message("Ответ GetStudents получен для параметра {$param_name}", [
                        'type' => gettype($search_response),
                        'is_empty' => empty($search_response),
                        'is_array' => is_array($search_response)
                    ]);
                    
                    // Извлекаем студентов из ответа
                    $candidates = [];
                    
                    if (is_array($search_response)) {
                        // Если это массив студентов
                        if (isset($search_response[0]) && is_array($search_response[0])) {
                            $candidates = $search_response;
                        }
                        // Если это объект студента (один результат)
                        elseif (isset($search_response['Id']) || isset($search_response['id']) || 
                                isset($search_response['ClientId']) || isset($search_response['clientId'])) {
                            $candidates = [$search_response];
                        }
                        // Если это объект с полем Students (множество результатов)
                        elseif (isset($search_response['Students']) && is_array($search_response['Students'])) {
                            $candidates = $search_response['Students'];
                        }
                    }
                    
                    // Добавляем кандидатов в общий список (избегаем дубликатов)
                    foreach ($candidates as $candidate) {
                        if (!is_array($candidate)) {
                            continue;
                        }
                        
                        $candidate_id = $candidate['Id'] ?? $candidate['id'] ?? 
                                       $candidate['ClientId'] ?? $candidate['clientId'] ?? null;
                        
                        // Проверяем, нет ли уже такого студента
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
                    
                    // Продолжаем пробовать другие параметры для полноты
                } catch (Exception $attempt_e) {
                    log_message("Попытка поиска с {$param_name} не сработала: " . $attempt_e->getMessage());
                    continue;
                }
            }
            
            // Фильтруем результаты по точному совпадению телефона (как в search_student_by_phone.php)
            $matched_students = [];
            
            foreach ($all_candidates as $candidate) {
                $candidate_phone = $get_student_phone($candidate);
                
                if (!empty($candidate_phone)) {
                    $normalized_candidate_phone = $normalize_phone($candidate_phone);
                    $phone_match = ($normalized_candidate_phone === $normalized_search_phone);
                    
                    if ($phone_match) {
                        $matched_students[] = $candidate;
                    }
                }
            }
            
            log_message("Результаты поиска", [
                'total_candidates' => count($all_candidates),
                'matched_students' => count($matched_students),
                'search_param_used' => $search_param_used
            ]);
            
            // Берем первого найденного студента с точным совпадением
            if (!empty($matched_students)) {
                $existing_student = $matched_students[0];
                $is_update = true;
                log_message("✓✓✓ НАЙДЕН студент с ТОЧНЫМ совпадением телефона", [
                    'search_param' => $search_param_used,
                    'Id' => $existing_student['Id'] ?? $existing_student['id'] ?? 'не найден',
                    'ClientId' => $existing_student['ClientId'] ?? $existing_student['clientId'] ?? 'не найден',
                    'total_matched' => count($matched_students)
                ]);
            } else {
                log_message("⚠️ Студент с телефоном '{$search_phone}' (нормализованный: '{$normalized_search_phone}') не найден, будет создан новый", [
                    'total_candidates' => count($all_candidates)
                ]);
            }
            
        } catch (Exception $e) {
            log_message("Ошибка при поиске студента по телефону: " . $e->getMessage() . ". Создаём нового.");
        }
    } else {
        log_message("Телефон не указан, пропускаем проверку существующего студента");
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
    if (!$is_update) {
        // Убираем Id и ClientId из параметров, если они были добавлены
        unset($student_params['Id']);
        unset($student_params['ClientId']);
        
        // Отправляем запрос к API для создания студента
        $result = call_hollyhop_api('AddStudent', $student_params, $auth_key, $api_base_url);
        
        // Проверяем, что результат получен
        if (!isset($result)) {
            throw new Exception("Не удалось получить результат от API (AddStudent)");
        }
        
        // Логируем полный ответ для отладки
        log_message("Ответ от AddStudent API", $result);
    }
    
    // Инициализируем переменные для ID студента
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
    
    // Обновляем контактные данные через EditContacts (только для новых студентов)
    if (!$is_update && $client_id && (!empty($post_data['phone']) || !empty($post_data['email']))) {
        try {
            $edit_contacts_params = [
                'StudentClientId' => $client_id,
                'useMobileBySystem' => false, // По умолчанию false
                'useEMailBySystem' => false   // Обязательное поле, даже если email не указан
            ];
            
            // Добавляем мобильный телефон, если указан
            if (!empty($post_data['phone'])) {
                $edit_contacts_params['mobile'] = trim($post_data['phone']);
                $edit_contacts_params['useMobileBySystem'] = true; // Разрешаем использование системой
                log_message("Подготовка обновления контактов: телефон указан", ['phone' => $edit_contacts_params['mobile']]);
            }
            
            // Добавляем email, если указан
            if (!empty($post_data['email'])) {
                $edit_contacts_params['eMail'] = trim($post_data['email']);
                $edit_contacts_params['useEMailBySystem'] = true; // Разрешаем использование системой
                log_message("Подготовка обновления контактов: email указан", ['email' => $edit_contacts_params['eMail']]);
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
    $student_id_from_api = null;
    $student_info = null;
    try {
        log_message("Запрос GetStudents с clientId: {$client_id}");
        
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
    
    // Формируем ответ в новом формате
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
    
    // Обновляем поле "Сделки АМО" в Hollyhop, если передан lead_id из AmoCRM
    if (isset($post_data['amo_lead_id']) && !empty($post_data['amo_lead_id']) && $client_id) {
        $amo_lead_id = (int)$post_data['amo_lead_id'];
        $amo_subdomain = $post_data['amo_subdomain'] ?? 'directorchinatutorru';
        
        // Получаем имя ответственного менеджера из AmoCRM
        $manager_name = 'Неизвестно';
        if (file_exists(__DIR__ . '/amo_func.php')) {
            require_once __DIR__ . '/amo_func.php';
            try {
                // Получаем данные сделки
                $api_url = '/api/v4/leads/' . $amo_lead_id;
                $LEAD = get($amo_subdomain, $api_url, $data);
                
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
            $get_student_params = ['clientId' => $client_id];
            $student_data = call_hollyhop_api('GetStudents', $get_student_params, $auth_key, $api_base_url);
            
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
                        if (mb_stripos($field_name_lower, 'сделки', 0, 'UTF-8') !== false || 
                            mb_stripos($field_name_lower, 'ссылки', 0, 'UTF-8') !== false ||
                            mb_stripos($field_name_lower, 'амо', 0, 'UTF-8') !== false ||
                            mb_stripos($field_name_lower, 'amo', 0, 'UTF-8') !== false) {
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
                        'available_fields' => array_map(function($f) {
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
                
                // Обновляем все поля через EditUserExtraFields
                // ВАЖНО: Метод требует отправки ВСЕХ полей сразу, иначе они будут удалены
                $update_success = false;
                $last_error = null;
                
                log_message("Подготовка к обновлению через EditUserExtraFields", [
                    'clientId' => $client_id,
                    'total_fields_to_send' => count($all_extra_fields),
                    'amo_deals_field_value' => $new_amo_deals_value,
                    'amo_deals_field_value_length' => strlen($new_amo_deals_value)
                ]);
                
                try {
                    $update_params = [
                        'studentClientId' => $client_id,
                        'fields' => $all_extra_fields
                    ];
                    
                    log_message("Отправка запроса EditUserExtraFields", [
                        'studentClientId' => $client_id,
                        'fields_count' => count($all_extra_fields),
                        'amo_deals_in_fields' => in_array('Сделки АМО', array_map(function($f) { return $f['name'] ?? ''; }, $all_extra_fields)),
                        'all_fields_names' => array_map(function($f) { return $f['name'] ?? 'unknown'; }, $all_extra_fields)
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
    http_response_code(200);
    echo json_encode($response_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    
    $error_message = $e->getMessage();
    log_message("Критическая ошибка: {$error_message}");
    
    echo json_encode([
        'success' => false,
        'error' => $error_message
    ]);
}
?>

