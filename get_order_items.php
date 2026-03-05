<?php
// api/get_order_items.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

// 1. Segurança: Requer login
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Acesso não autorizado.']);
    exit;
}

require_once '../config/db.php';
$user_id = (int)$_SESSION['user_id'];
$pedido_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$pedido_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ID do pedido inválido.']);
    exit;
}

try {
    // 2. Segurança: Verifica se o usuário é dono do pedido
    $stmt_owner = $pdo->prepare("SELECT usuario_id FROM pedidos WHERE id = :pedido_id");
    $stmt_owner->execute(['pedido_id' => $pedido_id]);
    $owner_id = $stmt_owner->fetchColumn();

    if ($owner_id !== $user_id) {
        http_response_code(403); // Proibido
        echo json_encode(['status' => 'error', 'message' => 'Acesso negado a este pedido.']);
        exit;
    }

    // 3. Busca os itens do pedido
    $stmt_itens = $pdo->prepare("
        SELECT pi.quantidade, pr.nome AS produto_nome
        FROM pedidos_itens pi
        JOIN produtos pr ON pi.produto_id = pr.id
        WHERE pi.pedido_id = :pedido_id
    ");
    $stmt_itens->execute(['pedido_id' => $pedido_id]);
    $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'items' => $itens]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Erro em get_order_items.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erro interno do servidor.']);
}
?>