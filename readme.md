# Описание проекта

## Общая архитектура системы интеграции

Система представляет собой интеграционный слой между AmoCRM и Hollyhop (T8S), обрабатывающий вебхуки и синхронизирующий данные студентов и платежей.

## Диаграмма потоков данных

```
┌─────────────┐        ┌──────────────┐        ┌─────────────┐
│   AmoCRM    │───────▶│  index.php   │───────▶│  Hollyhop   │
│  (Webhook)  │        │   (Router)   │        │   (T8S)     │
└─────────────┘        └──────────────┘        └─────────────┘
                              │
                              ├──────────────────┐
                              ▼                  ▼
                       ┌─────────────┐    ┌─────────────┐
                       │add_student  │    │add_payment  │
                       │    .php     │    │    .php     │
                       └─────────────┘    └─────────────┘
                              │                  │
                              ▼                  ▼
                       ┌──────────────────────────────┐
                       │      Hollyhop API V2         │
                       │  (AddStudent, GetStudents,   │
                       │   EditContacts, AddPayment)  │
                       └──────────────────────────────┘
```

## Структура файлов

```
project/
├── index.php              # Главный роутер вебхуков
├── add_student.php        # Создание/обновление студентов
├── add_payment.php        # Обработка платежей
├── amo_func.php          # Утилиты работы с AmoCRM API
├── config.php            # Конфигурация и загрузка .env
├── logger.php            # Единая система логирования
├── hook.php              # OAuth инициализация AmoCRM
├── .env                  # Конфигурация (не в git)
├── .env.example          # Шаблон конфигурации
├── tokens.json           # OAuth токены AmoCRM
└── logs/
    ├── app.log           # Общий лог приложения
    └── pay.log           # Специализированный лог платежей
```

## Модули системы

### 1. index.php - Главный роутер

**Назначение**: Точка входа для всех вебхуков (AmoCRM + OkiDoki)

**Обрабатываемые события**:
- `leads.add` - создание сделки
- `leads.status` - изменение статуса сделки
- OkiDoki webhook (status: signed) - подписание договора

**Основной поток**:
```php
Webhook → extractLeadIdFromWebhook() → processAmoCrmLead()
    ↓
fetchLeadData() → buildStudentDataFromLead()
    ↓
sendStudentToHollyhop() → processHollyhopResponse()
    ↓
updateLeadProfileLink() + updateHollyhopAmoDeal()
```

**Ключевые функции**:
- `isOkiDokiSignedContract()` - детектор OkiDoki вебхуков
- `handleOkiDokiSignedContract()` - обработка договоров
- `extractLeadIdFromWebhook()` - извлечение ID сделки
- `processAmoCrmLead()` - основная логика обработки
- `updateHollyhopAmoDeal()` - обновление полей "Сделки АМО" и "Договор Оки"

---

### 2. add_student.php - Управление студентами

**Назначение**: CRUD операции со студентами в Hollyhop

**API методы**:
- `POST /add_student.php` - создание/обновление студента

**Основной поток**:
```php
POST данные → валидация → поиск дубликатов по телефону
    ↓
Студент найден? 
    ├─ Да → использовать существующие данные (без обновления)
    └─ Нет → AddStudent API → EditContacts API
         ↓
    GetStudents (получение Id профиля)
         ↓
    Обновление поля "Сделки АМО" через EditUserExtraFields
```

**Маппинг данных**:
```php
AmoCRM Field → Hollyhop Parameter
─────────────────────────────────────
Имя контакта → firstName, lastName
Телефон      → phone
Email        → email
Дисциплина   → discipline
Уровень      → level (с маппингом: "С нуля" → "A0")
Тип обучения → learningType (с маппингом)
Возраст      → maturity (с маппингом)
Офис         → officeOrCompanyId (с маппингом названий → ID)
Менеджер     → responsible_user (с маппингом)
```

**Особенности**:
- Поиск дубликатов по нормализованному телефону (8 → 7)
- Не обновляет существующих студентов (только использует данные)
- Поддержка fallback-значений (прочерки для имени/фамилии)
- Автоматическое обновление поля "Сделки АМО" с HTML-ссылками

