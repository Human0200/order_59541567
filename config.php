<?php
/**
 * Конфигурационный файл для API Hollyhop
 * 
 * Конфигурация загружается из файла .env
 * Копируйте .env.example в .env и отредактируйте под ваши нужды:
 * 
 * cp .env.example .env
 * 
 * Затем отредактируйте .env:
 * HOLLYHOP_SUBDOMAIN=your_subdomain
 * HOLLYHOP_AUTH_KEY=your_auth_key_here
 */

/**
 * Загрузить файл .env
 */
function load_env_file($file_path = null) {
    if ($file_path === null) {
        $file_path = __DIR__ . '/.env';
    }
    
    if (!file_exists($file_path)) {
        throw new Exception("Файл конфигурации не найден: {$file_path}\n" .
            "Скопируйте .env.example в .env:\n" .
            "  cp .env.example .env");
    }
    
    $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Пропускаем комментарии и пустые строки
        if (strpos(trim($line), '#') === 0 || empty(trim($line))) {
            continue;
        }
        
        // Парсим переменные
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Удаляем кавычки если есть
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            // Устанавливаем переменную окружения
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Загружаем конфигурацию из .env файла
 */
try {
    load_env_file();
} catch (Exception $e) {
    error_log("Ошибка конфигурации: " . $e->getMessage());
    // Продолжаем со значениями по умолчанию (для обратной совместимости)
}

// Получаем параметры конфигурации
$config = [
    'api' => [
        'subdomain' => getenv('HOLLYHOP_SUBDOMAIN') ?: 'your_subdomain',
        'auth_key' => getenv('HOLLYHOP_AUTH_KEY') ?: 'your_auth_key',
        'base_url' => 'https://' . (getenv('HOLLYHOP_SUBDOMAIN') ?: 'your_subdomain') . '.t8s.ru/Api/V2'
    ],
    'upload' => [
        'max_photo_size' => (int)(getenv('MAX_PHOTO_SIZE') ?: 5242880), // 5MB
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'upload_dir' => __DIR__ . '/uploads'
    ],
    'logging' => [
        'enabled' => true,
        'level' => getenv('LOG_LEVEL') ?: 'INFO', // INFO, WARNING, ERROR
        'log_dir' => __DIR__ . '/logs',
        'max_file_size' => 10485760, // 10MB
        'rotation_count' => 5
    ],
    'security' => [
        'enable_cors' => true,
        'cors_origins' => ['*'], // Установите конкретные домены в production
        'enable_rate_limiting' => true,
        'rate_limit_requests' => 100,
        'rate_limit_period' => 3600 // секунды
    ]
];

// Способ 2: Через файл .env.local (альтернатива)
$env_file = __DIR__ . '/.env.local';
if (file_exists($env_file)) {
    $env_lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value, '\'"'));
        }
    }
}

// Способ 3: Прямое указание (НЕ РЕКОМЕНДУЕТСЯ для production)
// $config = [
//     'api' => [
//         'subdomain' => 'your_subdomain',
//         'auth_key' => 'your_auth_key',
//         'base_url' => 'https://your_subdomain.t8s.ru/Api/V2'
//     ],
//     ...
// ];

// Валидация конфигурации
function validate_config() {
    if (empty($GLOBALS['config']['api']['subdomain']) || 
        $GLOBALS['config']['api']['subdomain'] === 'your_subdomain') {
        throw new Exception('Ошибка конфигурации: установите HOLLYHOP_SUBDOMAIN');
    }
    
    if (empty($GLOBALS['config']['api']['auth_key']) || 
        $GLOBALS['config']['api']['auth_key'] === 'your_auth_key') {
        throw new Exception('Ошибка конфигурации: установите HOLLYHOP_AUTH_KEY');
    }
}

// Получение конфигурации
function get_config($key = null) {
    global $config;
    
    if ($key === null) {
        return $config;
    }
    
    $keys = explode('.', $key);
    $value = $config;
    
    foreach ($keys as $k) {
        if (!isset($value[$k])) {
            return null;
        }
        $value = $value[$k];
    }
    
    return $value;
}

// Инициализация директорий
function init_directories() {
    $log_dir = get_config('logging.log_dir');
    $upload_dir = get_config('upload.upload_dir');
    
    foreach ([$log_dir, $upload_dir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

return $config;
?>

