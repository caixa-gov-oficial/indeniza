<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Content-Type: application/json");
if (!isset($_GET['cpf']) || empty($_GET['cpf'])) {
    http_response_code(400);
    echo json_encode(['erro' => 'CPF não fornecido']);
    exit;
}
$cpf = preg_replace('/[^0-9]/', '', $_GET['cpf']);
if (strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(['erro' => 'CPF inválido, deve conter 11 dígitos']);
    exit;
}
$url = "https://api-do-roi.online/?cpf={$cpf}";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json'
]);
$response = curl_exec($ch);
if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao consultar a API externa: '.curl_error($ch)]);
    curl_close($ch);
    exit;
}
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
http_response_code($statusCode);
$data = json_decode($response, true);
if ($data === null) {
    echo json_encode(['erro' => 'Resposta inválida da API externa', 'raw' => $response]);
    exit;
}
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