**Ответ**:
```json
{
  "success": true,
  "operation": "created|updated",
  "operation_text": "Студент создан|обновлен",
  "search_result": {
    "found": true|false,
    "found_text": "...",
    "phone": "+7..."
  },
  "clientId": 12345,
  "Id": 67890,
  "link": "https://{subdomain}.t8s.ru/Profile/67890"
}
```

---

### 3. add_payment.php - Обработка платежей

**Назначение**: Регистрация платежей в Hollyhop из AmoCRM

**Источники данных**:
1. **Транзакции** (`transactions.add`, `transactions.status`)
2. **Сделки** (`leads.add`, `leads.status`)
3. **Счета из каталога** (`catalogs.add`, `catalogs.update`)

**Основной поток**:
```php
Webhook → определение типа события
    ↓
Транзакция/Сделка/Счет?
    │
    ├─ Транзакция → GetTransaction → извлечь amount, lead_id
    │
    ├─ Сделка → найти транзакции в сделке → взять последнюю
    │
    └─ Счет → извлечь BILL_PRICE, BILL_PAYMENT_DATE
         ↓
    Поиск связанной сделки (через массовый API links)
         ↓
    GetLead → извлечь clientId (или поиск по контактам)
         ↓
    GetStudents → получить officeOrCompanyId
         ↓
    Определение способа оплаты:
      - Если INVOICE_HASH_LINK заполнено → Тбанк (23)
      - Если пусто → ПСБ (19)
         ↓
    AddPayment API (state: "Unconfirmed")
```

**Маппинг статусов**:
```php
AmoCRM Счет        → Hollyhop Payment State
─────────────────────────────────────────────
"Оплачен"          → "Unconfirmed" (ожидает подтверждения)
"Частично оплачен" → "Unconfirmed"
"Не оплачен"       → "Unpaid"
"Отменен"          → "Unpaid"
```

**Поиск clientId (приоритет)**:
1. Кастомное поле сделки (field_id из конфига)
2. Через ссылку на профиль → GetStudents(Id)
3. Через телефон/email контакта → GetStudents(phone/email)

**Оптимизация поиска сделки для счета**:
- Массовый API `/api/v4/leads/links` (до 50 сделок за запрос)
- Фильтрация только открытых сделок (closed_at = null)
- Поиск за последние 6 месяцев
- Проверка по мере получения (early exit)

---

### 4. amo_func.php - AmoCRM API клиент

**Назначение**: Абстракция работы с AmoCRM API v4

**Функции**:
- `get($subdomain, $url, $data)` - GET запросы
- `post_or_patch($subdomain, $query_data, $url, $data, $method)` - POST/PATCH
- OAuth токены (автообновление через refresh_token)

**Механизм авторизации**:
```php
tokens.json существует?
    ├─ Нет → OAuth flow через hook.php
    └─ Да → проверка времени (time - stored_time > 82800)
         ├─ Истек → refresh_token → обновить tokens.json
         └─ Актуален → использовать access_token
```

**Обработка ошибок**:
```php
HTTP Code → Action
──────────────────────
200-204   → Success
301       → Moved permanently
400       → Bad request (exception)
401       → Unauthorized (exception)
403       → Forbidden (exception)
404       → Not found (exception)
500-503   → Server errors (exception)
```

---

### 5. config.php - Конфигурация

**Назначение**: Централизованная загрузка настроек из .env

**Структура конфигурации**:
```php
$config = [
    'api' => [
        'subdomain'  => getenv('HOLLYHOP_SUBDOMAIN'),
        'auth_key'   => getenv('HOLLYHOP_AUTH_KEY'),
        'base_url'   => 'https://{subdomain}.t8s.ru/Api/V2'
    ],
    'logging' => [
        'enabled'        => true,
        'level'          => 'INFO|WARNING|ERROR|DEBUG',
        'log_dir'        => __DIR__ . '/logs',
        'max_file_size'  => 10485760,  // 10MB
        'rotation_count' => 5
    ],
    'security' => [
        'enable_cors'           => true,
        'cors_origins'          => ['*'],
        'enable_rate_limiting'  => true,
        'rate_limit_requests'   => 100,
        'rate_limit_period'     => 3600
    ]
]
```

