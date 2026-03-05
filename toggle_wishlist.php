<?php
// api/toggle_wishlist.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Você precisa estar logado.', 'action' => 'login']);
    exit;
}

require_once '../config/db.php';

$user_id = (int)$_SESSION['user_id'];
$produto_id = filter_input(INPUT_POST, 'produto_id', FILTER_VALIDATE_INT);

if (!$produto_id) {
    echo json_encode(['status' => 'error', 'message' => 'ID do produto inválido.']);
    exit;
}

try {
    // 1. Verificar se já está na lista
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM lista_desejos WHERE usuario_id = ? AND produto_id = ?");
    $stmt_check->execute([$user_id, $produto_id]);
    $exists = $stmt_check->fetchColumn();

    if ($exists > 0) {
        // 2. Se existe, REMOVE
        $stmt_delete = $pdo->prepare("DELETE FROM lista_desejos WHERE usuario_id = ? AND produto_id = ?");
        $stmt_delete->execute([$user_id, $produto_id]);
        echo json_encode(['status' => 'success', 'message' => 'Removido da Lista de Desejos.', 'action' => 'removed']);
    } else {
        // 3. Se não existe, ADICIONA
        $stmt_insert = $pdo->prepare("INSERT INTO lista_desejos (usuario_id, produto_id) VALUES (?, ?)");
        $stmt_insert->execute([$user_id, $produto_id]);
        echo json_encode(['status' => 'success', 'message' => 'Adicionado à Lista de Desejos!', 'action' => 'added']);
    }

} catch (PDOException $e) {
    error_log("Erro no wishlist: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erro interno do servidor.']);
}
?>