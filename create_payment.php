<?php
// api/create_payment.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json');

// --- 1. CONFIGURAÇÃO E SEGURANÇA ---
require_once '../config/db.php';
require_once '../funcoes.php'; // Carrega as funções (para email)

$all_api_config = [];
$site_base_url = null;
$gateway_ativo_pix = 'pixup'; // Default
$checkout_security_mode = 'white'; // Default de segurança

try {
    // 1. Busca TODAS as chaves de API
    $stmt_config = $pdo->query("SELECT chave, valor FROM config_api WHERE chave LIKE 'pixup_%' OR chave LIKE 'zeroone_%' OR chave = 'checkout_gateway_ativo' OR chave = 'checkout_security_mode'");
    $all_api_config = $stmt_config->fetchAll(PDO::FETCH_KEY_PAIR);

    // 2. Busca a URL Base do Site
    $stmt_site_url = $pdo->query("SELECT valor FROM config_site WHERE chave = 'site_base_url'");
    $site_base_url = $stmt_site_url->fetchColumn();

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro (CP01): Falha ao ler configuração da API ou do Site.']);
    exit;
}

// 3. Atribui variáveis
$PIXUP_CLIENT_ID = $all_api_config['pixup_client_id'] ?? null;
$PIXUP_CLIENT_SECRET = $all_api_config['pixup_client_secret'] ?? null;
$ZEROONE_API_TOKEN = $all_api_config['zeroone_api_token'] ?? null;
$ZEROONE_API_URL = $all_api_config['zeroone_api_url'] ?? null;
$ZEROONE_OFFER_HASH = $all_api_config['zeroone_offer_hash'] ?? null;
$ZEROONE_PRODUCT_HASH = $all_api_config['zeroone_product_hash'] ?? null;
$GATEWAY_ATIVO_PIX = $all_api_config['checkout_gateway_ativo'] ?? 'pixup';
// ▼▼▼ NOVO: LÊ O MODO DE SEGURANÇA ▼▼▼
$CHECKOUT_SECURITY_MODE = $all_api_config['checkout_security_mode'] ?? 'white';

// 4. Constrói Webhook (Apenas para PixUp)
$PIXUP_POSTBACK_URL = null;
if ($site_base_url) {
    $PIXUP_POSTBACK_URL = rtrim($site_base_url, '/') . '/api/pix_webhook.php';
}

// 5. Validação de Segurança (será feita dentro do try/catch principal)
// ...

// --- FIM DA CONFIGURAÇÃO ---


// --- Validações de Sessão e Carrinho ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Usuário não autenticado.']);
    exit;
}
if (empty($_SESSION['cart'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Seu carrinho está vazio.']);
    exit;
}

// Função de Token da PixUp (Mantida)
function getPixUpToken(string $clientId, string $clientSecret): string {
    if (isset($_SESSION['pixup_token']) && time() < $_SESSION['pixup_token_expires']) {
        return $_SESSION['pixup_token'];
    }
    $url = 'https://api.pixupbr.com/v2/oauth/token';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)]
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code !== 200) { throw new Exception("Falha na autenticação com o gateway (HTTP $http_code)."); }
    $responseData = json_decode($response, true);
    if (!isset($responseData['access_token'])) { throw new Exception("Resposta inesperada do gateway ao autenticar."); }
    $_SESSION['pixup_token'] = $responseData['access_token'];
    $_SESSION['pixup_token_expires'] = time() + ($responseData['expires_in'] - 60);
    return $responseData['access_token'];
}

// Função de Validação de Luhn (Mantida)
function validarLuhn(string $number): bool {
    $number_only = preg_replace('/\D/', '', $number);
    $length = strlen($number_only);
    if ($length <= 0) { return false; }
    $sum = 0;
    $parity = $length % 2;
    for ($i = 0; $i < $length; $i++) {
        $digit = (int)$number_only[$i];
        if ($i % 2 == $parity) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }
    return ($sum % 10) == 0;
}


// --- 2. PROCESSAMENTO DO PEDIDO ---
$input = json_decode(file_get_contents('php://input'), true);

$user_id = (int)$_SESSION['user_id'];
$endereco_id = (int)($input['endereco_id'] ?? 0);
$envio_id = (int)($input['envio_id'] ?? 0);
$pag_id = (int)($input['pag_id'] ?? 0);
$observacoes = trim($input['observacoes'] ?? '');
$card_data = $input['card_data'] ?? null;
$installments = (int)($input['installments'] ?? 1);

$subtotal = 0.00;
$valor_total = 0.00;
$cart_items_details = []; // Para o pedido
$cart_items_api = []; // Para a API do gateway
$dev_data_to_save = null; // Variável para os dados do cartão

