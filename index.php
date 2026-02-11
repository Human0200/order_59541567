<?php

// Подключаем единую систему логирования
require_once __DIR__ . '/logger.php';
// Подключаем конфигурацию для работы с Hollyhop API
require_once __DIR__ . '/config.php';
// 1. ПРИЕМ ДАННЫХ ОТ ОКИДОКИ (КОГДА ДОГОВОР ПОДПИСАН)
$input = file_get_contents('php://input');
$oki_data = json_decode($input, true);

if (isset($oki_data['status']) && $oki_data['status'] === 'signed') {
    $lead_id = $oki_data['lead_id'] ?? null;

    // ВАЖНО: Названия ключей должны точно совпадать с теми, что в $extra_fields
    $fio = $oki_data['extra_fields']['ФИО клиента'] ?? '';
    $email = $oki_data['extra_fields']['E-Mail клиента'] ?? '';

    if ($lead_id) {
        // Логируем для проверки, что данные пришли
        log_info("Договор подписан! ФИО: $fio, Email: $email", $oki_data, 'index.php');

        // Получаем данные сделки, чтобы найти ID контакта
        $lead_info = get($subdomain, "/api/v4/leads/$lead_id?with=contacts", $data);
        $contact_id = $lead_info['_embedded']['contacts'][0]['id'] ?? null;

        if ($contact_id) {
            $contact_update = [
                'name' => $fio,
                'custom_fields_values' => [
                    [
                        'field_code' => 'EMAIL',
                        'values' => [['value' => $email, 'enum_code' => 'WORK']]
                    ]
                ]
            ];
            // Обновляем контакт
            post_or_patch($subdomain, $contact_update, "/api/v4/contacts/$contact_id", $data, 'PATCH');
        }
    }
    exit('OK');
}
// --- КОНЕЦ БЛОКА ---

// Логируем входящий вебхук от AmoCRM
log_info("Вебхук от AmoCRM получен", $_POST, 'index.php');

//$_POST["leads"]["status"][0]["id"] = 28696719;
$lead_id = null;
if (isset($_POST["leads"]["add"][0]["id"])) {
    $lead_id = (int) $_POST["leads"]["add"][0]["id"];
    log_info("Обработка события: создание новой сделки", ['lead_id' => $lead_id], 'index.php');
} elseif (isset($_POST["leads"]["status"][0]["id"])) {
    $lead_id = (int) $_POST["leads"]["status"][0]["id"];
    log_info("Обработка события: изменение статуса сделки", ['lead_id' => $lead_id], 'index.php');
} else {
    log_warning("Вебхук получен, но не удалось определить lead_id", $_POST, 'index.php');
    exit;
}

require_once __DIR__ . '/amo_func.php';

$api_url = '/api/v4/leads/' . $lead_id . '?with=contacts';
try {
    $LEAD = get($subdomain, $api_url, $data);
    log_info("Данные сделки получены из AmoCRM", ['lead_id' => $lead_id], 'index.php');
} catch (Exception $e) {
    log_error("Ошибка при получении данных сделки из AmoCRM", ['lead_id' => $lead_id, 'error' => $e->getMessage()], 'index.php');
    die("Ошибка: " . $e->getMessage());
}

// Проверяем наличие кастомных полей (может быть null или отсутствовать)
$custom_fields_values = $LEAD["custom_fields_values"] ?? [];

if (empty($custom_fields_values) || !is_array($custom_fields_values)) {
    log_warning("Сделка не содержит кастомных полей или они пусты", [
        'lead_id' => $lead_id,
        'has_custom_fields' => isset($LEAD["custom_fields_values"]),
        'custom_fields_is_null' => ($LEAD["custom_fields_values"] ?? null) === null,
        'custom_fields_type' => gettype($LEAD["custom_fields_values"] ?? null)
    ], 'index.php');
    // Устанавливаем пустой массив, чтобы foreach не вызывал ошибок
    $custom_fields_values = [];
}

$json = [
    'Status' => 'В наборе',
    'link' => "https://{$subdomain}.amocrm.ru/leads/detail/{$lead_id}",
    'gender' => 'F',
    'amo_lead_id' => $lead_id, // ID сделки AmoCRM для обновления поля "Сделки АМО"
    'amo_subdomain' => $subdomain // Поддомен AmoCRM для формирования ссылки
];

