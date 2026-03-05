<?php
// cart_manager.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php'; // Garante $pdo

// Inicializa o carrinho se não existir
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = []; // Armazena [id_produto => quantidade]
}

$action = $_GET['action'] ?? '';
$response = ['status' => 'error', 'message' => 'Ação inválida'];

try {
    // Ação: Adicionar item ao carrinho
    if ($action == 'add' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $produto_id = (int)($data['produto_id'] ?? 0);
        $quantidade = (int)($data['quantidade'] ?? 1);

        if ($produto_id > 0 && $quantidade > 0) {
            if (isset($_SESSION['cart'][$produto_id])) {
                $_SESSION['cart'][$produto_id] += $quantidade;
            } else {
                $_SESSION['cart'][$produto_id] = $quantidade;
            }
            $response = ['status' => 'success', 'message' => 'Produto adicionado!'];
        } else {
            $response['message'] = 'Dados do produto inválidos.';
        }
    }

    // AÇÃO: REMOVER UM ITEM
    if ($action == 'remove' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $produto_id = (int)($data['produto_id'] ?? 0);

        if ($produto_id > 0 && isset($_SESSION['cart'][$produto_id])) {
            unset($_SESSION['cart'][$produto_id]);
            $response = ['status' => 'success', 'message' => 'Item removido.'];
        } else {
            $response['message'] = 'Item não encontrado no carrinho.';
        }
    }

    // AÇÃO: LIMPAR O CARRINHO INTEIRO
    if ($action == 'clear' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $_SESSION['cart'] = [];
        $response = ['status' => 'success', 'message' => 'Sacola esvaziada.'];
    }

    // Ação: Buscar o total de itens (para o header)
    if ($action == 'get_cart_count') {
        $total_itens = 0;
        foreach ($_SESSION['cart'] as $qty) {
            $total_itens += $qty;
        }
        $response = [
            'status' => 'success',
            'item_count' => $total_itens,
            // Correção da lógica de plural
            'item_text' => $total_itens . ' ' . ($total_itens == 1 ? 'Item' : 'Itens')
        ];
    }

    // Ação: Buscar o HTML do corpo do modal da sacola
    if ($action == 'get_cart_html') {
        if (empty($_SESSION['cart'])) {
            // Envia o HTML de "Sacola vazia"
            $html = '<div class="cart-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                        <p>Sacola vazia</p>
                    </div>';
            $response = ['status' => 'success', 'html' => $html];
        } else {
            // Busca os produtos do carrinho no banco
            $ids = array_keys($_SESSION['cart']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, nome, preco, imagem_url FROM produtos WHERE id IN ($placeholders)");
            $stmt->execute(array_values($ids)); // Passa os valores dos IDs
            $produtos_carrinho = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $html = '<div class="cart-items-list">';
            $subtotal = 0;

            // Mapeia para facilitar a busca
            $produtos_por_id = [];
            foreach ($produtos_carrinho as $p) {
                $produtos_por_id[$p['id']] = $p;
            }

            // Garante a ordem em que foram adicionados
            foreach ($_SESSION['cart'] as $produto_id => $qty) {
                if (!isset($produtos_por_id[$produto_id])) continue; // Produto foi removido do DB

                $produto = $produtos_por_id[$produto_id];
                $total_item = $produto['preco'] * $qty;
                $subtotal += $total_item;

                $html .= '<div class="cart-item">
                            <img src="'.htmlspecialchars($produto['imagem_url'] ?? 'uploads/placeholder.png').'" alt="'.htmlspecialchars($produto['nome']).'" class="cart-item-img">
                            <div class="cart-item-info">
                                <p class="cart-item-name">'.htmlspecialchars($produto['nome']).'</p>
                                <p class="cart-item-qty">Qtd: '.$qty.'</p>
                                <p class="cart-item-price">R$ '.number_format($total_item, 2, ',', '.').'</p>
                            </div>
                            <button class="cart-item-remove" data-id="'.$produto['id'].'" title="Remover item">&times;</button>
                          </div>';
            }

            $html .= '</div>';

            // Adiciona o subtotal e o botão de limpar
            $html .= '<div class="cart-summary">
                        <div class="cart-subtotal-display">
                            <strong>Subtotal:</strong>
                            <strong>R$ '.number_format($subtotal, 2, ',', '.').'</strong>
                        </div>
                        <a href="#" id="clear-cart-btn" class="cart-clear-btn">Esvaziar Sacola</a>
                      </div>';

            $response = ['status' => 'success', 'html' => $html];
        }
    }

} catch (PDOException $e) {
    error_log("Cart Manager Error: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'Erro de banco de dados.'];
}

// Retorna a resposta como JSON
header('Content-Type: application/json');
echo json_encode($response);
exit;