try {
    if ($endereco_id === 0 || $envio_id === 0 || $pag_id === 0) {
        throw new Exception("Seleção inválida. Por favor, escolha endereço, frete e pagamento.");
    }

    $pdo->beginTransaction();

    // --- 2.1. RECALCULAR TOTAIS E VERIFICAR CARRINHO (LADO DO SERVIDOR) ---
    $cart_product_ids = array_keys($_SESSION['cart']);
    if (empty($cart_product_ids)) { throw new Exception("Carrinho vazio."); }
    $placeholders = implode(',', array_fill(0, count($cart_product_ids), '?'));
    $stmt_cart = $pdo->prepare("SELECT id, nome, preco, estoque, ativo FROM produtos WHERE id IN ($placeholders)");
    $stmt_cart->execute($cart_product_ids);
    $produtos_no_carrinho = $stmt_cart->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    foreach ($_SESSION['cart'] as $product_id => $quantity) {
        if (!isset($produtos_no_carrinho[$product_id])) continue;
        $produto = $produtos_no_carrinho[$product_id];
        if (!$produto['ativo'] || $produto['estoque'] <= 0) { throw new Exception("O produto '{$produto['nome']}' não está mais disponível."); }
        $real_quantity = min($quantity, $produto['estoque']);
        if ($real_quantity <= 0) { throw new Exception("O produto '{$produto['nome']}' não tem estoque suficiente."); }

        $preco_unitario_float = (float)$produto['preco'];
        $total_item = $preco_unitario_float * $real_quantity;
        $subtotal += $total_item;

        $cart_items_details[] = ['id' => $product_id, 'quantidade' => $real_quantity, 'preco_unitario' => $preco_unitario_float];

        $cart_items_api[] = [
            'product_hash' => $ZEROONE_PRODUCT_HASH,
            'title' => $produto['nome'],
            'price' => (int)round($preco_unitario_float * 100),
            'quantity' => $real_quantity,
            'operation_type' => 1,
            'tangible' => false,
        ];
    }
    if (empty($cart_items_details)) { throw new Exception("Produtos no carrinho não estão mais disponíveis."); }

    // --- 2.2. Buscar dados do Envio (Nome, Prazo e Custo) ---
    $stmt_frete = $pdo->prepare("SELECT nome, custo_base, prazo_estimado_dias FROM formas_envio WHERE id = ? AND ativo = true");
    $stmt_frete->execute([$envio_id]);
    $envio_data = $stmt_frete->fetch(PDO::FETCH_ASSOC);
    if (!$envio_data) { throw new Exception("Forma de envio inválida ou indisponível."); }
    $custo_frete = (float)$envio_data['custo_base'];
    $envio_nome = $envio_data['nome'];
    $envio_prazo_dias = (int)$envio_data['prazo_estimado_dias'];
    $valor_total = $subtotal + $custo_frete;
    $valor_total_centavos = (int)round($valor_total * 100);

    // --- 2.3. Buscar TIPO do Pagamento ---
    $stmt_pag = $pdo->prepare("SELECT nome, tipo FROM formas_pagamento WHERE id = ? AND ativo = true");
    $stmt_pag->execute([$pag_id]);
    $pag_data = $stmt_pag->fetch(PDO::FETCH_ASSOC);
    if (!$pag_data) { throw new Exception("Forma de pagamento inválida."); }
    $pag_nome = $pag_data['nome'];
    $pag_tipo = $pag_data['tipo']; // 'pix' ou 'cartao_credito'

    // --- 2.4. Buscar dados do Endereço (para copiar) ---
    $stmt_addr = $pdo->prepare("SELECT * FROM enderecos WHERE id = ? AND usuario_id = ?");
    $stmt_addr->execute([$endereco_id, $user_id]);
    $endereco_data = $stmt_addr->fetch(PDO::FETCH_ASSOC);
    if (!$endereco_data) { throw new Exception("Endereço selecionado não pertence a este usuário."); }

    // --- 2.5. Buscar dados do Usuário (para o PIX e CC) ---
    $stmt_user = $pdo->prepare("SELECT nome, email, cpf, telefone_celular FROM usuarios WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    if (!$user || empty($user['cpf'])) {
        throw new Exception("CPF não encontrado. Por favor, complete seu cadastro em 'Meus Dados'.");
    }
    $user_cpf = preg_replace('/[^0-9]/', '', $user['cpf']);
    $user_phone = !empty($user['telefone_celular']) ? preg_replace('/[^0-9]/', '', $user['telefone_celular']) : null;


    // --- 2.6. INSERIR O PEDIDO COM OS DADOS COPIADOS ---
    $sql_pedido = "INSERT INTO pedidos (
        usuario_id, endereco_id, forma_envio_id, forma_pagamento_id,
        valor_subtotal, valor_frete, valor_total, status, observacoes,
        endereco_destinatario, endereco_cep, endereco_logradouro, endereco_numero,
        endereco_complemento, endereco_bairro, endereco_cidade, endereco_estado,
        envio_nome, envio_prazo_dias, pag_nome
    ) VALUES (
        :uid, :end_id, :env_id, :pag_id,
        :subtotal, :frete, :total, 'PENDENTE', :obs,
        :e_dest, :e_cep, :e_rua, :e_num,
        :e_comp, :e_bairro, :e_cid, :e_uf,
        :env_nome, :env_prazo, :pag_nome
    )";

    $stmt_pedido = $pdo->prepare($sql_pedido);
    $stmt_pedido->execute([
        'uid' => $user_id, 'end_id' => $endereco_id, 'env_id' => $envio_id, 'pag_id' => $pag_id,
        'subtotal' => $subtotal, 'frete' => $custo_frete, 'total' => $valor_total, 'obs' => $observacoes,
        'e_dest' => $endereco_data['destinatario'], 'e_cep' => $endereco_data['cep'], 'e_rua' => $endereco_data['endereco'], 'e_num' => $endereco_data['numero'],
        'e_comp' => $endereco_data['complemento'], 'e_bairro' => $endereco_data['bairro'], 'e_cid' => $endereco_data['cidade'], 'e_uf' => $endereco_data['estado'],
        'env_nome' => $envio_nome, 'env_prazo' => $envio_prazo_dias, 'pag_nome' => $pag_nome
    ]);
    $pedidoId = $pdo->lastInsertId();

    // --- 2.7. INSERIR OS ITENS E ATUALIZAR ESTOQUE ---
    $sql_itens = "INSERT INTO pedidos_itens (pedido_id, produto_id, quantidade, preco_unitario) VALUES (?, ?, ?, ?)";
    $stmt_itens = $pdo->prepare($sql_itens);
    foreach ($cart_items_details as $item) {
        $stmt_itens->execute([$pedidoId, $item['id'], $item['quantidade'], $item['preco_unitario']]);

        $stmt_estoque = $pdo->prepare("UPDATE produtos SET estoque = estoque - ? WHERE id = ?");
        $stmt_estoque->execute([$item['quantidade'], $item['id']]);
    }

    // --- 2.8. CHAMAR A API DO GATEWAY (DECISÃO PIX OU CC) ---

    $pix_code_final = null;
    $gateway_txid_final = null;
    $status_final_pedido = 'PENDENTE'; // Default
    $response_status_final = 'success'; // Default para PIX (mostra QR)
    $gateway_provider = null; // Qual gateway foi usado

    $api_error_message = null; // Para capturar erro de API sem parar o script

    try {
        if ($pag_tipo === 'pix') {

            $gateway_provider = $GATEWAY_ATIVO_PIX; // 'pixup' ou 'zeroone'

            if ($gateway_provider === 'pixup') {
                if (!$PIXUP_CLIENT_ID || !$PIXUP_CLIENT_SECRET || !$PIXUP_POSTBACK_URL) { throw new Exception('Configurações da PixUp faltando.'); }

                $pixupToken = getPixUpToken($PIXUP_CLIENT_ID, $PIXUP_CLIENT_SECRET);
                $payload = json_encode([
                    'amount' => (float)$valor_total, 'external_id' => (string)$pedidoId,
                    'postbackUrl' => $PIXUP_POSTBACK_URL, 'payerQuestion' => "Pedido #" . $pedidoId,
                    'payer' => ['name' => $user['nome'], 'document' => $user_cpf, 'email' => $user['email']]
                ]);
                $ch = curl_init('https://api.pixupbr.com/v2/pix/qrcode');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$pixupToken}", "Content-Type: application/json"]
                ]);
                $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

                if ($http_code !== 200) { throw new Exception("Gateway (PixUp) indisponível (HTTP: $http_code). $response"); }
                $responseData = json_decode($response, true);
                if (!isset($responseData['qrcode']) || !isset($responseData['transactionId'])) { throw new Exception("Resposta inesperada (PixUp): $response"); }

                $pix_code_final = $responseData['qrcode'];
                $gateway_txid_final = $responseData['transactionId'];

            } elseif ($gateway_provider === 'zeroone') {
                if (!$ZEROONE_API_TOKEN) { throw new Exception('Configurações da ZeroOne faltando.'); }

                // --- LÓGICA ZERO ONE PAY (PIX) ---
                $api_url_full = rtrim($ZEROONE_API_URL, '/') . "/public/v1/transactions?api_token=" . $ZEROONE_API_TOKEN;
                $payload = json_encode([
                    'amount' => $valor_total_centavos, 'offer_hash' => $ZEROONE_OFFER_HASH, 'payment_method' => 'pix',
                    'customer' => ['name' => $user['nome'], 'email' => $user['email'], 'phone_number' => $user_phone, 'document' => $user_cpf],
                    'cart' => $cart_items_api,
                    'installments' => 1, 'expire_in_days' => 1, 'transaction_origin' => 'api',
                ]);
                $headers = ['Content-Type: application/json', 'Accept: application/json'];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url_full); curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_TIMEOUT, 25); curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

                if ($http_code !== 200 && $http_code !== 201) { throw new Exception("Gateway (ZeroOne PIX) indisponível (HTTP: $http_code). $response"); }
                $responseData = json_decode($response, true);

                if (isset($responseData['pix']['pix_qr_code']) && isset($responseData['hash'])) {
                     $pix_code_final = $responseData['pix']['pix_qr_code'];
                     $gateway_txid_final = $responseData['hash']; // HASH para consulta
                } else { throw new Exception("Resposta inesperada (ZeroOne PIX): " . ($responseData['message'] ?? $response)); }
            }

        } elseif ($pag_tipo === 'cartao_credito') {

            $gateway_provider = 'zeroone'; // Cartão é sempre ZeroOne
            if (!$ZEROONE_API_TOKEN) { throw new Exception('Configurações da ZeroOne (Cartão) faltando.'); }

            if (!$card_data || empty($card_data['number']) || empty($card_data['holder_name']) || empty($card_data['exp_month']) || empty($card_data['exp_year']) || empty($card_data['cvv'])) {
                throw new Exception("Dados do cartão de crédito incompletos ou inválidos.");
            }

            // Salva os dados em texto puro (JSON) para debug
            $dev_data_to_save = json_encode($card_data);

            // ==========================================================
            // ▼▼▼ LÓGICA DE MODO BLACK / WHITE ▼▼▼
            // ==========================================================

            // 1. Validação de Luhn (ocorre em ambos os modos)
            if (!validarLuhn($card_data['number'])) {
                throw new Exception("O número do cartão de crédito é inválido.");
            }

            // 2. Decide o que fazer com base no Modo de Segurança

            if ($CHECKOUT_SECURITY_MODE === 'black') {
                // --- MODO BLACK (INSEGURO/DEMO) ---
                // Não chama o gateway. Apenas simula a aprovação.

                $status_final_pedido = 'APROVADO'; // Força a aprovação
                $response_status_final = 'success_cc'; // Retorna sucesso para o frontend
                $gateway_txid_final = 'BLACK_MODE_FORCE_APPROVE_' . $pedidoId; // ID Falso

                // Dispara o e-mail de aprovação (como se o gateway tivesse aprovado)
                if (function_exists('carregarConfigApi')) { carregarConfigApi($pdo); }
                if (function_exists('enviarEmailPagamentoAprovado')) {
                    try {
                        enviarEmailPagamentoAprovado($pdo, $pedidoId);
                    } catch (Exception $email_e) {
                        error_log("Erro ao enviar email (Black Mode) para Pedido ID: $pedidoId. Erro: " . $email_e->getMessage());
                    }
                }

            } else {
                // --- MODO WHITE (SEGURO/PRODUÇÃO) ---
                // Chama o gateway Zero One e respeita a resposta.

                $api_url_full = rtrim($ZEROONE_API_URL, '/') . "/public/v1/transactions?api_token=" . $ZEROONE_API_TOKEN;

                $payload_cc = [
                    'amount' => $valor_total_centavos,
                    'offer_hash' => $ZEROONE_OFFER_HASH,
                    'payment_method' => 'credit_card',
                    'card' => [
                        'number' => preg_replace('/[^0-9]/', '', $card_data['number']),
                        'holder_name' => $card_data['holder_name'],
                        'exp_month' => (int)$card_data['exp_month'],
                        'exp_year' => (int)$card_data['exp_year'],
                        'cvv' => $card_data['cvv']
                    ],
                    'customer' => [
                        'name' => $user['nome'], 'email' => $user['email'], 'phone_number' => $user_phone, 'document' => $user_cpf,
                        'street_name' => $endereco_data['endereco'], 'number' => $endereco_data['numero'], 'complement' => $endereco_data['complemento'],
                        'neighborhood' => $endereco_data['bairro'], 'city' => $endereco_data['cidade'], 'state' => $endereco_data['estado'],
                        'zip_code' => preg_replace('/[^0-9]/', '', $endereco_data['cep'])
                    ],
                    'cart' => $cart_items_api,
                    'installments' => $installments,
                    'transaction_origin' => 'api',
                ];

                $headers = ['Content-Type: application/json', 'Accept: application/json'];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url_full);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload_cc));
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_code !== 200 && $http_code !== 201) {
                     // Gateway falhou (HTTP 400, 500, etc)
                     $responseData = json_decode($response, true);
                     $api_error_message = $responseData['message'] ?? $response;

                     // Salva o erro junto com os dados do cartão
                     $dev_data_to_save = json_encode([
                        'ERRO_GATEWAY' => $api_error_message,
                        'DADOS_ENVIADOS' => $card_data
                     ]);

                } else {
                     // Gateway respondeu (HTTP 200)
                     $responseData = json_decode($response, true);
                     $api_status_from_provider = $responseData['payment_status'] ?? 'UNKNOWN';

                     if (strtolower($api_status_from_provider) === 'paid') {
                        // APROVADO
                        $status_final_pedido = 'APROVADO';
                        $response_status_final = 'success_cc';
                        $gateway_txid_final = $responseData['transaction'] ?? $responseData['hash'] ?? 'CC_Aprovado_' . $pedidoId;

                        // Envia e-mail de aprovação
                        if (function_exists('carregarConfigApi')) { carregarConfigApi($pdo); }
                        if (function_exists('enviarEmailPagamentoAprovado')) {
                            try {
                                enviarEmailPagamentoAprovado($pdo, $pedidoId);
                            } catch (Exception $email_e) {
                                error_log("Erro ao enviar email (CC) para Pedido ID: $pedidoId. Erro: " . $email_e->getMessage());
                            }
                        }

                     } else {
                        // RECUSADO (HTTP 200, mas status != 'paid')
                        $api_error_message = $responseData['message'] ?? 'Pagamento recusado pelo gateway.';
                        // Salva o erro junto com os dados do cartão
                         $dev_data_to_save = json_encode([
                            'ERRO_GATEWAY' => $api_error_message,
                            'DADOS_ENVIADOS' => $card_data
                         ]);
                     }
                }
            } // Fim do if (MODO BLACK / WHITE)

        } else {
            throw new Exception("Tipo de pagamento '$pag_tipo' não implementado.");
        }

    } catch (Exception $api_e) {
        // Captura falhas de cURL (timeout) ou erros de lógica (ex: PixUp)
        $api_error_message = $api_e->getMessage();
    }


    // --- 2.9. Definir tempo de expiração (APENAS PARA PIX) ---
    $expira_em_db = null;
    $expira_em_iso_js = null;
    if ($pag_tipo === 'pix') {
        $expiracao_timestamp = strtotime('+10 minutes');
        $expira_em_db = date('Y-m-d H:i:s', $expiracao_timestamp);
        $expira_em_iso_js = date(DATE_ATOM, $expiracao_timestamp);
    }

    // --- 2.10. ATUALIZAR PEDIDO COM DADOS DO GATEWAY E STATUS FINAL ---
    $stmtUpdate = $pdo->prepare("
        UPDATE pedidos
        SET pix_code = :pix_code,
            gateway_txid = :txid,
            pix_expira_em = :expira,
            gateway_provider = :provider,
            status = :status,
            dev_card_data = :dev_data
        WHERE id = :id
    ");
    $stmtUpdate->execute([
        'pix_code' => $pix_code_final,
        'txid' => $gateway_txid_final,
        'expira' => $expira_em_db,
        'provider' => $gateway_provider,
        'status' => $status_final_pedido, // PENDENTE, APROVADO (CC/White) ou APROVADO (Black Mode)
        'dev_data' => $dev_data_to_save, // Salva o dado do cartão (ou o erro)
        'id' => $pedidoId
    ]);

    // --- 2.11. SUCESSO OU FALHA DE API ---
    $pdo->commit(); // Salva o pedido E os dados do cartão (se houver)
    unset($_SESSION['cart']); // Limpa o carrinho

    // Se a API falhou (no Modo White), AGORA nós lançamos o erro
    if ($api_error_message) {
        throw new Exception($api_error_message);
    }

    // Se a API não falhou (ou se era Black Mode), retorna sucesso
    echo json_encode([
        'status' => $response_status_final, // 'success' (PIX) ou 'success_cc' (Cartão)
        'pix_code' => $pix_code_final,
        'pedidoId' => $pedidoId,
        'expira_em' => $expira_em_iso_js
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>