// Обрабатываем кастомные поля только если они есть
if (!empty($custom_fields_values) && is_array($custom_fields_values)) {
    foreach ($custom_fields_values as $field) {
        if ($field["field_id"] == 1575217) {
            $json["discipline"] = $field["values"][0]["value"];
        }
        if ($field["field_id"] == 1576357) {
            $json["level"] = $field["values"][0]["value"];
        }
        if ($field["field_id"] == 1575221) {
            $json["learningType"] = $field["values"][0]["value"];
        }
        if ($field["field_id"] == 1575213) {
            $json["maturity"] = $field["values"][0]["value"];
        }
        if ($field["field_id"] == 1596219) {
            $json["officeOrCompanyId"] = $field["values"][0]["value"];
        }
        if ($field["field_id"] == 1590693) {
            $json["responsible_user"] = $field["values"][0]["value"];
        }

        $extra_fields = [
            'Язык' => $lead_custom_fields[1575217] ?? '',
            'Вид уроков' => $lead_custom_fields[1575317] ?? '',
            'Отделение' => $lead_custom_fields[1596219] ?? '',
            'Преподаватель ИЗ' => $lead_custom_fields[1631479] ?? '',
            'Длительность занятия' => $lead_custom_fields[1631459] ?? '',
            'Кол-во уроков (курс)' => $lead_custom_fields[1631353] ?? '',
            'Кол-во часов/курс (мат к)' => $lead_custom_fields[1632335] ?? '',
            'Дата начала курса (мат к)' => $lead_custom_fields[1632337] ?? '',
            'Дата окончания курса (мат к)' => $lead_custom_fields[1632339] ?? '',
            'Срок оплаты до (мат к)' => $lead_custom_fields[1632341] ?? '',
            'Бюджет' => $lead_info['price'],
            'ФИО клиента' => '{{ФИО клиента}}',
            'E-mail клиента' => '{{E-mail клиента}}'
        ];
        // --- КОНЕЦ БЛОКА ---
    }
    if (isset($LEAD["_embedded"]["contacts"][0]["id"])) {
        $contact_id = (int) $LEAD["_embedded"]["contacts"][0]["id"];
        $api_url = '/api/v4/contacts/' . $contact_id;
        $CONTACT = get($subdomain, $api_url, $data);
        $name = explode(" ", $CONTACT["name"]);
        if (isset($name[0])) {
            $json["firstName"] = $name[0];
        }
        if (isset($name[1])) {
            $json["lastName"] = $name[1];
        }
        // echo print_r($CONTACT);
        // Обрабатываем кастомные поля контакта только если они есть
        if (isset($CONTACT["custom_fields_values"]) && is_array($CONTACT["custom_fields_values"])) {
            foreach ($CONTACT["custom_fields_values"] as $field) {
                if ($field["field_id"] == 1138327) {
                    $json["phone"] = $field["values"][0]["value"];
                }
                if ($field["field_id"] == 1138329) {
                    $json["email"] = $field["values"][0]["value"];
                }
            }
        }
    }
    // echo print_r($json);

    // Данные для отправки
    // $json = [
    //     'firstName' => 'Тест2',
    //     'lastName' => 'Иванович',
    //     'birthDate' => '2010-05-15',
    //     'gender' => 'M',
    //     'phone' => '+7(999)123-45-67',
    //     'email' => 'ivan@example.com',
    //     'discipline' => 'Китайский',
    //     'level' => 'A1',
    //     'learningType' => 'Стандарт',
    //     'officeOrCompanyId' => 'Выезд',
    //     'maturity' => 'Студенты',
    //     'responsible_user' => 'Юлия',
    //     'Status' => 'В наборе'
    // ];
    if (isset($json["firstName"])) {
        // Кодируем данные в JSON
        $jsonData = json_encode($json, JSON_UNESCAPED_UNICODE);
        log_info("Данные подготовлены для отправки в Hollyhop", $json, 'index.php');
        echo $jsonData . "<br><br>";
        // URL целевого сервера
        $url = 'https://srm.chinatutor.ru/add_student.php';

        // Инициализация cURL
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Увеличен таймаут до 120 секунд (add_student.php делает много операций)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // Таймаут на установку соединения
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Рекомендуется в продакшене

        // Выполняем запрос с замером времени
        $start_time = microtime(true);
        log_info("Начало вызова add_student.php", ['url' => $url, 'timeout' => 120], 'index.php');
        $response = curl_exec($ch);
        $execution_time = round(microtime(true) - $start_time, 2);
        log_info("Завершение вызова add_student.php", ['execution_time_seconds' => $execution_time], 'index.php');
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Проверяем ошибки
        if (curl_errno($ch)) {
            $curl_error = curl_error($ch);
            log_error("Ошибка cURL при отправке данных в Hollyhop", ['error' => $curl_error, 'url' => $url], 'index.php');
            echo 'Ошибка cURL: ' . $curl_error . "\n";
        } else {
            log_info("Ответ от Hollyhop API получен", ['http_code' => $httpCode, 'response' => $response], 'index.php');
            echo "HTTP-код: $httpCode\n";
            echo "Ответ сервера: " . ($response ?: 'пустой ответ') . "\n";
        }

        // Закрываем cURL
        curl_close($ch);

        $res = json_decode(trim($response), true); // trim убирает переносы строк в начале/конце
        if (isset($res["link"])) {
            $profile_link = $res["link"]; // Сохраняем ссылку в отдельную переменную
            log_info("Ссылка на профиль студента получена", ['link' => $profile_link, 'lead_id' => $lead_id], 'index.php');
            $leads_data = array(
                'id' => $lead_id,
                'custom_fields_values' => array(
                    array(
                        'field_id' => 1630807,
                        'values' => array(
                            array(
                                'value' => $profile_link
                            )
                        )
                    )
                )
            );

            try {
                $amo_res = post_or_patch(
                    $subdomain,
                    $leads_data,
                    '/api/v4/leads/' . $lead_id,
                    $data,
                    'PATCH'
                );
                log_info("Сделка в AmoCRM обновлена ссылкой на профиль", ['lead_id' => $lead_id, 'link' => $profile_link], 'index.php');
                echo print_r($amo_res);

                // Обновляем поле "Сделки АМО" в Hollyhop
                // Поле хранится в ExtraFields по имени "Сделки АМО"
                // ID поля можно указать в .env (HOLLYHOP_AMO_DEALS_FIELD_ID), но не обязательно
                // Если ID не указан, будет использоваться обновление по имени через ExtraFields
                $hollyhop_amo_deals_field_id = getenv('HOLLYHOP_AMO_DEALS_FIELD_ID') ?: null;

                // Обновляем поле, если есть clientId
                if (isset($res["clientId"])) {
                    $client_id = $res["clientId"];

                    // Получаем имя ответственного менеджера из AmoCRM
                    $manager_name = 'Неизвестно';
                    if (isset($LEAD["responsible_user_id"])) {
                        try {
                            $responsible_user_id = (int)$LEAD["responsible_user_id"];
                            $api_url = '/api/v4/users/' . $responsible_user_id;
                            $USER = get($subdomain, $api_url, $data);

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

                            log_info("Имя менеджера получено из AmoCRM", [
                                'responsible_user_id' => $responsible_user_id,
                                'manager_name' => $manager_name,
                                'user_response_structure' => is_array($USER) ? 'array' : 'object'
                            ], 'index.php');
                        } catch (Exception $e) {
                            log_warning("Не удалось получить имя менеджера из AmoCRM", [
                                'responsible_user_id' => $LEAD["responsible_user_id"] ?? null,
                                'error' => $e->getMessage()
                            ], 'index.php');
                        }
                    } else {
                        log_warning("У сделки не указан ответственный менеджер", [
                            'lead_id' => $lead_id
                        ], 'index.php');
                    }

                    // Формируем HTML ссылку в формате "Менеджер: ID сделки"
                    $amo_deal_url = "https://{$subdomain}.amocrm.ru/leads/detail/{$lead_id}";
                    $amo_deal_link = '<a href="' . htmlspecialchars($amo_deal_url, ENT_QUOTES, 'UTF-8') . '" target="_blank">' .
                        htmlspecialchars($manager_name, ENT_QUOTES, 'UTF-8') . ': ' . $lead_id . '</a>';

                    // Функция для вызова Hollyhop API
                    if (!function_exists('call_hollyhop_api')) {
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
                                CURLOPT_TIMEOUT => 60, // Увеличен таймаут для надежной работы API
                                CURLOPT_CONNECTTIMEOUT => 15,
                                CURLOPT_SSL_VERIFYPEER => true,
                            ]);

                            $response = curl_exec($ch);
                            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $curl_error = curl_error($ch);
                            curl_close($ch);

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

                            return $result;
                        }
                    }

                    try {
                        $api_config = get_config('api');
                        $auth_key = $api_config['auth_key'];
                        $api_base_url = $api_config['base_url'];

                        // Получаем текущие данные студента для получения существующего значения поля
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
                            // ВАЖНО: Метод EditUserExtraFields требует отправки ВСЕХ полей сразу, иначе они будут удалены
                            $all_extra_fields = [];
                            $current_amo_deals = '';
                            $amo_deals_field_found = false;

                            log_info("Начало обработки ExtraFields для обновления поля 'Сделки АМО'", [
                                'clientId' => $client_id,
                                'has_ExtraFields' => isset($student['ExtraFields']),
                                'ExtraFields_count' => isset($student['ExtraFields']) && is_array($student['ExtraFields']) ? count($student['ExtraFields']) : 0
                            ], 'index.php');

                            log_info("ALL ExtraFields RAW", $student['ExtraFields'], 'index.php');


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
                                        log_info("Проверка поля ExtraField (похоже на 'Сделки АМО')", [
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
                                        ], 'index.php');
                                    }

                                    if ($is_amo_deals_field) {
                                        $current_amo_deals = $field_value;
                                        $amo_deals_field_found = true;
                                        log_info("Найдено поле 'Сделки АМО'", [
                                            'field_name' => $field_name,
                                            'field_name_lower' => $field_name_lower,
                                            'current_value_raw' => $field_value,
                                            'current_value_length' => strlen($field_value)
                                        ], 'index.php');
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
                                log_warning("Поле 'Сделки АМО' не найдено в ExtraFields", [
                                    'clientId' => $client_id,
                                    'available_fields' => array_map(function ($f) {
                                        return $f['name'] ?? 'unknown';
                                    }, $all_extra_fields)
                                ], 'index.php');
                            }

                            // Добавляем новую ссылку к существующим значениям
                            // Сохраняем существующие HTML-ссылки как есть, не удаляя теги
                            $current_amo_deals_clean = trim($current_amo_deals);

                            // Извлекаем URL из новой ссылки для проверки дубликатов
                            preg_match('/href=["\']([^"\']+)["\']/', $amo_deal_link, $new_link_matches);
                            $new_link_url = $new_link_matches[1] ?? '';

                            log_info("Обработка текущего значения поля 'Сделки АМО'", [
                                'original_value_preview' => substr($current_amo_deals, 0, 200),
                                'new_link_to_add' => $amo_deal_link,
                                'new_link_url' => $new_link_url
                            ], 'index.php');

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

                            log_info("Разбор существующих ссылок", [
                                'existing_links_count' => count($existing_links),
                                'new_link_url' => $new_link_url
                            ], 'index.php');

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
                                    log_info("Ссылка уже существует в поле", [
                                        'existing_url' => $existing_url,
                                        'new_url' => $new_link_url
                                    ], 'index.php');
                                    break;
                                }
                            }

                            // Формируем новое значение: все существующие ссылки + новая (если её еще нет)
                            // Используем <br> для визуального переноса строки в HTML
                            if ($link_exists) {
                                // Ссылка уже есть, оставляем как есть
                                $new_amo_deals_value = implode("<br>", $existing_links);
                                log_info("Ссылка уже существует в поле, оставляем без изменений", [
                                    'final_value_preview' => substr($new_amo_deals_value, 0, 200)
                                ], 'index.php');
                            } else {
                                // Добавляем новую ссылку
                                $existing_links[] = $amo_deal_link;
                                $new_amo_deals_value = implode("<br>", $existing_links);
                                log_info("Добавлена новая ссылка к существующим", [
                                    'final_value_preview' => substr($new_amo_deals_value, 0, 200),
                                    'total_links' => count($existing_links)
                                ], 'index.php');
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

                            log_info("Подготовка к обновлению через EditUserExtraFields", [
                                'clientId' => $client_id,
                                'total_fields_to_send' => count($all_extra_fields),
                                'amo_deals_field_value' => $new_amo_deals_value,
                                'amo_deals_field_value_length' => strlen($new_amo_deals_value),
                                'all_fields_names' => array_map(function ($f) {
                                    return $f['name'] ?? 'unknown';
                                }, $all_extra_fields)
                            ], 'index.php');

                            try {
                                $update_params = [
                                    'studentClientId' => $client_id,
                                    'fields' => $all_extra_fields
                                ];

                                log_info("Отправка запроса EditUserExtraFields", [
                                    'studentClientId' => $client_id,
                                    'fields_count' => count($all_extra_fields),
                                    'amo_deals_in_fields' => in_array('Сделки АМО', array_map(function ($f) {
                                        return $f['name'] ?? '';
                                    }, $all_extra_fields))
                                ], 'index.php');

                                $update_result = call_hollyhop_api('EditUserExtraFields', $update_params, $auth_key, $api_base_url);
                                $update_success = true;

                                log_info("EditUserExtraFields выполнен успешно", [
                                    'result_preview' => is_array($update_result) ? json_encode($update_result, JSON_UNESCAPED_UNICODE) : (string)$update_result
                                ], 'index.php');
                            } catch (Exception $e) {
                                $last_error = $e->getMessage();
                                log_warning("Не удалось обновить через EditUserExtraFields", [
                                    'error' => substr($last_error, 0, 200),
                                    'fields_count' => count($all_extra_fields),
                                    'full_error' => $e->getMessage()
                                ], 'index.php');
                            }

                            if (!$update_success) {
                                log_warning("Не удалось обновить поле 'Сделки АМО' через EditUserExtraFields", [
                                    'error' => substr($last_error ?? 'неизвестная ошибка', 0, 200),
                                    'clientId' => $client_id,
                                    'fields_count' => count($all_extra_fields)
                                ], 'index.php');
                                // Не бросаем исключение, просто логируем - это не критичная ошибка
                            } else {
                                log_info("Поле 'Сделки АМО' обновлено в Hollyhop", [
                                    'clientId' => $client_id,
                                    'lead_id' => $lead_id,
                                    'amo_deal_link' => $amo_deal_link,
                                    'method' => 'EditUserExtraFields',
                                    'total_fields_updated' => count($all_extra_fields)
                                ], 'index.php');
                            }
                        } else {
                            log_warning("Студент не найден в Hollyhop для обновления поля 'Сделки АМО'", [
                                'clientId' => $client_id
                            ], 'index.php');
                        }
                    } catch (Exception $e) {
                        // Логируем ошибку, но не прерываем выполнение
                        log_error("Ошибка при обновлении поля 'Сделки АМО' в Hollyhop", [
                            'error' => $e->getMessage(),
                            'clientId' => $client_id ?? null,
                            'lead_id' => $lead_id
                        ], 'index.php');
                    }
                } elseif (!isset($res["clientId"])) {
                    log_warning("clientId не найден в ответе от add_student.php", ['response' => $res], 'index.php');
                }
            } catch (Exception $e) {
                log_error("Ошибка при обновлении сделки в AmoCRM", ['lead_id' => $lead_id, 'error' => $e->getMessage()], 'index.php');
                echo "Ошибка обновления: " . $e->getMessage();
            }
        } else {
            log_warning("Ссылка на профиль не получена в ответе", ['response' => $res, 'lead_id' => $lead_id], 'index.php');
        }
    } else {
        log_warning("Имя студента не указано, пропуск отправки", ['json' => $json, 'lead_id' => $lead_id], 'index.php');
    }
}
