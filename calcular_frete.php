<?php
// calcular_frete.php
require_once 'config/db.php'; // Inclui a conexão com o banco

header('Content-Type: application/json'); // Define o tipo de resposta

// Futuro: Receber $endereco_id ou $cep via GET/POST
// $endereco_id = $_GET['endereco_id'] ?? null;
// if (!$endereco_id) { /* Tratar erro */ }

$response = ['status' => 'error', 'options' => [], 'message' => 'Erro desconhecido.'];

try {
    // SIMPLIFICAÇÃO: Busca todas as formas de envio ativas.
    // Em um cenário real, você filtraria/calcularia com base no endereço/CEP/peso.
    $stmt = $pdo->query("SELECT id, nome, custo_base, prazo_estimado_dias FROM formas_envio WHERE ativo = true ORDER BY custo_base ASC");
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'status' => 'success',
        'options' => $options
    ];

} catch (PDOException $e) {
    // Em produção, logar o erro $e->getMessage()
    $response['message'] = 'Erro ao consultar formas de envio.';
}

echo json_encode($response);
exit;