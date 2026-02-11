<?php

declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/amo_func.php';

header('Content-Type: application/json');

log_info('=== WEBHOOK START ===', [], 'index.php');

try {

    // =============================================
    // 1. Определяем тип входящего запроса
    // =============================================

    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true);

    // Если это JSON (Окидоки / Radist)
    if (is_array($jsonInput) && !empty($jsonInput)) {

        log_info('JSON webhook detected', $jsonInput, 'index.php');

        if (($jsonInput['status'] ?? null) === 'signed') {
            handleOkidokiSigned($jsonInput);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }

        http_response_code(200);
        exit(json_encode(['status' => 'ignored']));
    }

    // =============================================
    // 2. Обработка webhook от AmoCRM
    // =============================================

    if (empty($_POST)) {
        log_warning('Empty POST received', [], 'index.php');
        http_response_code(400);
        exit(json_encode(['error' => 'empty webhook']));
    }

    log_info('Amo webhook received', $_POST, 'index.php');

    $leadId = extractLeadId($_POST);

    if (!$leadId) {
        throw new Exception('Lead ID not found in webhook');
    }

    processAmoLead($leadId);

    http_response_code(200);
    exit(json_encode(['status' => 'processed']));
} catch (Throwable $e) {

    log_error('FATAL ERROR', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'index.php');

    http_response_code(500);
    exit(json_encode(['error' => 'internal error']));
}









// ======================================================
// ================== FUNCTIONS =========================
// ======================================================

function extractLeadId(array $post): ?int
{
    if (isset($post["leads"]["add"][0]["id"])) {
        return (int)$post["leads"]["add"][0]["id"];
    }

    if (isset($post["leads"]["status"][0]["id"])) {
        return (int)$post["leads"]["status"][0]["id"];
    }

    return null;
}




function processAmoLead(int $leadId): void
{
    global $subdomain, $data;

    log_info('Processing lead', ['lead_id' => $leadId], 'index.php');

    $lead = get($subdomain, "/api/v4/leads/{$leadId}?with=contacts", $data);

    if (!$lead) {
        throw new Exception("Lead not found in AmoCRM");
    }

    $contactId = $lead['_embedded']['contacts'][0]['id'] ?? null;

    if (!$contactId) {
        log_warning('Lead has no contact attached', ['lead_id' => $leadId], 'index.php');
        return;
    }

    $contact = get($subdomain, "/api/v4/contacts/{$contactId}", $data);

    $payload = buildStudentPayload($lead, $contact);

    if (!$payload['firstName']) {
        log_warning('First name missing — skip student creation', $payload, 'index.php');
        return;
    }

    createStudentInHollyhop($payload, $leadId);
}




function buildStudentPayload(array $lead, array $contact): array
{
    $nameParts = explode(' ', $contact['name'] ?? '');

    $firstName = $nameParts[0] ?? '';
    $lastName  = $nameParts[1] ?? '';

    $phone = '';
    $email = '';

    foreach ($contact['custom_fields_values'] ?? [] as $field) {
        if ($field['field_code'] === 'PHONE') {
            $phone = $field['values'][0]['value'] ?? '';
        }
        if ($field['field_code'] === 'EMAIL') {
            $email = $field['values'][0]['value'] ?? '';
        }
    }

    return [
        'firstName' => $firstName,
        'lastName'  => $lastName,
        'phone'     => $phone,
        'email'     => $email,
        'Status'    => 'В наборе',

        // ВАЖНО: отключаем "конверты"
        'useMobileBySystem' => false,
        'useEMailBySystem'  => false,
    ];
}




function createStudentInHollyhop(array $payload, int $leadId): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    log_info('Sending to Hollyhop', $payload, 'index.php');

    $ch = curl_init('https://srm.chinatutor.ru/add_student.php');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

    log_info('Hollyhop response', [
        'http_code' => $httpCode,
        'response'  => $response
    ], 'index.php');

    if ($httpCode >= 400) {
        throw new Exception("Hollyhop returned HTTP {$httpCode}");
    }
}




function handleOkidokiSigned(array $data): void
{
    log_info('Processing Okidoki signed', $data, 'index.php');

    // тут можно доработать при необходимости
}
