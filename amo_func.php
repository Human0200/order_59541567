<?php

/**
$res = post_or_patch (
    $subdomain,
    array(
        'id' => $lead_id,
        'status_id' => 46017067,
        'responsible_user_id' => $amo_responsible_user_id
    ),
    '/api/v4/leads/'.$lead_id,
    $data,
    'PATCH'
);

$res = post_or_patch (
    $subdomain,
    array(
        array(
            'name' => $site,
            'status_id' => 46017064,
            'responsible_user_id' => $callback_code-100000000000000,
        )
    ),
    '/api/v4/leads',
    $data,
    'POST'
);

$api_url = '/api/v4/users';
$amo_users = get ($subdomain, $api_url, $data);
**/
date_default_timezone_set("Europe/Moscow");

// Подключаем логгер, если он еще не подключен
if (!function_exists('log_message')) {
    if (file_exists(__DIR__ . '/logger.php')) {
        require_once __DIR__ . '/logger.php';
    }
}

$client_id = '98b0ad7f-864b-47a6-9650-b4bf32aa8927';
$client_secret = 'Qw4JEh8zL3eRXAhvtdzAo5xqjUSVUWAoCmIjAEVNjrkSzgENnvrnL0ymMf66kdm1';
$redirect_uri = 'https://directorchinatutorru.amocrm.ru/';
$subdomain = 'directorchinatutorru';
// $oauth_token = 'def502004e29f64ecca0143b9c14d8cd6cc6d9f45e87f95d58309751f83497685b2fbe090bf4b6c7ad80ef9b14a4e6e03f5518562d1a5a078763d8ffd1b600a0f144942a8632ca182df46b43a2c4830e504b32c31eb10675ae729693e0b01aca72550ec8344c1402f3b29ee6dd73ab4cebb5ac96ea0d0fcec5f3ee49fc612648a1bd23ffdbb9bd2abd9a8cbdcfaf6835dc9f51c8b462a8eb50fb559ae4b9ec51f692854409d001dc8e6dc5a14eaecf13b695766c313a0a655394b915bd8297e1fc316c549e1e8a4a4dcbd3fb6761b7d4c0c23e0cd47798ffe8b6ceaf227876ae74247927510a01f87669a7edad7d479dbc5ca0dcd86cf79fe2f7f531a65a640f938aff0bea47c26e78f3a24d6379f5e8ecd6cefaaf392c06f5256c3786a85db4226b648b6cc5f4b844d6e0adda20cce7841c6c90f9e7f185c0fd049e4810cf6bb58952f5b0d29c8d1e7bc5f8033d86ae574f101aae7100dfcc8b2b7c926abdc4b30bc6a5b6bcf29f68c5ca43a6a53ce3923472fb8cd1b666623e5596e8e070fd99f2349912d57c4f35b0def4ca15633df790874a77d72e6ce9d8fa05d447b9128323d065f3a6cff1887df6ca49e90fbf1c303f2d0951e59e7d173c29d644bdf0ee07d0cedd66aa3f624653e8cb8dd818dacba56be724656abe8e017c92334cf574d93e2780716f7401b3e68b6b5b760f1ec1e83020dce69299';

