<?php
// api/pix_webhook.php
declare(strict_types=1);

// 1. Conectar ao banco de dados e funções
require_once '../config/db.php';
require_once '../funcoes.php'; // ⭐ NOVO: Inclui as funções de e-mail

// Carrega as constantes (NOME_DA_LOJA, Mailgun keys, etc.)
// É importante carregar isso *antes* de qualquer lógica de e-mail
if (isset($pdo)) {
    carregarConfigApi($pdo);
}

// Função para log, essencial para depurar webhooks
function log_webhook($message) {
    // Cria um arquivo de log na mesma pasta /api
    error_log("Webhook PixUp: " . $message . "\n", 3, __DIR__ . '/webhook.log');
}

// 2. Capturar e validar o payload vindo da PixUp
$input = file_get_contents('php://input');
log_webhook("Payload recebido: " . $input);

$payload = json_decode($input, true);

// Validação básica do payload da PixUp
if (json_last_error() !== JSON_ERROR_NONE || !isset($payload['requestBody']['status'], $payload['requestBody']['external_id'])) {
    log_webhook("ERRO: Payload inválido ou incompleto.");
    http_response_code(400); // Bad Request
    exit;
}

$requestBody = $payload['requestBody'];
$status = $requestBody['status'];
$pedidoId = (int)$requestBody['external_id']; // Este é o nosso ID do pedido

// 3. Processar apenas se o status for 'PAID' (Pago)
if ($status === 'PAID') {
    log_webhook("Pagamento CONFIRMADO para o pedido ID: {$pedidoId}. Iniciando processamento.");

    try {
        // 4. Iniciar uma transação
        $pdo->beginTransaction();

        // 5. Encontrar o pedido PENDENTE e travar a linha
        $stmt = $pdo->prepare("SELECT id, status FROM pedidos WHERE id = ? AND status = 'PENDENTE' FOR UPDATE");
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido) {
            // Se encontrou um pedido pendente com este ID, continue.

            // 6. Atualizar o status do pedido para APROVADO
            $stmtUpdate = $pdo->prepare("UPDATE pedidos SET status = 'APROVADO' WHERE id = ?");
            $stmtUpdate->execute([$pedidoId]);
            log_webhook("Status do pedido ID {$pedidoId} atualizado para APROVADO.");

            // 7. ⭐ NOVO: LÓGICA DE E-MAIL (SEGURA)
            // Colocamos o envio de e-mail dentro de seu próprio try...catch
            // para garantir que uma falha no e-mail NÃO reverta a transação do pagamento.
            try {
                $email_enviado = enviarEmailPagamentoAprovado($pdo, $pedidoId);

                if ($email_enviado) {
                    log_webhook("E-mail de confirmação (Pedido #{$pedidoId}) enviado com sucesso.");
                } else {
                    // Não é um erro fatal, apenas um erro de log. O pagamento está salvo.
                    log_webhook("ERRO: O e-mail de confirmação para o pedido {$pedidoId} FALHOU, mas o pedido foi salvo.");
                }
            } catch (Exception $email_exception) {
                // Pega exceções da função de e-mail, mas não para o script
                log_webhook("EXCEÇÃO CRÍTICA AO TENTAR ENVIAR E-MAIL (Pedido #{$pedidoId}): " . $email_exception->getMessage());
            }

            // 8. Se tudo deu certo, confirma as alterações no banco
            $pdo->commit();
            log_webhook("Pedido ID {$pedidoId} processado com SUCESSO!");

        } else {
            // Se o pedido não foi encontrado ou já foi processado, apenas registre.
            log_webhook("Pedido ID {$pedidoId} não encontrado como 'PENDENTE'. Provavelmente já foi processado. Nenhuma ação tomada.");
            // Ainda commitamos a transação vazia para liberar o lock
            $pdo->commit();
        }
    } catch (Exception $e) {
        // 9. Se algo der errado (no DB), desfaz todas as alterações
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_webhook("ERRO CRÍTICO ao processar pedido {$pedidoId}: " . $e->getMessage());
        http_response_code(500); // Internal Server Error
        exit;
    }
} else {
    log_webhook("Webhook recebido para o pedido ID {$pedidoId} com status '{$status}'. Nenhuma ação tomada.");
}

// 10. Responda à PixUp com status 200 para confirmar o recebimento.
http_response_code(200);
echo json_encode(['ok' => true, 'message' => 'Webhook recebido.']);
?>