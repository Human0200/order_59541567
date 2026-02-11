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
register_shutdown_function(function() use ($log_dir) {
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

/**
 * Функции логирования для add_payment.php (запись в отдельный файл pay.log)
 */
function log_payment_message($message, $data = null, $level = 'INFO') {
    $log_dir = __DIR__ . '/logs';
    $log_file = $log_dir . '/pay.log';
    
    // Создаём директорию для логов, если её нет
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    // Форматируем сообщение
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}";
    
    // Добавляем дополнительные данные
    if ($data !== null) {
        if (is_string($data)) {
            $log_entry .= "\n" . $data;
        } else {
            $log_entry .= "\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    
    $log_entry .= "\n" . str_repeat('-', 80) . "\n";
    
    // Записываем в файл
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function log_payment_info($message, $data = null) {
    log_payment_message($message, $data, 'INFO');
}

function log_payment_warning($message, $data = null) {
    log_payment_message($message, $data, 'WARNING');
}

function log_payment_error($message, $data = null) {
    log_payment_message($message, $data, 'ERROR');
}

function log_payment_debug($message, $data = null) {
    log_payment_message($message, $data, 'DEBUG');
}

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
    
    // Логирование запроса (только при ошибках)
    
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
    
    // Успешный ответ (логируем только при ошибках)
    
    return $result;
}

/**
 * Основная логика скрипта
 */
try {
    // Извлекаем ID транзакции, сделки или счета из вебхука
    $transaction_id = null;
    $lead_id_from_webhook = null;
    $catalog_element_id = null;
    $catalog_id = null;
    $event_type = null;
    $payment_link = null; // Ссылка на оплату (INVOICE_HASH_LINK) - для определения способа оплаты
    
    // Сначала проверяем, есть ли транзакция в вебхуке
    if (isset($_POST["transactions"]["add"][0]["id"])) {
        $transaction_id = (int) $_POST["transactions"]["add"][0]["id"];
        $event_type = 'transaction_add';
        log_payment_info("Вебхук: транзакция создана", ['transaction_id' => $transaction_id]);
    } elseif (isset($_POST["transactions"]["status"][0]["id"])) {
        $transaction_id = (int) $_POST["transactions"]["status"][0]["id"];
        $event_type = 'transaction_status';
        log_payment_info("Вебхук: статус транзакции изменен", ['transaction_id' => $transaction_id]);
    } 
    // Если транзакции нет, проверяем, есть ли сделка
    elseif (isset($_POST["leads"]["add"][0]["id"])) {
        $lead_id_from_webhook = (int) $_POST["leads"]["add"][0]["id"];
        $event_type = 'lead_add';
        log_payment_info("Вебхук: сделка создана", ['lead_id' => $lead_id_from_webhook]);
    } elseif (isset($_POST["leads"]["status"][0]["id"])) {
        $lead_id_from_webhook = (int) $_POST["leads"]["status"][0]["id"];
        $event_type = 'lead_status';
        log_payment_info("Вебхук: статус сделки изменен", ['lead_id' => $lead_id_from_webhook]);
    }
    // Проверяем, есть ли счет (каталог)
    elseif (isset($_POST["catalogs"]["update"][0]["id"])) {
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
    
    // Если получен вебхук от счета (каталога), обрабатываем его
    if ($catalog_element_id && $catalog_id) {
        
        try {
            // Извлекаем данные из вебхука
            $catalog_data = $_POST["catalogs"]["update"][0] ?? $_POST["catalogs"]["add"][0] ?? null;
            
            if (!$catalog_data) {
                throw new Exception("Не удалось извлечь данные счета из вебхука");
            }
            
            // Извлекаем данные из кастомных полей счета
            $custom_fields = $catalog_data['custom_fields'] ?? [];
            $bill_status = null;
            $bill_price = null;
            $bill_payment_date = null;
            // $payment_link уже инициализирована в начале скрипта
            
            foreach ($custom_fields as $field) {
                $code = $field['code'] ?? null;
                $field_id = $field['id'] ?? null;
                $values = $field['values'] ?? [];
                
                if ($code === 'BILL_STATUS') {
                    $bill_status = is_array($values[0]) ? ($values[0]['value'] ?? null) : ($values[0] ?? null);
                } elseif ($code === 'BILL_PRICE') {
                    $bill_price = is_array($values[0]) ? ($values[0]['value'] ?? null) : ($values[0] ?? null);
                } elseif ($code === 'BILL_PAYMENT_DATE') {
                    // Дата может быть массивом значений или объектом
                    if (is_array($values[0])) {
                        $bill_payment_date = $values[0]['value'] ?? $values[0][0] ?? null;
                    } else {
                        $bill_payment_date = $values[0] ?? null;
                    }
                    // Если это массив значений напрямую (как в вашем случае)
                    if (is_array($values) && count($values) > 0 && !is_array($values[0])) {
                        $bill_payment_date = $values[0];
                    }
                } elseif ($code === 'INVOICE_HASH_LINK' || $field_id == 1622603 || $field_id == 1630781) {
                    // Ссылка на оплату (проверяем по code INVOICE_HASH_LINK или по field_id 1622603, 1630781)
                    $payment_link_raw = is_array($values[0]) ? ($values[0]['value'] ?? null) : ($values[0] ?? null);
                    $payment_link = $payment_link_raw ? trim($payment_link_raw) : null;
                    // Если после trim получилась пустая строка, считаем как null
                    if ($payment_link === '') {
                        $payment_link = null;
                    }
                }
                
                // Останавливаемся, если все обязательные поля найдены
                // payment_link необязательно - может быть пустым (тогда будет ПСБ)
                if ($bill_status && $bill_price && $bill_payment_date) {
                    break;
                }
            }
            
            // Проверяем, что счет оплачен
            if ($bill_status !== 'Оплачен' && strpos(strtolower($bill_status ?? ''), 'оплач') === false) {
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
            
            // Ищем связанную сделку через массовый API links
            $lead_id_from_catalog = null;
            
            // Поиск через связанные сущности (links)
            // Используем поиск через не закрытые сделки, созданные за последние 6 месяцев
            // Проверяем сделки по мере получения (оптимизация - не ждем сбора всех)
            try {
                // Вычисляем дату 6 месяцев назад (в Unix timestamp)
                    $six_months_ago = time() - (6 * 30 * 24 * 60 * 60); // 6 месяцев назад (примерно 180 дней)
                    $six_months_ago_timestamp = $six_months_ago;
                    
                    // Размер пула для получения сделок
                    $leads_pool_size = 250; // Максимум 250 сделок за один запрос
                    $page = 1;
                    $total_processed = 0;
                    $total_checked = 0;
                    
                    // Получаем сделки по фильтру, разбивая на пулы, и проверяем по мере получения
                    do {
                        $api_url = "/api/v4/leads?limit={$leads_pool_size}&page={$page}&filter[created_at][from]={$six_months_ago_timestamp}&order[created_at]=desc";
                        
                        $LEADS_RESPONSE = get($subdomain, $api_url, $data);
                        
                        if (!isset($LEADS_RESPONSE['_embedded']['leads']) || !is_array($LEADS_RESPONSE['_embedded']['leads']) || empty($LEADS_RESPONSE['_embedded']['leads'])) {
                            break; // Нет больше сделок
                        }
                        
                        $leads_in_page = count($LEADS_RESPONSE['_embedded']['leads']);
                        $total_processed += $leads_in_page;
                        
                        // Фильтруем только не закрытые сделки
                        // В AmoCRM закрытые сделки имеют поле closed_at (timestamp закрытия)
                        $open_leads = array_filter($LEADS_RESPONSE['_embedded']['leads'], function($lead) {
                            $closed_at = $lead['closed_at'] ?? null;
                            // Если closed_at пустое или null, сделка не закрыта
                            return empty($closed_at);
                        });
                        
                        $page_lead_ids = array_map(function($lead) {
                            return $lead['id'] ?? null;
                        }, $open_leads);
                        $page_lead_ids = array_filter($page_lead_ids);
                        
                        // Сразу проверяем не закрытые сделки из этого пула
                        if (!empty($page_lead_ids)) {
                            // Разбиваем на части по 50 сделок для массового API links
                            $chunk_size = 50;
                            $lead_chunks = array_chunk($page_lead_ids, $chunk_size);
                            
                            foreach ($lead_chunks as $chunk_index => $lead_chunk) {
                                if ($lead_id_from_catalog) {
                                    break 2; // Выходим из обоих циклов, если нашли
                                }
                                
                                // Используем массовый API с filter[entity_id] для части сделок
                                $query_parts = [];
                                foreach ($lead_chunk as $lead_id) {
                                    $query_parts[] = 'filter[entity_id][]=' . urlencode($lead_id);
                                }
                                $query_parts[] = 'filter[to_entity_id]=' . urlencode($catalog_element_id);
                                $query_parts[] = 'filter[to_entity_type]=' . urlencode('catalog_elements');
                                
                                if ($catalog_id) {
                                    $query_parts[] = 'filter[to_catalog_id]=' . urlencode($catalog_id);
                                }
                                
                                $query_string = implode('&', $query_parts);
                                $links_api_url = "/api/v4/leads/links?" . $query_string;
                                
                                // Выполняем запрос напрямую через cURL
                                $link = 'https://' . $subdomain . '.amocrm.ru' . $links_api_url;
                                $access_token = $data['access_token'];
                                $headers = ['Authorization: Bearer ' . $access_token];
                                
                                $curl = curl_init();
                                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-oAuth-client/1.0');
                                curl_setopt($curl, CURLOPT_URL, $link);
                                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($curl, CURLOPT_HEADER, false);
                                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
                                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
                                $out = curl_exec($curl);
                                $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                                $curl_error = curl_error($curl);
                                curl_close($curl);
                                
                                $total_checked += count($lead_chunk);
                                
                                if ($http_code >= 200 && $http_code <= 204) {
                                    $LINKS_RESPONSE = json_decode($out, true);
                                    
                                    $links_count = isset($LINKS_RESPONSE['_embedded']['links']) && is_array($LINKS_RESPONSE['_embedded']['links']) ? count($LINKS_RESPONSE['_embedded']['links']) : 0;
                                    
                                    if ($links_count > 0) {
                                        // Проверяем все найденные связи, а не только первую
                                        $found_matching_link = false;
                                        
                                        foreach ($LINKS_RESPONSE['_embedded']['links'] as $link_index => $found_link) {
                                            // Извлекаем ID сделки из связи
                                            $lead_id_from_link = null;
                                            
                                            // Вариант 1: entity_type = leads и to_entity_id = catalog_element_id (новая структура API)
                                            if (isset($found_link['entity_id']) && 
                                                isset($found_link['entity_type']) && 
                                                $found_link['entity_type'] === 'leads' &&
                                                isset($found_link['to_entity_id']) &&
                                                $found_link['to_entity_id'] == $catalog_element_id) {
                                                $lead_id_from_link = (int) $found_link['entity_id'];
                                            }
                                            // Вариант 2: from_entity_type = leads и to_entity_id = catalog_element_id (старая структура API)
                                            elseif (isset($found_link['from_entity_id']) && 
                                                isset($found_link['from_entity_type']) && 
                                                $found_link['from_entity_type'] === 'leads' &&
                                                isset($found_link['to_entity_id']) &&
                                                $found_link['to_entity_id'] == $catalog_element_id) {
                                                $lead_id_from_link = (int) $found_link['from_entity_id'];
                                            } 
                                            // Вариант 3: to_entity_type = leads и from_entity_id = catalog_element_id (старая структура API)
                                            elseif (isset($found_link['to_entity_id']) && 
                                                      isset($found_link['to_entity_type']) && 
                                                      $found_link['to_entity_type'] === 'leads' &&
                                                      isset($found_link['from_entity_id']) &&
                                                      $found_link['from_entity_id'] == $catalog_element_id) {
                                                $lead_id_from_link = (int) $found_link['to_entity_id'];
                                            }
                                            
                                            if ($lead_id_from_link) {
                                                $lead_id_from_catalog = $lead_id_from_link;
                                                $found_matching_link = true;
                                                log_payment_info("✓ Сделка найдена через массовый API links", [
                                                    'lead_id' => $lead_id_from_catalog,
                                                    'total_checked' => $total_checked,
                                                    'total_processed' => $total_processed
                                                ]);
                                                break 2; // Выходим из обоих циклов, если нашли
                                            }
                                        }
                                        
                                        if (!$found_matching_link) {
                                            log_payment_warning("Связи найдены, но не соответствуют критериям для извлечения lead_id", [
                                                'catalog_element_id' => $catalog_element_id,
                                                'total_links' => $links_count
                                            ]);
                                        }
                                    }
                                } else {
                                    log_payment_warning("Ошибка HTTP при запросе к массовому API links", [
                                        'page' => $page,
                                        'chunk_index' => $chunk_index + 1,
                                        'http_code' => $http_code,
                                        'curl_error' => $curl_error,
                                        'response' => substr($out, 0, 500),
                                        'api_url' => $links_api_url
                                    ]);
                                }
                            }
                        }
                        
                        // Если уже нашли, выходим из цикла получения пулов
                        if ($lead_id_from_catalog) {
                            break;
                        }
                        
                        // Если получили меньше сделок, чем размер пула, значит это последняя страница
                        if ($leads_in_page < $leads_pool_size) {
                            break;
                        }
                        
                        $page++;
                        
                        // Защита от бесконечного цикла (максимум 20 страниц = 5000 сделок)
                        if ($page > 20) {
                            log_payment_warning("Достигнут лимит страниц при получении сделок", [
                                'max_pages' => 20,
                                'total_processed' => $total_processed,
                                'total_checked' => $total_checked
                            ]);
                            break;
                        }
                    } while (true);
                    
                    if ($lead_id_from_catalog) {
                        log_payment_info("Сделка найдена", [
                            'lead_id' => $lead_id_from_catalog,
                            'total_checked' => $total_checked
                        ]);
                    } else {
                        log_payment_warning("Сделка не найдена через массовый API links", [
                            'total_processed' => $total_processed,
                            'total_checked' => $total_checked,
                            'pages_processed' => $page - 1
                        ]);
                    }
            } catch (Exception $mass_api_e) {
                log_payment_warning("Ошибка при использовании массового API links", [
                    'error' => $mass_api_e->getMessage()
                ]);
            }
            
            // Проверяем, найдена ли сделка
            if (!$lead_id_from_catalog) {
                // Дополнительная диагностика: проверяем все кастомные поля счета на наличие любых ссылок
                $all_field_codes = [];
                $all_field_names = [];
                $all_field_types = [];
                if (isset($CATALOG_ELEMENT['custom_fields_values'])) {
                    foreach ($CATALOG_ELEMENT['custom_fields_values'] as $field) {
                        // Правильное извлечение данных из поля
                        $field_code = $field['code'] ?? null;
                        $field_name = $field['name'] ?? $field['field_name'] ?? null;
                        $field_type = $field['field_type'] ?? null;
                        
                        $all_field_codes[] = $field_code ?: 'no_code';
                        $all_field_names[] = $field_name ?: 'no_name';
                        $all_field_types[] = $field_type ?: 'no_type';
                    }
                }
                
                log_payment_error("Не удалось найти связанную сделку для счета", [
                    'catalog_element_id' => $catalog_element_id,
                    'catalog_id' => $catalog_id,
                    'catalog_name' => $CATALOG_ELEMENT['name'] ?? 'not_set',
                    'total_checked' => $total_checked ?? 0
                ]);
                
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Не удалось найти связанную сделку для счета. Счет не привязан к сделке в AmoCRM.',
                    'catalog_element_id' => $catalog_element_id,
                    'catalog_id' => $catalog_id,
                    'recommendation' => 'Проверьте в AmoCRM, что счет привязан к сделке через механизм связей. После привязки счета к сделке, обработка будет выполнена автоматически при следующем обновлении счета.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Извлекаем "Ссылку на оплату" из API ответа, если не была извлечена из вебхука
            if (!$payment_link && isset($CATALOG_ELEMENT['custom_fields_values'])) {
                $catalog_custom_fields = $CATALOG_ELEMENT['custom_fields_values'] ?? [];
                foreach ($catalog_custom_fields as $field) {
                    $field_code = $field['code'] ?? null;
                    $field_id = $field['field_id'] ?? null;
                    
                    // Проверяем по code (INVOICE_HASH_LINK) или по field_id (1622603, 1630781)
                    if ($field_code === 'INVOICE_HASH_LINK' || $field_id == 1622603 || $field_id == 1630781) {
                        $field_values = $field['values'] ?? [];
                        if (!empty($field_values)) {
                            $payment_link_raw = is_array($field_values[0]) ? ($field_values[0]['value'] ?? null) : ($field_values[0] ?? null);
                            $payment_link = $payment_link_raw ? trim($payment_link_raw) : null;
                            // Если после trim получилась пустая строка, считаем как null
                            if ($payment_link === '') {
                                $payment_link = null;
                            }
                        }
                        break;
                    }
                }
            }
            
            // Используем данные из счета как транзакцию
            $amount = (float) $bill_price;
            // Дата оплаты может быть в разных форматах
            if (is_array($bill_payment_date)) {
                $payment_date = !empty($bill_payment_date) ? (int) $bill_payment_date[0] : time();
            } else {
                $payment_date = $bill_payment_date ? (int) $bill_payment_date : time();
            }
            $lead_id = $lead_id_from_catalog;
            
            // Преобразуем дату в ISO 8601 формат
            $date_iso = date('c', $payment_date);
            
            // Пропускаем шаг получения транзакции, переходим к получению данных сделки
            // Устанавливаем lead_id для перехода к получению данных сделки
            if ($lead_id_from_catalog) {
                $lead_id = $lead_id_from_catalog;
            }
            
        } catch (Exception $e) {
            log_payment_error("✗ Ошибка при обработке счета", [
                'catalog_element_id' => $catalog_element_id,
                'catalog_id' => $catalog_id,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка при обработке счета: ' . $e->getMessage()
            ]);
            exit;
        }
        
        // Переход к получению данных сделки после успешной обработки счета
        if (isset($lead_id) && $lead_id) {
            goto get_lead_data;
        }
    }
    
    // Если получен ID сделки, нужно найти транзакции в этой сделке
    if ($lead_id_from_webhook && !$transaction_id) {
        log_payment_info("Получен вебхук от сделки, ищем транзакции в сделке", [
            'lead_id' => $lead_id_from_webhook
        ]);
        
        try {
            // Получаем данные сделки с транзакциями
            $api_url = '/api/v4/leads/' . $lead_id_from_webhook . '?with=transactions';
            $LEAD_WITH_TRANSACTIONS = get($subdomain, $api_url, $data);
            
            log_payment_info("Данные сделки с транзакциями получены", [
                'lead_id' => $lead_id_from_webhook,
                'has_embedded' => isset($LEAD_WITH_TRANSACTIONS['_embedded']),
                'has_transactions' => isset($LEAD_WITH_TRANSACTIONS['_embedded']['transactions']),
                'transactions_count' => isset($LEAD_WITH_TRANSACTIONS['_embedded']['transactions']) 
                    ? count($LEAD_WITH_TRANSACTIONS['_embedded']['transactions']) 
                    : 0
            ]);
            
            // Ищем транзакции в сделке
            if (isset($LEAD_WITH_TRANSACTIONS['_embedded']['transactions']) && 
                is_array($LEAD_WITH_TRANSACTIONS['_embedded']['transactions']) &&
                !empty($LEAD_WITH_TRANSACTIONS['_embedded']['transactions'])) {
                
                // Берем последнюю транзакцию (самую свежую)
                $transactions = $LEAD_WITH_TRANSACTIONS['_embedded']['transactions'];
                $last_transaction = end($transactions);
                $transaction_id = $last_transaction['id'] ?? null;
                
                if ($transaction_id) {
                    log_payment_info("✓ Найдена транзакция в сделке", [
                        'lead_id' => $lead_id_from_webhook,
                        'transaction_id' => $transaction_id,
                        'total_transactions' => count($transactions),
                        'transaction_price' => $last_transaction['price'] ?? $last_transaction['value'] ?? 'not_set',
                        'transaction_date' => $last_transaction['created_at'] ?? $last_transaction['date'] ?? 'not_set'
                    ]);
                } else {
                    log_payment_warning("Транзакции найдены, но не удалось извлечь ID", [
                        'lead_id' => $lead_id_from_webhook,
                        'transactions_count' => count($transactions),
                        'last_transaction_keys' => is_array($last_transaction) ? array_keys($last_transaction) : 'not_array'
                    ]);
                }
            } else {
                // Если транзакций нет в embedded, пробуем получить через отдельный запрос
                log_payment_info("Транзакции не найдены в embedded, пробуем отдельный запрос", [
                    'lead_id' => $lead_id_from_webhook
                ]);
                
                $api_url = '/api/v4/leads/' . $lead_id_from_webhook . '/transactions';
                $TRANSACTIONS_RESPONSE = get($subdomain, $api_url, $data);
                
                log_payment_info("Ответ на запрос транзакций", [
                    'has_embedded' => isset($TRANSACTIONS_RESPONSE['_embedded']),
                    'has_transactions' => isset($TRANSACTIONS_RESPONSE['_embedded']['transactions']),
                    'transactions_count' => isset($TRANSACTIONS_RESPONSE['_embedded']['transactions']) 
                        ? count($TRANSACTIONS_RESPONSE['_embedded']['transactions']) 
                        : 0,
                    'response_structure' => is_array($TRANSACTIONS_RESPONSE) ? array_keys($TRANSACTIONS_RESPONSE) : 'not_array'
                ]);
                
                if (isset($TRANSACTIONS_RESPONSE['_embedded']['transactions']) && 
                    is_array($TRANSACTIONS_RESPONSE['_embedded']['transactions']) &&
                    !empty($TRANSACTIONS_RESPONSE['_embedded']['transactions'])) {
                    
                    $transactions = $TRANSACTIONS_RESPONSE['_embedded']['transactions'];
                    $last_transaction = end($transactions);
                    $transaction_id = $last_transaction['id'] ?? null;
                    
                    if ($transaction_id) {
                        log_payment_info("✓ Транзакция найдена через отдельный запрос", [
                            'lead_id' => $lead_id_from_webhook,
                            'transaction_id' => $transaction_id,
                            'total_transactions' => count($transactions)
                        ]);
                    }
                }
            }
            
            if (!$transaction_id) {
                log_payment_info("ℹ В сделке не найдено транзакций - пропускаем обработку", [
                    'lead_id' => $lead_id_from_webhook,
                    'note' => 'Транзакция может быть создана позже. Обработка будет выполнена при получении вебхука от транзакции.'
                ]);
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
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка при поиске транзакций в сделке: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    
    // Получаем данные транзакции из AmoCRM API
    $api_url = '/api/v4/transactions/' . $transaction_id;
    try {
        $TRANSACTION = get($subdomain, $api_url, $data);
    } catch (Exception $e) {
        log_payment_error("✗ Ошибка при получении данных транзакции из AmoCRM", [
            'transaction_id' => $transaction_id,
            'api_url' => $api_url,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'trace' => $e->getTraceAsString()
        ], 'add_payment.php');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка при получении данных транзакции: ' . $e->getMessage()
        ]);
        exit;
    }
    
    // Извлекаем данные из транзакции
    $lead_id = $TRANSACTION['lead_id'] ?? null;
    $amount = $TRANSACTION['price'] ?? $TRANSACTION['value'] ?? null;
    $transaction_date = $TRANSACTION['created_at'] ?? $TRANSACTION['date'] ?? time();
    
    log_payment_info("Извлечение полей из транзакции", [
        'lead_id_raw' => $TRANSACTION['lead_id'] ?? 'not_set',
        'price_raw' => $TRANSACTION['price'] ?? 'not_set',
        'value_raw' => $TRANSACTION['value'] ?? 'not_set',
        'created_at_raw' => $TRANSACTION['created_at'] ?? 'not_set',
        'date_raw' => $TRANSACTION['date'] ?? 'not_set',
        'extracted_lead_id' => $lead_id,
        'extracted_amount' => $amount,
        'extracted_date' => $transaction_date
    ]);
    
    if (!$lead_id) {
        log_payment_error("✗ Транзакция не содержит lead_id", [
            'transaction_id' => $transaction_id,
            'transaction_keys' => array_keys($TRANSACTION),
            'transaction_data' => $TRANSACTION
        ]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Транзакция не связана со сделкой (lead_id отсутствует)'
        ]);
        exit;
    }
    
    if (!$amount || !is_numeric($amount)) {
        log_payment_error("Транзакция не содержит сумму или сумма не числовая", [
            'transaction_id' => $transaction_id,
            'amount_raw' => $amount
        ]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Транзакция не содержит корректную сумму оплаты'
        ]);
        exit;
    }
    
    // Преобразуем дату в ISO 8601 формат
    if (is_numeric($transaction_date)) {
        // Unix timestamp
        $date_iso = date('c', $transaction_date);
    } else {
        // Строка даты
        $date_timestamp = strtotime($transaction_date);
        if ($date_timestamp === false) {
            $date_iso = date('c'); // Используем текущую дату
            log_payment_warning("Не удалось распарсить дату транзакции, используем текущую", [
                'transaction_date' => $transaction_date,
                'current_date_iso' => $date_iso
            ]);
        } else {
            $date_iso = date('c', $date_timestamp);
        }
    }
    
    // Проверяем, что у нас есть все необходимые данные
    if (!$lead_id || !$amount || !$date_iso) {
        log_payment_error("✗ Не все необходимые данные получены", [
            'has_lead_id' => !empty($lead_id),
            'has_amount' => !empty($amount),
            'has_date_iso' => !empty($date_iso),
            'transaction_id' => $transaction_id ?? 'not_set',
            'catalog_element_id' => $catalog_element_id ?? 'not_set'
        ]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не удалось получить все необходимые данные для обработки оплаты'
        ]);
        exit;
    }
    
    // Получаем данные сделки из AmoCRM API
    get_lead_data:
    $api_url = '/api/v4/leads/' . $lead_id;
    try {
        $LEAD = get($subdomain, $api_url, $data);
    } catch (Exception $e) {
        log_payment_error("✗ Ошибка при получении данных сделки из AmoCRM", [
            'lead_id' => $lead_id,
            'api_url' => $api_url,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'trace' => $e->getTraceAsString()
        ], 'add_payment.php');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка при получении данных сделки: ' . $e->getMessage()
        ]);
        exit;
    }
    
    // Извлекаем clientId из кастомных полей сделки
    $client_id = null;
    $custom_fields_values = $LEAD["custom_fields_values"] ?? [];
    $profile_link = null;
    
    // Список возможных field_id для clientId (можно добавить в конфиг)
    // Пока используем пустой массив - нужно будет указать field_id после настройки поля в AmoCRM
    $client_id_field_ids = [];
    
    // Если в конфиге указан field_id для clientId
    if (function_exists('get_config')) {
        $client_id_field_id = get_config('amo.client_id_field_id');
        if ($client_id_field_id) {
            $client_id_field_ids[] = $client_id_field_id;
        }
    }
    
    foreach ($custom_fields_values as $field) {
        $field_id = $field["field_id"] ?? null;
        $field_value = $field["values"][0]["value"] ?? null;
        
        // Проверяем, является ли это полем для clientId
        if (in_array($field_id, $client_id_field_ids) && !empty($field_value)) {
            $client_id = trim($field_value);
            log_payment_info("clientId найден в кастомном поле", [
                'field_id' => $field_id,
                'clientId' => $client_id
            ]);
            break;
        }
        
        // Сохраняем ссылку на профиль для дальнейшего использования
        if ($field_id == 1630807 && !empty($field_value)) {
            $profile_link = $field_value;
        }
    }
    
    // Если clientId не найден в кастомных полях, пытаемся получить через GetStudents по ссылке на профиль
    if (!$client_id && $profile_link) {
        // Извлекаем profile_id из ссылки: https://{subdomain}.t8s.ru/Profile/{profile_id}
        if (preg_match('/\/Profile\/(\d+)/', $profile_link, $matches)) {
            $profile_id = $matches[1];
            
            try {
                
                // Пробуем получить студента по profile_id (Id)
                $get_student_params = [
                    'Id' => $profile_id
                ];
                $api_response = call_hollyhop_api('GetStudents', $get_student_params, $auth_key, $api_base_url);
                
                // Обрабатываем ответ (аналогично add_student.php)
                $student_info = null;
                if (is_array($api_response)) {
                    if (isset($api_response['ClientId']) || isset($api_response['clientId'])) {
                        $student_info = $api_response;
                    } elseif (isset($api_response['Students']) && is_array($api_response['Students'])) {
                        foreach ($api_response['Students'] as $idx => $student) {
                            if (is_array($student) && (($student['Id'] ?? $student['id'] ?? null) == $profile_id)) {
                                $student_info = $student;
                                break;
                            }
                        }
                    }
                }
                
                if ($student_info) {
                    $client_id = $student_info['ClientId'] ?? $student_info['clientId'] ?? null;
                    if ($client_id) {
                        log_payment_info("clientId получен через GetStudents", [
                            'profile_id' => $profile_id,
                            'clientId' => $client_id
                        ]);
                    } else {
                        log_payment_warning("Студент найден, но clientId отсутствует", [
                            'profile_id' => $profile_id
                        ]);
                    }
                } else {
                    log_payment_warning("Студент не найден в ответе GetStudents", [
                        'profile_id' => $profile_id
                    ]);
                }
            } catch (Exception $e) {
                log_payment_error("✗ Ошибка при получении clientId через GetStudents", [
                    'profile_id' => $profile_id,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            log_payment_warning("Не удалось извлечь profile_id из ссылки", [
                'profile_link' => $profile_link,
                'regex_pattern' => '/\/Profile\/(\d+)/'
            ]);
        }
    }
    
    // Если clientId всё ещё не найден, пытаемся найти студента по контактам сделки
    if (!$client_id) {
        try {
            // Получаем контакты сделки
            $api_url = '/api/v4/leads/' . $lead_id . '?with=contacts';
            $LEAD_WITH_CONTACTS = get($subdomain, $api_url, $data);
            
            $contact_phone = null;
            $contact_email = null;
            
            // Извлекаем телефон и email из контактов
            if (isset($LEAD_WITH_CONTACTS['_embedded']['contacts']) && 
                is_array($LEAD_WITH_CONTACTS['_embedded']['contacts']) &&
                !empty($LEAD_WITH_CONTACTS['_embedded']['contacts'])) {
                
                $contact_id = $LEAD_WITH_CONTACTS['_embedded']['contacts'][0]['id'] ?? null;
                if ($contact_id) {
                    $api_url = '/api/v4/contacts/' . $contact_id;
                    $CONTACT = get($subdomain, $api_url, $data);
                    
                    $contact_fields = $CONTACT['custom_fields_values'] ?? [];
                    foreach ($contact_fields as $field) {
                        $field_id = $field['field_id'] ?? null;
                        $field_value = $field['values'][0]['value'] ?? null;
                        
                        // Телефон (обычно field_id 1138327) и email (1138329)
                        if ($field_id == 1138327) {
                            $contact_phone = $field_value;
                        } elseif ($field_id == 1138329) {
                            $contact_email = $field_value;
                        }
                    }
                }
            }
            
            // Пытаемся найти студента по телефону или email
            if ($contact_phone || $contact_email) {
                $search_params = [];
                if ($contact_phone) {
                    $search_params['phone'] = $contact_phone;
                } elseif ($contact_email) {
                    $search_params['email'] = $contact_email;
                }
                
                log_payment_info("Поиск студента в Hollyhop по контактам", $search_params);
                
                $api_response = call_hollyhop_api('GetStudents', $search_params, $auth_key, $api_base_url);
                
                // Обрабатываем ответ GetStudents
                $found_students = [];
                if (is_array($api_response)) {
                    if (isset($api_response['Students']) && is_array($api_response['Students'])) {
                        $found_students = $api_response['Students'];
                    } elseif (isset($api_response['ClientId']) || isset($api_response['clientId'])) {
                        $found_students = [$api_response];
                    } elseif (is_array($api_response) && !empty($api_response) && isset($api_response[0])) {
                        $found_students = $api_response;
                    }
                }
                
                if (!empty($found_students)) {
                    // Берем первого найденного студента
                    $found_student = $found_students[0];
                    $client_id = $found_student['ClientId'] ?? $found_student['clientId'] ?? null;
                    
                    if ($client_id) {
                        log_payment_info("clientId найден через поиск по контактам", [
                            'clientId' => $client_id,
                            'search_by' => $contact_phone ? 'phone' : 'email',
                            'students_found' => count($found_students)
                        ]);
                    } else {
                        log_payment_warning("Студент найден по контактам, но clientId отсутствует", [
                            'found_students_count' => count($found_students)
                        ]);
                    }
                } else {
                    log_payment_warning("Студент не найден в Hollyhop по контактам", [
                        'search_params' => $search_params
                    ]);
                }
            } else {
                log_payment_warning("В сделке не найдены контакты (телефон/email) для поиска студента");
            }
            
        } catch (Exception $contact_e) {
            log_payment_warning("Ошибка при поиске студента по контактам", [
                'error' => $contact_e->getMessage()
            ]);
        }
    }
    
    // Если clientId всё ещё не найден, возвращаем ошибку
    if (!$client_id) {
        log_payment_error("✗✗✗ Не удалось найти clientId в сделке", [
            'lead_id' => $lead_id,
            'custom_fields_count' => count($custom_fields_values),
            'profile_link' => $profile_link,
            'searched_field_ids' => $client_id_field_ids,
            'custom_fields_summary' => array_map(function($field) {
                return [
                    'field_id' => $field['field_id'] ?? 'unknown',
                    'has_value' => !empty($field['values'][0]['value'] ?? null)
                ];
            }, $custom_fields_values)
        ]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не удалось найти clientId студента в сделке. Убедитесь, что в сделке заполнено поле с ID студента (clientId) из Hollyhop, ссылка на профиль студента, или что в сделке есть контакт с телефоном/email, по которому можно найти студента в Hollyhop.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    log_payment_info("clientId найден в сделке", [
        'lead_id' => $lead_id,
        'clientId' => $client_id,
        'profile_link' => $profile_link
    ]);
    
    // Получаем officeOrCompanyId из данных студента через GetStudents
    $office_id = null;
    try {
        $get_student_params = [
            'clientId' => $client_id
        ];
        
        $api_response = call_hollyhop_api('GetStudents', $get_student_params, $auth_key, $api_base_url);
        
        // API может вернуть:
        // 1. Объект с полем Students (массив студентов)
        // 2. Массив студентов напрямую
        // 3. Один объект студента (при использовании clientId)
        $student_info = null;
        
        if (is_array($api_response)) {
            // Проверяем, это массив студентов или объект с полем Students
            if (isset($api_response['Students']) && is_array($api_response['Students'])) {
                // Ищем студента с нужным ClientId в массиве
                foreach ($api_response['Students'] as $idx => $student) {
                    if (is_array($student)) {
                        $student_client_id = $student['ClientId'] ?? $student['clientId'] ?? null;
                        if ($student_client_id == $client_id) {
                            $student_info = $student;
                            break;
                        }
                    }
                }
            } elseif (isset($api_response['ClientId']) || isset($api_response['clientId'])) {
                // Это один студент (при использовании clientId)
                $student_info = $api_response;
            } else {
                // Это массив студентов напрямую
                foreach ($api_response as $idx => $student) {
                    if (is_array($student)) {
                        $student_client_id = $student['ClientId'] ?? $student['clientId'] ?? null;
                        if ($student_client_id == $client_id) {
                            $student_info = $student;
                            break;
                        }
                    }
                }
            }
        }
        
        if ($student_info === null) {
            log_payment_error("✗ Студент не найден в ответе GetStudents", [
                'clientId' => $client_id,
                'response_structure' => is_array($api_response) ? array_keys($api_response) : 'not_array'
            ]);
            throw new Exception("Студент с clientId = {$client_id} не найден в системе Hollyhop");
        }
        
        // Извлекаем officeOrCompanyId из данных студента
        // Сначала пробуем прямые поля
        $office_id = $student_info['OfficeOrCompanyId'] ?? 
                     $student_info['officeOrCompanyId'] ?? 
                     $student_info['OfficeOrCompany'] ?? 
                     $student_info['officeOrCompany'] ?? null;
        
        // Если не найдено, пробуем извлечь из массива OfficesAndCompanies
        if ($office_id === null) {
            if (isset($student_info['OfficesAndCompanies']) && 
                is_array($student_info['OfficesAndCompanies']) && 
                !empty($student_info['OfficesAndCompanies'])) {
                
                $first_office = $student_info['OfficesAndCompanies'][0];
                if (is_array($first_office) && !empty($first_office)) {
                    // Пробуем разные варианты названий полей
                    $office_id = $first_office['Id'] ?? 
                                 $first_office['id'] ?? 
                                 $first_office['OfficeOrCompanyId'] ?? 
                                 $first_office['officeOrCompanyId'] ?? 
                                 $first_office['OfficeId'] ?? 
                                 $first_office['officeId'] ?? null;
                }
            }
        }
        
        if ($office_id === null) {
            log_payment_error("✗ officeOrCompanyId не найден в данных студента", [
                'clientId' => $client_id,
                'has_OfficesAndCompanies' => isset($student_info['OfficesAndCompanies'])
            ]);
            throw new Exception("Не удалось получить officeOrCompanyId для студента с clientId = {$client_id}");
        }
        
    } catch (Exception $e) {
        log_payment_error("✗ Ошибка при получении officeOrCompanyId", [
            'clientId' => $client_id,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'trace' => $e->getTraceAsString()
        ], 'add_payment.php');
        
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
    
    // Формируем параметры для AddPayment
    log_payment_info("Формирование параметров для AddPayment", [
        'clientId' => $client_id,
        'officeOrCompanyId' => $office_id,
        'amount' => $amount,
        'date_iso' => $date_iso,
        'has_payment_link' => !empty($payment_link),
        'payment_link' => $payment_link ? substr($payment_link, 0, 20) . '...' : null
    ]);
    
    // Определяем paymentMethodId на основе наличия "Ссылки на оплату"
    // Если поле "Ссылка на оплату" заполнено → paymentMethodId = 23 (Тбанк)
    // Если не заполнено → paymentMethodId = 19 (ПСБ)
    $payment_state = "Unconfirmed"; // Всегда "Проведен, ожидает подтверждения"
    $payment_method_id = !empty($payment_link) ? 23 : 19; // Тбанк (23) или ПСБ (19)
    
    log_payment_info("Определение способа оплаты и статуса", [
        'payment_link' => $payment_link ? substr($payment_link, 0, 20) . '...' : null,
        'payment_method_id' => $payment_method_id,
        'payment_method_name' => $payment_method_id == 23 ? 'Тбанк (23)' : 'ПСБ (19)',
        'payment_state' => $payment_state
    ]);
    
    $payment_params = [
        'clientId' => $client_id,
        'officeOrCompanyId' => $office_id,
        'date' => $date_iso,
        'value' => (float)$amount,
        'state' => $payment_state,
        'paymentMethodId' => $payment_method_id
    ];
    
    // Вызываем API для записи оплаты
    $result = call_hollyhop_api('AddPayment', $payment_params, $auth_key, $api_base_url);
    
    log_payment_info("Оплата успешно записана", [
        'clientId' => $client_id,
        'amount' => $amount,
        'paymentMethodId' => $payment_method_id
    ]);
    
    // Возвращаем успешный ответ
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Оплата успешно записана',
        'payment' => [
            'clientId' => $client_id,
            'officeOrCompanyId' => $office_id,
            'date' => $date_iso,
            'value' => (float)$amount
        ],
        'api_response' => $result
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    
    $error_message = $e->getMessage();
    log_payment_error("Ошибка обработки платежа", [
        'error_message' => $error_message,
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine()
    ]);
    
    echo json_encode([
        'success' => false,
        'error' => $error_message
    ], JSON_UNESCAPED_UNICODE);
}
?>