// auth
$data = json_decode(file_get_contents(__DIR__.'/tokens.json'), 1);
if (time() - (int) $data['time'] > 82800) {
    $link = 'https://' . $subdomain . '.amocrm.ru/oauth2/access_token';
    $refresh_data = [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'refresh_token',
        'refresh_token' => $data['refresh_token'],
        'redirect_uri' => $redirect_uri,
    ];
    $curl = curl_init(); //Сохраняем дескриптор сеанса cURL
    curl_setopt($curl,CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl,CURLOPT_USERAGENT,'amoCRM-oAuth-client/1.0');
    curl_setopt($curl,CURLOPT_URL, $link);
    curl_setopt($curl,CURLOPT_HTTPHEADER,['Content-Type:application/json']);
    curl_setopt($curl,CURLOPT_HEADER, false);
    curl_setopt($curl,CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($curl,CURLOPT_POSTFIELDS, json_encode($refresh_data));
    curl_setopt($curl,CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST, 2);
    $out = curl_exec($curl); //Инициируем запрос к API и сохраняем ответ в переменную
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $code = (int)$code;
    $errors = [
        400 => 'Bad request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not found',
        500 => 'Internal server error',
        502 => 'Bad gateway',
        503 => 'Service unavailable',
    ];
    try
    {
        if ($code < 200 || $code > 204) {
            if (function_exists('log_error')) {
                log_error("Ошибка обновления токена AmoCRM", ['code' => $code, 'error' => isset($errors[$code]) ? $errors[$code] : 'Undefined error', 'response' => $out], 'amo_func.php');
            }
            throw new Exception(isset($errors[$code]) ? $errors[$code] : 'Undefined error', $code);
        }
        if (function_exists('log_info')) {
            log_info("Токен AmoCRM успешно обновлен", ['code' => $code], 'amo_func.php');
        }
    }
    catch(\Exception $e)
    {
        if (function_exists('log_error')) {
            log_error("Критическая ошибка обновления токена AmoCRM", ['error' => $e->getMessage(), 'code' => $e->getCode()], 'amo_func.php');
        }
        die('Ошибка: ' . $e->getMessage() . PHP_EOL . 'Код ошибки: ' . $e->getCode());
    }
    $response = json_decode($out, true);
    $response['time'] = time();
    file_put_contents(__DIR__.'/tokens.json', json_encode($response));
    $data['access_token'] = $response['access_token'];
}
// auth

function get ($subdomain, $url, $data) {
    $link = 'https://' . $subdomain . '.amocrm.ru'.$url;
    $access_token = $data['access_token'];
    $headers = [
        'Authorization: Bearer ' . $access_token
    ];
    // echo print_r($headers).'<br><br>';
    $curl = curl_init(); //Сохраняем дескриптор сеанса cURL
    curl_setopt($curl,CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl,CURLOPT_USERAGENT,'amoCRM-oAuth-client/1.0');
    curl_setopt($curl,CURLOPT_URL, $link);
    curl_setopt($curl,CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl,CURLOPT_HEADER, false);
    curl_setopt($curl,CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST, 2);
    $out = curl_exec($curl); //Инициируем запрос к API и сохраняем ответ в переменную
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $code = (int)$code;
    $errors = [
        400 => 'Bad request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not found',
        500 => 'Internal server error',
        502 => 'Bad gateway',
        503 => 'Service unavailable',
    ];

    try
    {
        if ($code < 200 || $code > 204) {
            if (function_exists('log_error')) {
                log_error("Ошибка GET запроса к AmoCRM API", ['code' => $code, 'url' => $url, 'error' => isset($errors[$code]) ? $errors[$code] : 'Undefined error', 'response' => substr($out, 0, 500)], 'amo_func.php');
            }
            throw new Exception(isset($errors[$code]) ? $errors[$code] : 'Undefined error', $code);
        }
        if (function_exists('log_debug')) {
            log_debug("GET запрос к AmoCRM API выполнен успешно", ['code' => $code, 'url' => $url], 'amo_func.php');
        }
    }
    catch(\Exception $e)
    {
        if (function_exists('log_error')) {
            log_error("Исключение при GET запросе к AmoCRM API", ['error' => $e->getMessage(), 'code' => $e->getCode(), 'url' => $url], 'amo_func.php');
        }
        die('Ошибка: ' . $e->getMessage() . PHP_EOL . 'Код ошибки: ' . $e->getCode());
    }
    // if ($url == '/api/v4/users') {
    //     echo $out.'<br>';
    // }
    $result = json_decode($out, true);
    return $result;
}

function post_or_patch ($subdomain, $query_data, $url, $data, $method) {
    // echo 'POST:<br>';
    // echo $url.'<br>';
    $link = 'https://' . $subdomain . '.amocrm.ru'.$url;
    $access_token = $data['access_token'];
    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ];
    $curl = curl_init(); //Сохраняем дескриптор сеанса cURL
    curl_setopt($curl,CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl,CURLOPT_USERAGENT,'amoCRM-oAuth-client/1.0');
    curl_setopt($curl,CURLOPT_URL, $link);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($query_data));
    curl_setopt($curl,CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl,CURLOPT_HEADER, false);
    curl_setopt($curl,CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST, 2);
    $out = curl_exec($curl); //Инициируем запрос к API и сохраняем ответ в переменную
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    // echo $code.'<br>';
    // echo $out.'<br>';
    curl_close($curl);
    $code = (int)$code;
    $errors = array(
        301 => 'Moved permanently',
        400 => 'Bad request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not found',
        500 => 'Internal server error',
        502 => 'Bad gateway',
        503 => 'Service unavailable',
    );
    try
    {
        if ($code != 200 && $code != 204) {
            if (function_exists('log_error')) {
                log_error("Ошибка {$method} запроса к AmoCRM API", ['code' => $code, 'method' => $method, 'url' => $url, 'error' => isset($errors[$code]) ? $errors[$code] : 'Undescribed error', 'response' => substr($out, 0, 500)], 'amo_func.php');
            }
            throw new Exception(isset($errors[$code]) ? $errors[$code] : 'Undescribed error', $code);
        }
        if (function_exists('log_debug')) {
            log_debug("{$method} запрос к AmoCRM API выполнен успешно", ['code' => $code, 'method' => $method, 'url' => $url], 'amo_func.php');
        }
    } catch (Exception $E) {
        if (function_exists('log_error')) {
            log_error("Исключение при {$method} запросе к AmoCRM API", ['error' => $E->getMessage(), 'code' => $E->getCode(), 'method' => $method, 'url' => $url], 'amo_func.php');
        }
        die('Ошибка: ' . $E->getMessage() . PHP_EOL . 'Код ошибки: ' . $E->getCode());
    }
    $result = json_decode($out, true);
    return $result;
}