**Функции**:
- `load_env_file()` - парсинг .env
- `get_config($key)` - доступ к настройкам (поддержка dot-notation)
- `validate_config()` - проверка обязательных параметров
- `init_directories()` - создание logs/, uploads/

---

### 6. logger.php - Система логирования

**Назначение**: Единая точка для всех логов

**Файлы логов**:
- `logs/app.log` - общий лог всех модулей
- `logs/pay.log` - специализированный лог для платежей (add_payment.php)

**Уровни логирования**:
```
DEBUG   (0) - детальная отладка
INFO    (1) - информационные сообщения
WARNING (2) - предупреждения
ERROR   (3) - ошибки
```

**Формат записи**:
```
[2026-02-12 14:30:45] [INFO] [index.php] Webhook получен
{
  "lead_id": 12345,
  "status_id": 46017064
}
────────────────────────────────────────
```

**Функции**:
- `log_message($message, $data, $level, $source)` - основная
- `log_debug()`, `log_info()`, `log_warning()`, `log_error()` - shortcuts
- `log_exception(Exception $e)` - логирование исключений
- `rotate_log_file($log_file, $max_files)` - ротация при превышении размера

**Ротация**:
```
app.log      → app.log.1 → app.log.2 → ... → app.log.5 (удаляется)
(текущий)      (свежий)    (старый)          (самый старый)
```

---

## Интеграции API

### Hollyhop API V2

**Base URL**: `https://{subdomain}.t8s.ru/Api/V2`

**Используемые методы**:

| Метод | Назначение | Параметры |
|-------|-----------|-----------|
| `AddStudent` | Создание студента | firstName, lastName, phone, email, discipline, level, learningType, maturity, officeOrCompanyId, responsible_user, Status |
| `GetStudents` | Поиск студентов | clientId, Id, phone, email, term, search, q |
| `EditContacts` | Обновление контактов | StudentClientId, mobile, eMail, useMobileBySystem, useEMailBySystem |
| `EditUserExtraFields` | Обновление доп. полей | studentClientId, fields: [{name, value}] |
| `AddPayment` | Добавление платежа | clientId, officeOrCompanyId, date (ISO 8601), value, state, paymentMethodId |

**Авторизация**: `authkey` в теле запроса

**Таймауты**:
- Connection timeout: 15 секунд
- Request timeout: 60-120 секунд (зависит от модуля)

---

### AmoCRM API V4

**Base URL**: `https://{subdomain}.amocrm.ru/api/v4`

**Используемые эндпоинты**:

| Эндпоинт | Метод | Назначение |
|----------|-------|-----------|
| `/leads/{id}` | GET | Получение данных сделки |
| `/leads/{id}` | PATCH | Обновление сделки |
| `/leads/{id}/transactions` | GET | Получение транзакций сделки |
| `/transactions/{id}` | GET | Данные транзакции |
| `/contacts/{id}` | GET | Данные контакта |
| `/contacts/{id}` | PATCH | Обновление контакта |
| `/users/{id}` | GET | Данные пользователя |
| `/catalogs/{catalog_id}/elements/{element_id}` | GET | Данные счета |
| `/leads/links` | GET | Массовый API связей |

**Авторизация**: Bearer token в заголовке `Authorization`

**Параметры запросов**:
- `?with=contacts` - включить связанные контакты
- `?with=transactions` - включить транзакции
- `?filter[entity_id][]=123&filter[entity_id][]=456` - массовая фильтрация
- `?limit=250&page=1` - пагинация

---

## ID полей AmoCRM

```php
// Кастомные поля сделки
AMO_FIELD_DISCIPLINE         = 1575217  // Дисциплина
AMO_FIELD_LEVEL             = 1576357  // Уровень
AMO_FIELD_LEARNING_TYPE     = 1575221  // Тип обучения
AMO_FIELD_MATURITY          = 1575213  // Возраст
AMO_FIELD_OFFICE_OR_COMPANY = 1596219  // Офис/Компания
AMO_FIELD_RESPONSIBLE_USER  = 1590693  // Ответственный
AMO_FIELD_PROFILE_LINK      = 1630807  // Ссылка на профиль
AMO_FIELD_CONTRACT_LINK     = 1632483  // Ссылка на договор

// Поля контакта
AMO_CONTACT_FIELD_PHONE = 1138327  // Телефон
AMO_CONTACT_FIELD_EMAIL = 1138329  // Email

// Поля счета (каталог)
BILL_STATUS          // Статус счета
BILL_PRICE           // Сумма
BILL_PAYMENT_DATE    // Дата оплаты
INVOICE_HASH_LINK    // Ссылка на оплату (field_id: 1622603 или 1630781)
```

