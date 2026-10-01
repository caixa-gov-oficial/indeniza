<?php
// Habilita o log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Função para gerar CPF válido
function gerarCPF() {
    $cpf = '';
    for ($i = 0; $i < 9; $i++) {
        $cpf .= rand(0, 9);
    }

    $soma = 0;
    for ($i = 0; $i < 9; $i++) {
        $soma += intval($cpf[$i]) * (10 - $i);
    }
    $resto = $soma % 11;
    $digito1 = ($resto < 2) ? 0 : 11 - $resto;
    $cpf .= $digito1;

    $soma = 0;
    for ($i = 0; $i < 10; $i++) {
        $soma += intval($cpf[$i]) * (11 - $i);
    }
    $resto = $soma % 11;
    $digito2 = ($resto < 2) ? 0 : 11 - $resto;
    $cpf .= $digito2;

    $invalidos = [
        '00000000000', '11111111111', '22222222222', '33333333333', 
        '44444444444', '55555555555', '66666666666', '77777777777', 
        '88888888888', '99999999999'
    ];

    if (in_array($cpf, $invalidos)) {
        return gerarCPF();
    }

    return $cpf;
}

try {
    // Configurações da API
    $apiUrl = 'https://api.lotuspay.me';
    $apiSecret = 'lp_3f0d87d7ea822efc4a634b09d4085ba6223519c16c0215e7b118d66ff2c53957';

    // Conecta ao SQLite (arquivo de banco de dados)
    $dbPath = __DIR__ . '/database.sqlite'; // Caminho para o arquivo SQLite
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verifica se a tabela 'pedidos' existe e cria se necessário
    $db->exec("CREATE TABLE IF NOT EXISTS pedidos (
        transaction_id TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        valor INTEGER NOT NULL,
        nome TEXT,
        email TEXT,
        cpf TEXT,
        utm_params TEXT,
        created_at TEXT,
        updated_at TEXT
    )");

    // Recebe os parâmetros
    $valor = isset($_POST['valor']) ? intval($_POST['valor']) : 9358; // Valor dinâmico ou padrão
    $valor_centavos = $valor;

    if (!$valor || $valor <= 0) {
        throw new Exception('Valor inválido');
    }

    // Dados adicionais do usuário e agendamento
    error_log("[Pagamento] 🔍 POST userData raw: " . ($_POST['userData'] ?? 'não encontrado'));
    $userData = isset($_POST['userData']) ? json_decode($_POST['userData'], true) : null;
    $agendamento = isset($_POST['agendamento']) ? json_decode($_POST['agendamento'], true) : null;

    error_log("[Pagamento] � Dados ddo usuário recebidos: " . json_encode($userData));
    error_log("[Pagamento] 📅 Dados do agendamento recebidos: " . json_encode($agendamento));
    
    // Debug: verificar se userData foi decodificado corretamente
    if ($userData) {
        error_log("[Pagamento] ✅ userData decodificado com sucesso");
        error_log("[Pagamento] 📝 Nome no userData: " . ($userData['nomeCompleto'] ?? 'não encontrado'));
        error_log("[Pagamento] 📝 CPF no userData: " . ($userData['cpf'] ?? 'não encontrado'));
    } else {
        error_log("[Pagamento] ❌ userData não foi decodificado ou está vazio");
    }

    // Gera dados do cliente
    $nomes_masculinos = [
        'João', 'Pedro', 'Lucas', 'Miguel', 'Arthur', 'Gabriel', 'Bernardo', 'Rafael',
        'Gustavo', 'Felipe', 'Daniel', 'Matheus', 'Bruno', 'Thiago', 'Carlos'
    ];

    $nomes_femininos = [
        'Maria', 'Ana', 'Julia', 'Sofia', 'Isabella', 'Helena', 'Valentina', 'Laura',
        'Alice', 'Manuela', 'Beatriz', 'Clara', 'Luiza', 'Mariana', 'Sophia'
    ];

    $sobrenomes = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 
        'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 
        'Almeida', 'Lopes', 'Soares', 'Fernandes', 'Vieira', 'Barbosa'
    ];

    // Parâmetros UTM
    $utmParams = [
        'utm_source' => $_POST['utm_source'] ?? null,
        'utm_medium' => $_POST['utm_medium'] ?? null,
        'utm_campaign' => $_POST['utm_campaign'] ?? null,
        'utm_content' => $_POST['utm_content'] ?? null,
        'utm_term' => $_POST['utm_term'] ?? null,
        'xcod' => $_POST['xcod'] ?? null,
        'sck' => $_POST['sck'] ?? null
    ];

    $utmParams = array_filter($utmParams, function($value) {
        return $value !== null && $value !== '';
    });

    error_log("[Pagamento] 📊 Parâmetros UTM recebidos: " . json_encode($utmParams));

    $utmQuery = http_build_query($utmParams);

    // Gera dados do cliente (usa dados reais se disponíveis)
    $usarDadosReais = false;
    
    if ($userData && is_array($userData)) {
        error_log("[Pagamento] 🔍 Verificando dados do userData...");
        
        if (isset($userData['nomeCompleto']) && !empty(trim($userData['nomeCompleto']))) {
            $nome_cliente = trim($userData['nomeCompleto']);
            $usarDadosReais = true;
            error_log("[Pagamento] ✅ Nome encontrado no userData: {$nome_cliente}");
        }
        
        if (isset($userData['cpf']) && !empty(trim($userData['cpf']))) {
            $cpf = preg_replace('/[^0-9]/', '', $userData['cpf']);
            error_log("[Pagamento] ✅ CPF encontrado no userData: {$cpf}");
        }
        
        if (isset($userData['email']) && !empty(trim($userData['email']))) {
            $email = trim($userData['email']);
            error_log("[Pagamento] ✅ Email encontrado no userData: {$email}");
        } else {
            $email = "clienteteste@gmail.com";
        }
    }
    
    if ($usarDadosReais) {
        // Se não tiver CPF nos dados reais, gera um
        if (!isset($cpf) || empty($cpf)) {
            $cpf = gerarCPF();
            error_log("[Pagamento] ⚠️ CPF não encontrado, gerando aleatório: {$cpf}");
        }
        
        error_log("[Pagamento] ✅ Usando dados reais do usuário - Nome: {$nome_cliente}, CPF: {$cpf}, Email: {$email}");
    } else {
        // Gera dados aleatórios se não tiver dados reais
        error_log("[Pagamento] ❌ userData não contém dados válidos, gerando dados aleatórios");
        
        $genero = rand(0, 1);
        $nome = $genero ? 
            $nomes_masculinos[array_rand($nomes_masculinos)] : 
            $nomes_femininos[array_rand($nomes_femininos)];
        
        $sobrenome1 = $sobrenomes[array_rand($sobrenomes)];
        $sobrenome2 = $sobrenomes[array_rand($sobrenomes)];
        
        $nome_cliente = "$nome $sobrenome1 $sobrenome2";
        $email = "clienteteste@gmail.com";
        $cpf = gerarCPF();
        
        error_log("[Pagamento] 🎲 Usando dados aleatórios - Nome: {$nome_cliente}, CPF: {$cpf}");
    }

    error_log("[Nivopay] 📝 Preparando dados para envio: " . json_encode([
        'valor' => $valor,
        'valor_centavos' => $valor_centavos,
        'nome' => $nome_cliente,
        'email' => "clienteteste@gmail.com",
        'cpf' => $cpf
    ]));

    // Determina o título do item baseado no agendamento
    $titulo_item = "Produto Front";
    if ($agendamento && isset($agendamento['laboratorio'])) {
        $titulo_item = "Agendamento de Exame - " . $agendamento['laboratorio']['nome'];
    } elseif ($userData) {
        $titulo_item = "Produto Front";
    }

    // Webhook URL para receber notificações
    $webhookUrl = "https://" . $_SERVER['HTTP_HOST'] . "/consulta/checkoutup/webhook.php";
    
    $data = [
        "external_id" => uniqid('doacao_'),
        "total_amount" => $valor_centavos / 100, // Convertendo centavos para reais
        "payment_method" => "PIX",
        "webhook_url" => $webhookUrl,
        "items" => [
            [
                "id" => uniqid('item_'),
                "title" => $titulo_item,
                "description" => $titulo_item,
                "price" => $valor_centavos / 100, // Convertendo centavos para reais
                "quantity" => 1,
                "is_physical" => false
            ]
        ],
        "ip" => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        "customer" => [
            "name" => $nome_cliente,
            "email" => "clienteteste@gmail.com",
            "phone" => "11999999999",
            "document_type" => "CPF",
            "document" => $cpf
        ]
    ];

    error_log("[LotusPay] 🌐 URL da requisição: " . $apiUrl . '/api/v1/cashin');
    error_log("[LotusPay] 📦 Dados enviados: " . json_encode($data));

    $ch = curl_init($apiUrl . '/api/v1/cashin');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-secret: ' . $apiSecret,
        'Content-Type: application/json'
    ]);

    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);

    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    error_log("[LotusPay] 🔍 Detalhes da requisição cURL:\n" . $verboseLog);

    if ($curlError) {
        error_log("[LotusPay] ❌ Erro cURL: " . $curlError . " (errno: " . $curlErrno . ")");
        throw new Exception("Erro na requisição: " . $curlError);
    }

    curl_close($ch);

    error_log("[LotusPay] 📊 HTTP Status Code: " . $httpCode);
    error_log("[LotusPay] 📄 Resposta bruta: " . $response);

    // HTTP 200 (OK) e 201 (Created) são códigos de sucesso
    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception("Erro na API: HTTP " . $httpCode . " - " . $response);
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar resposta: " . json_last_error_msg() . " - Resposta: " . $response);
    }

    if (!isset($result['id'])) {
        throw new Exception("ID não encontrado na resposta da API");
    }

    // Salva os dados no SQLite
    $stmt = $db->prepare("INSERT INTO pedidos (transaction_id, status, valor, nome, email, cpf, utm_params, created_at) 
        VALUES (:transaction_id, 'pending', :valor, :nome, :email, :cpf, :utm_params, :created_at)");
    $stmt->execute([
        'transaction_id' => $result['id'],
        'valor' => $valor_centavos,
        'nome' => $nome_cliente,
        'email' => $email,
        'cpf' => $cpf,
        'utm_params' => json_encode($utmParams),
        'created_at' => date('c')
    ]);

    session_start();
    $_SESSION['payment_id'] = $result['id'];

    error_log("[NovaAPI] 💳 Transação criada com sucesso: " . $result['id']);
    error_log("[NovaAPI] 📄 Resposta completa da API: " . $response);
    error_log("[NovaAPI] 🔑 Token gerado: " . $result['id']);

    error_log("[Sistema] 📡 Iniciando comunicação com utmify-pendente.php");

    $utmifyData = [
        'orderId' => $result['id'],
        'platform' => 'MinhaPlataforma',
        'paymentMethod' => 'pix',
        'status' => 'waiting_payment',
        'createdAt' => date('Y-m-d H:i:s'),
        'approvedDate' => null,
        'refundedAt' => null,
        'customer' => [
            'name' => $nome_cliente,
            'email' => $email,
            'phone' => null,
            'document' => $cpf,
            'country' => 'BR',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ],
        'products' => [
            [
                'id' => uniqid('PROD_'),
                'name' => $titulo_item,
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => $valor_centavos,
                'agendamento' => $agendamento
            ]
        ],
        'trackingParameters' => $utmParams,
        'commission' => [
            'totalPriceInCents' => $valor_centavos,
            'gatewayFeeInCents' => isset($result['fee']['fixedAmount']) ? $result['fee']['fixedAmount'] : 0,
            'userCommissionInCents' => $valor_centavos
        ],
        'isTest' => false
    ];

    error_log("[Utmify] 📦 Preparando dados para envio ao utmify-pendente.php: " . json_encode($utmifyData));

    // Envia para utmify-pendente.php
    error_log("[Sistema] 📡 Enviando requisição POST para ../utmify-pendente.php");
    
    $serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
    $utmifyUrl = $serverUrl . "/consulta/checkoutup/utmify-pendente.php";
    error_log("[Sistema] 🔍 URL do utmify-pendente.php: " . $utmifyUrl);
    
    $ch = curl_init($utmifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($utmifyData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $utmifyResponse = curl_exec($ch);
    $utmifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $utmifyError = curl_error($ch);
    $utmifyErrno = curl_errno($ch);
    
    error_log("[Sistema] 🔍 Detalhes da requisição Utmify: " . print_r([
        'url' => $utmifyUrl,
        'status' => $utmifyHttpCode,
        'resposta' => $utmifyResponse,
        'erro' => $utmifyError,
        'errno' => $utmifyErrno
    ], true));
    
    curl_close($ch);

    error_log("[Sistema] ✉️ Resposta do utmify-pendente.php: " . $utmifyResponse);
    error_log("[Sistema] 📊 Status code do utmify-pendente.php: " . $utmifyHttpCode);

    if ($utmifyHttpCode !== 200) {
        error_log("[Sistema] ❌ Erro ao enviar dados para utmify-pendente.php: " . $utmifyResponse);
    } else {
        error_log("[Sistema] ✅ Dados enviados com sucesso para utmify-pendente.php");
    }

    // Preparar resposta mantendo a mesma estrutura para o frontend
    $pixCode = isset($result['pix']['payload']) ? $result['pix']['payload'] : null;
    
    $responseData = [
        'success' => true,
        'token' => $result['id'],
        'pixCode' => $pixCode,
        'qrCodeUrl' => $pixCode ? 
            'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($pixCode) . '&size=300x300&charset-source=UTF-8&charset-target=UTF-8&qzone=1&format=png&ecc=L' : 
            null,
        'valor' => $valor,
        'userData' => $userData,
        'agendamento' => $agendamento,
        'logs' => [
            'utmParams' => $utmParams,
            'transacao' => [
                'valor' => $valor,
                'cliente' => $nome_cliente,
                'email' => $email,
                'cpf' => $cpf,
                'titulo_item' => $titulo_item
            ],
            'utmifyResponse' => [
                'status' => $utmifyHttpCode,
                'resposta' => $utmifyResponse
            ],
            'apiResponse' => $result
        ]
    ];

    error_log("[NovaAPI] 📤 Enviando resposta ao frontend: " . json_encode($responseData));
    echo json_encode($responseData);

} catch (Exception $e) {
    error_log("[NovaAPI] ❌ Erro: " . $e->getMessage());
    error_log("[NovaAPI] 🔍 Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao gerar o PIX: ' . $e->getMessage()
    ]);
}
?>