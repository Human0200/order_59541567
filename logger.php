<?php
/**
 * Единая система логирования для всего проекта
 * 
 * Все логи записываются в один файл: logs/app.log
 * Поддерживает уровни логирования: DEBUG, INFO, WARNING, ERROR
 */

/**
 * Логирование сообщения
 * 
 * @param string $message Сообщение для логирования
 * @param mixed $data Дополнительные данные (массив, объект и т.д.)
 * @param string $level Уровень логирования (DEBUG, INFO, WARNING, ERROR)
 * @param string $source Источник лога (имя файла/скрипта)
 */
function log_message($message, $data = null, $level = 'INFO', $source = null) {
    // Получаем конфигурацию логирования
    $log_config = null;
    if (function_exists('get_config')) {
        $log_config = get_config('logging');
    }
    
    // Проверяем, включено ли логирование
    if ($log_config && isset($log_config['enabled']) && !$log_config['enabled']) {
        return;
    }
    
    // Получаем минимальный уровень логирования из конфига
    $min_level = $log_config['level'] ?? 'INFO';
    $levels = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];
    $current_level = $levels[strtoupper($level)] ?? 1;
    $min_level_num = $levels[strtoupper($min_level)] ?? 1;
    
    // Пропускаем логи ниже минимального уровня
    if ($current_level < $min_level_num) {
        return;
    }
    
    // Определяем источник
    if ($source === null) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $source = isset($backtrace[1]['file']) 
            ? basename($backtrace[1]['file']) 
            : 'unknown';
    }
    
    // Формируем путь к файлу логов
    $log_dir = $log_config['log_dir'] ?? __DIR__ . '/logs';
    $log_file = $log_dir . '/app.log';
    
    // Создаём директорию для логов, если её нет
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
        // Создаём .htaccess для защиты логов (если используется Apache)
        if (file_exists(__DIR__ . '/.htaccess') || strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'Apache') !== false) {
            @file_put_contents($log_dir . '/.htaccess', "Deny from all\n");
        }
    }
    
    // Проверяем права на запись
    if (!is_writable($log_dir) && !is_writable($log_file)) {
        // Пытаемся создать файл или использовать альтернативный путь
        error_log("Не удалось записать в лог файл: {$log_file}. Проверьте права доступа.");
        return;
    }
    
    // Форматируем сообщение
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] [{$source}] {$message}";
    
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
    $result = @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Если не удалось записать, пробуем через error_log
    if ($result === false) {
        error_log("LOGGER ERROR: Не удалось записать в {$log_file}. Сообщение: {$message}");
    }
    
    // Ротация логов (если файл слишком большой)
    if ($log_config && isset($log_config['max_file_size'])) {
        $max_size = $log_config['max_file_size'] ?? 10485760; // 10MB по умолчанию
        if (file_exists($log_file) && filesize($log_file) > $max_size) {
            rotate_log_file($log_file, $log_config['rotation_count'] ?? 5);
        }
    }
}

/**
 * Ротация лог-файла (создание архивных копий)
 */
function rotate_log_file($log_file, $max_files = 5) {
    if (!file_exists($log_file)) {
        return;
    }
    
    // Переименовываем существующие файлы
    for ($i = $max_files - 1; $i >= 1; $i--) {
        $old_file = $log_file . '.' . $i;
        $new_file = $log_file . '.' . ($i + 1);
        
        if (file_exists($old_file)) {
            if ($i >= $max_files - 1) {
                @unlink($old_file); // Удаляем самый старый
            } else {
                @rename($old_file, $new_file);
            }
        }
    }
    
    // Переименовываем текущий файл
    if (file_exists($log_file)) {
        @rename($log_file, $log_file . '.1');
    }
}

/**
 * Вспомогательные функции для разных уровней логирования
 */
function log_debug($message, $data = null, $source = null) {
    log_message($message, $data, 'DEBUG', $source);
}

function log_info($message, $data = null, $source = null) {
    log_message($message, $data, 'INFO', $source);
}

function log_warning($message, $data = null, $source = null) {
    log_message($message, $data, 'WARNING', $source);
}

function log_error($message, $data = null, $source = null) {
    log_message($message, $data, 'ERROR', $source);
}

/**
 * Логирование исключений
 */
function log_exception(Exception $e, $source = null) {
    $message = "EXCEPTION: " . $e->getMessage();
    $data = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    log_error($message, $data, $source);
}
?>