---

## Сценарии использования

### 1. Создание нового студента из AmoCRM

```
1. AmoCRM отправляет webhook (leads.add)
2. index.php получает вебхук
3. Извлекает данные сделки и контакта
4. Отправляет POST /add_student.php
5. add_student.php проверяет дубликаты по телефону
6. Если новый: AddStudent API → EditContacts API → GetStudents
7. Обновляет поле "Сделки АМО" через EditUserExtraFields
8. Возвращает clientId, Id, ссылку на профиль
9. index.php обновляет сделку в AmoCRM (поле "Ссылка на профиль")
```

### 2. Обработка платежа по транзакции

```
1. AmoCRM создает транзакцию (transactions.add)
2. add_payment.php получает вебхук
3. Получает данные транзакции: amount, lead_id
4. Получает данные сделки
5. Извлекает clientId из кастомного поля
   ├─ Если нет → поиск через GetStudents по ссылке на профиль
   └─ Если нет → поиск по телефону/email контакта
6. Получает officeOrCompanyId через GetStudents
7. Определяет paymentMethodId (Тбанк/ПСБ)
8. Отправляет AddPayment API (state: "Unconfirmed")
```

### 3. Обработка платежа по счету

```
1. AmoCRM обновляет счет (catalogs.update)
2. add_payment.php получает вебхук
3. Проверяет статус: "Оплачен"?
   └─ Нет → exit (обработается при оплате)
4. Извлекает BILL_PRICE, BILL_PAYMENT_DATE, INVOICE_HASH_LINK
5. Ищет связанную сделку:
   - Массовый API /api/v4/leads/links
   - Фильтр: только открытые сделки, последние 6 месяцев
   - Пакеты по 50 сделок
6. Если сделка не найдена → error + рекомендация
7. Получает clientId из сделки (как в сценарии 2)
8. Определяет paymentMethodId:
   - INVOICE_HASH_LINK заполнен → 23 (Тбанк)
   - INVOICE_HASH_LINK пуст → 19 (ПСБ)
9. AddPayment API (state: "Unconfirmed")
```

### 4. Подписание договора OkiDoki

```
1. OkiDoki отправляет webhook {status: "signed"}
2. index.php определяет тип вебхука
3. Извлекает ФИО, Email, lead_id
4. Получает контакт из сделки
5. Обновляет контакт: имя + email
6. Логирует успешное обновление
```

---

## Обработка ошибок

### Стратегия обработки

```php
try {
    // Основная логика
} catch (Exception $e) {
    log_error("Описание ошибки", [
        'context' => $context_data,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
```

### Типичные ошибки

| Ошибка | Причина | Решение |
|--------|---------|---------|
| "Файл конфигурации не найден" | .env отсутствует | Скопировать .env.example → .env |
| "API не вернул Id студента" | Некорректный ответ AddStudent | Проверить логи, структуру ответа |
| "Студент не найден в базе" | clientId некорректен | Проверить маппинг полей AmoCRM |
| "Не удалось найти связанную сделку для счета" | Счет не привязан к сделке | Привязать счет в AmoCRM |
| "Ошибка подключения к API" | Сеть/таймаут | Увеличить timeout, проверить сеть |

---

## Безопасность

### 1. Защита токенов

```php
// tokens.json
{
  "access_token": "...",
  "refresh_token": "...",
  "time": 1707743445
}
```

**Рекомендации**:
- `tokens.json` в .gitignore
- Права доступа: 600 (только владелец)
- Регулярная ротация через refresh_token

### 2. Защита логов

```apache
# logs/.htaccess
Deny from all
```

