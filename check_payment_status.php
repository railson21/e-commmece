<?php
// api/check_payment_status.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

// 1. Conexão com o banco e FUNÇÕES
require_once '../config/db.php';
require_once '../funcoes.php'; // <--- Inclui o funcoes.php (necessário para o e-mail)

// CORREÇÃO: Fuso horário para garantir logs corretos
date_default_timezone_set('America/Sao_Paulo');

// 2. Validação de Segurança
if (!isset($_SESSION['user_logged_in']) || !isset($_GET['pedido_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['status' => 'ERRO', 'message' => 'Acesso não autorizado.']);
    exit;
}

$pedido_id = (int)$_GET['pedido_id'];
$user_id = (int)$_SESSION['user_id'];

// 3. BUSCAR CONFIGS DA API (APENAS PARA ZEROONE)
$zeroone_config = [];
try {
    $stmt_config = $pdo->query("SELECT chave, valor FROM config_api WHERE chave LIKE 'zeroone_%'");
    $zeroone_config = $stmt_config->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Erro check_payment_status (config): " . $e->getMessage());
    echo json_encode(['status' => 'ERRO_DB', 'message' => 'Erro interno ao ler config.']);
    exit;
}

$ZEROONE_API_TOKEN = $zeroone_config['zeroone_api_token'] ?? null;
$ZEROONE_API_URL = $zeroone_config['zeroone_api_url'] ?? null;


// 4. LÓGICA PRINCIPAL DE VERIFICAÇÃO
try {

    // Inicia a transação para travar a linha (SELECT ... FOR UPDATE)
    $pdo->beginTransaction();

    // Busca o status do pedido, o provedor e o ID do gateway
    $stmt = $pdo->prepare("
        SELECT status, gateway_provider, gateway_txid
        FROM pedidos
        WHERE id = :pedido_id AND usuario_id = :user_id
        FOR UPDATE
    ");
    $stmt->execute([
        ':pedido_id' => $pedido_id,
        ':user_id' => $user_id
    ]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        $pdo->rollBack();
        http_response_code(404); // Not Found
        echo json_encode(['status' => 'NAO_ENCONTRADO', 'message' => 'Pedido não encontrado.']);
        exit;
    }

    // Se o status local já está APROVADO, apenas retorne.
    if ($pedido['status'] === 'APROVADO' || $pedido['status'] === 'ENTREGUE') {
        $pdo->commit();
        echo json_encode(['status' => 'APROVADO']);
        exit();
    }

    // Se o status for PENDENTE e o gateway for PixUp, apenas retorne PENDENTE.
    // (A PixUp USA WEBHOOK, então não fazemos polling)
    if ($pedido['status'] === 'PENDENTE' && $pedido['gateway_provider'] === 'pixup') {
        $pdo->commit();
        echo json_encode(['status' => 'PENDENTE']);
        exit;
    }

    // Se o status for PENDENTE e o gateway for ZeroOnePay, FAZEMOS O POLLING.
    if ($pedido['status'] === 'PENDENTE' && $pedido['gateway_provider'] === 'zeroone') {

        if (empty($ZEROONE_API_TOKEN) || empty($ZEROONE_API_URL) || empty($pedido['gateway_txid'])) {
            $pdo->rollBack(); // Libera o lock antes de lançar o erro
            throw new Exception("Configuração da ZeroOnePay incompleta ou TXID do pedido não encontrado.");
        }

        // Usa o $gateway_txid (que armazenamos o HASH da ZeroOne) para consultar
        $id_to_use = $pedido['gateway_txid'];
        $api_url = rtrim($ZEROONE_API_URL, '/') . "/public/v1/transactions/{$id_to_use}?api_token={$ZEROONE_API_TOKEN}";

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status !== 200) {
            // Se a API falhar, não faz nada no DB, apenas retorna PENDENTE para tentar de novo
            error_log("Falha ao consultar ZeroOnePay (Pedido $pedido_id). HTTP: {$http_status} | Response: {$response}");
            $pdo->rollBack(); // Libera o lock
            echo json_encode(['status' => 'PENDENTE']);
            exit;
        }

        $responseData = json_decode($response, true);
        $api_status_from_provider = $responseData['payment_status'] ?? 'UNKNOWN';

        // Se a API da ZeroOne retornar 'paid' (APROVADO)
        if (strtolower($api_status_from_provider) === 'paid') {

            // ATUALIZA O STATUS DO PEDIDO no nosso banco
            $stmtUpdate = $pdo->prepare("UPDATE pedidos SET status = 'APROVADO' WHERE id = ? AND status = 'PENDENTE'");
            $stmtUpdate->execute([$pedido_id]);

            // ▼▼▼ INÍCIO DA LÓGICA DE E-MAIL (CORRIGIDA) ▼▼▼

            // 1. Carrega as constantes de e-mail (NOME_DA_LOJA, etc.) do funcoes.php
            // Esta função (de funcoes.php) é necessária para carregar as constantes que o e-mail usa
            if (function_exists('carregarConfigApi')) {
                carregarConfigApi($pdo);
            }

            // 2. Tenta chamar a função de 'funcoes.php' para enviar o e-mail
            if (function_exists('enviarEmailPagamentoAprovado')) {
                try {
                    // Chama a função correta com os parâmetros corretos
                    enviarEmailPagamentoAprovado($pdo, $pedido_id);
                } catch (Exception $email_e) {
                    // Se o email falhar, não reverte a transação. Apenas loga o erro.
                    error_log("Erro crítico ao tentar enviar email (função) para Pedido ID: $pedido_id. Erro: " . $email_e->getMessage());
                }
            } else {
                // Fallback (Log de erro caso a função não exista)
                error_log("ALERTA: A função 'enviarEmailPagamentoAprovado' não foi encontrada em funcoes.php. O Pedido $pedido_id foi aprovado, mas o e-mail não foi enviado.");
            }

            // ▲▲▲ FIM DA LÓGICA DE E-MAIL ▲▲▲

            // Confirma a transação
            $pdo->commit();

            // Retorna o novo status para o frontend
            echo json_encode(['status' => 'APROVADO']);
            exit;

        } else {
            // Se a API retornar 'pending', 'expired', etc.
            // Apenas libera o lock e informa o frontend que ainda está pendente.
            $pdo->rollBack();
            echo json_encode(['status' => 'PENDENTE']);
            exit;
        }
    }

    // Se o status for qualquer outra coisa (CANCELADO, etc.), apenas retorne
    $pdo->commit();
    echo json_encode(['status' => $pedido['status']]);


} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    http_response_code(500); // Internal Server Error
    error_log("Erro check_payment_status: " . $e->getMessage()); // Loga o erro
    echo json_encode(['status' => 'ERRO_DB', 'message' => 'Erro interno do servidor.', 'detail' => $e->getMessage()]);
}
?>