```php
// Автоматическое создание при первой записи
if (file_exists(__DIR__ . '/.htaccess')) {
    @file_put_contents($log_dir . '/.htaccess', "Deny from all\n");
}
```

### 3. Валидация входных данных

```php
// Проверка обязательных полей
if (empty($post_data['phone']) && empty($post_data['email'])) {
    throw new Exception("Необходим телефон или email");
}

// Нормализация телефона
$phone = preg_replace('/\D/', '', $phone);
if (substr($phone, 0, 1) === '8') {
    $phone = '7' . substr($phone, 1);
}
```

### 4. Rate Limiting

```php
// config.php
'security' => [
    'enable_rate_limiting'  => true,
    'rate_limit_requests'   => 100,
    'rate_limit_period'     => 3600  // 1 час
]
```

---

## Мониторинг и отладка

### Логирование

**Уровни детализации**:
```php
// .env
LOG_LEVEL=DEBUG  // Максимальная детализация
LOG_LEVEL=INFO   // Стандартный режим (рекомендуется)
LOG_LEVEL=WARNING // Только предупреждения и ошибки
LOG_LEVEL=ERROR   // Только ошибки
```

**Специализированные логи**:
- `logs/app.log` - все модули
- `logs/pay.log` - только add_payment.php (детальная трассировка платежей)

### Ключевые метрики

```php
// Измерение времени выполнения
$start_time = microtime(true);
// ... код ...
$execution_time = round(microtime(true) - $start_time, 2);
log_info("Операция завершена", ['execution_time_seconds' => $execution_time]);
```

**Типичные метрики**:
- Время создания студента: 2-5 секунд
- Время обработки платежа: 3-8 секунд
- Время поиска сделки для счета: 5-20 секунд (зависит от количества сделок)

### Отладка

**Включение детального логирования**:
```php
// Временно для отладки конкретного модуля
log_debug("Детальная информация", $debug_data, 'module.php');
```

**Трассировка вебхуков**:
```php
// Логирует полный $_POST
log_info("Вебхук получен", $_POST, 'index.php');
```

---

## Производительность

### Оптимизации

1. **Поиск студентов по телефону**:
   - Нормализация телефонов (8→7) для точного совпадения
   - Использование count=10000 для больших баз
   - Early exit при нахождении совпадения

2. **Поиск сделки для счета**:
   - Массовый API links (50 сделок за запрос)
   - Фильтрация открытых сделок на уровне API
   - Проверка по мере получения (не ждём сбора всех)
   - Лимит: 20 страниц × 250 = 5000 сделок максимум

3. **Кэширование**:
   - OAuth токены (82800 секунд = ~23 часа)
   - Данные студента (используются в течение обработки)

### Узкие места

1. **GetStudents без фильтров** → может вернуть тысячи записей
2. **Множественные запросы к AmoCRM API** → использовать `?with=contacts,transactions`
3. **Поиск связей для счета** → массовый API + фильтрация

---

## Развертывание

### Требования

- PHP 7.4+
- cURL extension
- JSON extension
- File write permissions (logs/, uploads/)

### Шаги установки

```bash
# 1. Клонировать код
git clone <repository>
cd <project>

# 2. Настроить конфигурацию
cp .env.example .env
nano .env  # Указать HOLLYHOP_SUBDOMAIN, HOLLYHOP_AUTH_KEY

# 3. Создать директории
mkdir -p logs uploads
chmod 755 logs uploads

# 4. Настроить OAuth AmoCRM
# Получить code через браузер
# Установить в hook.php: $oauth_token = '<code>'
php hook.php  # Создаст tokens.json

# 5. Настроить вебхуки в AmoCRM
# URL: https://your-domain.com/index.php
# События: leads.add, leads.status, transactions.add, transactions.status, catalogs.update
```

### Переменные окружения (.env)

```env
HOLLYHOP_SUBDOMAIN=your_subdomain
HOLLYHOP_AUTH_KEY=your_auth_key_here
LOG_LEVEL=INFO
HOLLYHOP_AMO_DEALS_FIELD_ID=  # Опционально
```

---


**Логи**:
- `logs/app.log` - основной лог
- `logs/pay.log` - платежи