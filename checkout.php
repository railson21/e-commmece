<?php
// checkout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // DEVE ser a primeira linha
}

// --- 1. PROTEÇÃO DA PÁGINA ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    $_SESSION['redirect_url'] = basename($_SERVER['REQUEST_URI']);
    header('Location: login.php');
    exit;
}

require_once 'config/db.php'; // Garante $pdo para includes

// **************************************************
// CORREÇÃO CRÍTICA DE FUSO HORÁRIO
// **************************************************
date_default_timezone_set('America/Sao_Paulo');


$user_id = $_SESSION['user_id'];
$user_data = null;
$enderecos = [];
$formas_pagamento = [];
$cart_items_details = [];
$subtotal = 0.00;
$frete_valor = 0.00;
$total_valor = 0.00;
$errors = [];
$page_alert_message = '';
$is_reopening_pix = false;
$pix_data_to_js = null;

try {
    // --- 2. VERIFICA SE ESTÁ REABRINDO UM PIX ---
    if (isset($_GET['reopen_pix']) && !empty($_GET['reopen_pix'])) {
        $is_reopening_pix = true;
        $reopen_pedido_id = (int)$_GET['reopen_pix'];

        $stmt_reopen = $pdo->prepare("
            SELECT
                p.id, p.valor_total, p.valor_subtotal, p.valor_frete,
                p.status, fp.tipo AS pag_tipo,
                p.pix_code AS pix_copia_e_cola,
                p.pix_expira_em,
                p.criado_em
            FROM pedidos p
            JOIN formas_pagamento fp ON p.forma_pagamento_id = fp.id
            WHERE p.id = :pedido_id AND p.usuario_id = :user_id
        ");
        $stmt_reopen->execute(['pedido_id' => $reopen_pedido_id, 'user_id' => $user_id]);
        $pedido_data = $stmt_reopen->fetch(PDO::FETCH_ASSOC);

        if (!$pedido_data) {
            throw new Exception("Pedido não encontrado ou não pertence a você.");
        }
        if ($pedido_data['pag_tipo'] !== 'pix') {
            throw new Exception("Este pedido não é um pagamento PIX.");
        }

        // --- REGRA CRÍTICA: CANCELAMENTO POR EXPIRAÇÃO ---
        $expira_em_timestamp = 0;
        if (!empty($pedido_data['pix_expira_em'])) {
            $expira_em_timestamp = strtotime($pedido_data['pix_expira_em']);
        } else {
            $expira_em_timestamp = strtotime($pedido_data['criado_em'] . ' +600 seconds'); // 10 min fallback
        }

        $agora_timestamp = time(); // Fuso de SP (definido no topo)

        if ($pedido_data['status'] === 'PENDENTE' && $expira_em_timestamp < $agora_timestamp) {
              $stmt_cancel = $pdo->prepare("UPDATE pedidos SET status = 'CANCELADO' WHERE id = :id AND status = 'PENDENTE'");
              $stmt_cancel->execute(['id' => $reopen_pedido_id]);
              throw new Exception("Este PIX expirou e foi cancelado automaticamente. Por favor, inicie um novo pedido.");
        }

        if ($pedido_data['status'] !== 'PENDENTE') {
              throw new Exception("O status deste pedido é: {$pedido_data['status']}. Não é possível reabrir o PIX.");
        }
        if (empty($pedido_data['pix_copia_e_cola'])) {
            throw new Exception("Dados PIX não encontrados para este pedido.");
        }

        // CORREÇÃO TIMER: Envia a data no formato ISO 8601 COMPLETO (com fuso)
        $expira_em_iso = date(DATE_ATOM, $expira_em_timestamp);

        $pix_data_to_js = json_encode([
            'pedidoId' => $pedido_data['id'],
            'pix_code' => $pedido_data['pix_copia_e_cola'],
            'expira_em' => $expira_em_iso
        ]);

        $subtotal = $pedido_data['valor_subtotal'];
        $frete_valor = $pedido_data['valor_frete'];
        $total_valor = $pedido_data['valor_total'];

        $stmt_itens = $pdo->prepare("
            SELECT pi.quantidade, pi.preco_unitario, pr.nome, pr.imagem_url
            FROM pedidos_itens pi
            JOIN produtos pr ON pi.produto_id = pr.id
            WHERE pi.pedido_id = :pedido_id
        ");
        $stmt_itens->execute(['pedido_id' => $reopen_pedido_id]);
        $itens_do_pedido = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens_do_pedido as $item) {
            $cart_items_details[] = [
                'id' => 0,
                'nome' => $item['nome'],
                'imagem_url' => $item['imagem_url'],
                'quantidade' => $item['quantidade'],
                'preco_unitario' => $item['preco_unitario'],
                'preco_total' => $item['preco_unitario'] * $item['quantidade']
            ];
        }
        unset($_SESSION['cart']);

    } else {
        // --- 3. LÓGICA NORMAL DE CHECKOUT (CARRINHO ATIVO) ---

        $stmt_user = $pdo->prepare("SELECT nome, email, cpf, telefone_celular FROM usuarios WHERE id = :id");
        $stmt_user->execute(['id' => $user_id]);
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if (!$user_data) { session_unset(); session_destroy(); header('Location: login.php'); exit; }

        $stmt_addr = $pdo->prepare("SELECT * FROM enderecos WHERE usuario_id = :id ORDER BY is_principal DESC, id DESC");
        $stmt_addr->execute(['id' => $user_id]);
        $enderecos = $stmt_addr->fetchAll(PDO::FETCH_ASSOC);

        // Busca formas de pagamento (Pix e Cartão)
        $stmt_pag = $pdo->query("SELECT * FROM formas_pagamento WHERE ativo = true AND (tipo = 'pix' OR tipo = 'cartao_credito') ORDER BY nome ASC");
        $formas_pagamento = $stmt_pag->fetchAll(PDO::FETCH_ASSOC);

        $cart_product_ids = array_keys($_SESSION['cart'] ?? []);
        if (!empty($cart_product_ids)) {
            $placeholders = implode(',', array_fill(0, count($cart_product_ids), '?'));
            $stmt_cart = $pdo->prepare("SELECT id, nome, preco, imagem_url, estoque, ativo FROM produtos WHERE id IN ($placeholders)");
            $stmt_cart->execute($cart_product_ids);
            $produtos_no_carrinho = $stmt_cart->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

            foreach ($_SESSION['cart'] as $product_id => $quantity) {
                if (isset($produtos_no_carrinho[$product_id]) && $produtos_no_carrinho[$product_id]['ativo']) {
                    $produto = $produtos_no_carrinho[$product_id];
                    $real_quantity = min($quantity, $produto['estoque']);
                    if ($real_quantity > 0) {
                        $subtotal += $produto['preco'] * $real_quantity;
                        $cart_items_details[] = ['id' => $product_id, 'nome' => $produto['nome'], 'imagem_url' => $produto['imagem_url'], 'quantidade' => $real_quantity, 'preco_unitario' => $produto['preco'], 'preco_total' => $produto['preco'] * $real_quantity];
                        $_SESSION['cart'][$product_id] = $real_quantity;
                    } else { unset($_SESSION['cart'][$product_id]); }
                } else { unset($_SESSION['cart'][$product_id]); }
            }
        }
        $total_valor = $subtotal;
    }

} catch (PDOException $e) {
    $errors['db'] = "Erro ao carregar dados do checkout: " . $e->getMessage();
    $page_alert_message = $e->getMessage();
} catch (Exception $e) {
    $errors['db'] = $e->getMessage();
    $page_alert_message = $e->getMessage();
}

/** Funções helper */
function formatCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return $cpf;
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}
function formatTelefone($tel) {
    $tel = preg_replace('/[^0-9]/', '', $tel);
    if (strlen($tel) == 11) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7, 4);
    } elseif (strlen($tel) == 10) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 4) . '-' . substr($tel, 6, 4);
    }
    return $tel;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finalizar Pedido - <?php echo defined('NOME_DA_LOJA') ? NOME_DA_LOJA : 'Sua Loja'; ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">
    <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>

    <style>
        /* ==========================================================
            CSS ESPECÍFICO DESTA PÁGINA (Layout Checkout e Acordeão)
            ========================================================== */

        body { background-color: #f9f9f9; }

        .checkout-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            align-items: flex-start;
        }
        .checkout-steps {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .general-error { color: var(--error-color); font-weight: bold; text-align: center; margin-bottom: 15px; }

        .step-box {
            background-color: #fff;
            border: 1px solid var(--border-color-medium);
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .step-header {
            display: flex;
            align-items: center;
            padding: 20px;
            cursor: pointer;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--border-color-medium);
            color: var(--text-color-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1em;
            margin-right: 15px;
            transition: all 0.3s ease;
        }
        .step-title {
            font-size: 1.2em;
            font-weight: bold;
            color: var(--text-color-medium);
            transition: all 0.3s ease;
        }
        .step-header .step-chevron {
            margin-left: auto;
            width: 20px;
            height: 20px;
            color: var(--text-color-light);
            transition: transform 0.3s ease;
        }
        .step-header .step-chevron.open {
            transform: rotate(90deg);
        }

        .step-content {
            padding: 0 20px 20px 65px;
            display: none;
            animation: fadeIn 0.5s ease;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        .step-content p { margin: 0 0 10px 0; font-size: 0.9em; color: var(--text-color-dark); line-height: 1.5; }
        .step-content p strong { color: var(--text-color-dark); }
        .step-content .data-icon { width: 16px; height: 16px; stroke-width: 2.5; color: var(--success-color); vertical-align: middle; margin-left: 5px; }
        .step-buttons { margin-top: 20px; display: flex; gap: 15px; }
        .btn-full-width { width: 100%; padding: 12px; font-size: 1em; font-weight: bold; border-radius: 4px; margin-top: 10px; }

        .step-box.step-complete .step-header { cursor: pointer; }
        .step-box.step-complete .step-number { background-color: var(--success-color); color: #fff; }
        .step-box.step-complete .step-title { color: var(--text-color-dark); }
        .step-box.step-complete .step-chevron { color: var(--text-color-dark); }

        .step-box.step-active { border-color: var(--green-accent); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .step-box.step-active .step-header { cursor: pointer; }
        .step-box.step-active .step-number { background-color: var(--green-accent); color: #fff; }
        .step-box.step-active .step-title { color: var(--text-color-dark); }
        .step-box.step-active .step-content { display: block; }
        .step-box.step-active .step-chevron { color: var(--text-color-dark); transform: rotate(90deg); }

        .step-box.step-disabled { background-color: #fdfdfd; opacity: 0.7; }
        .step-box.step-disabled .step-header { cursor: not-allowed; }
        .step-box.step-disabled .step-title { color: var(--text-color-light); }
        .step-box.step-disabled .step-content { display: none; }
        .step-box.step-disabled .step-chevron { display: none; }

        .address-list { display: flex; flex-direction: column; gap: 15px; }
        .address-item { border: 1px solid var(--border-color-medium); border-radius: 4px; padding: 15px; display: flex; align-items: flex-start; gap: 10px; }
        .address-item input[type="radio"] { margin-top: 3px; accent-color: var(--green-accent); cursor: pointer; }
        .address-item label { font-size: 0.9em; line-height: 1.5; color: var(--text-color-medium); cursor: pointer; width: 100%; }
        .address-item strong { display: inline-flex; align-items: center; gap: 8px; color: var(--text-color-dark); font-size: 1.05em; }
        .address-item strong svg { width: 18px; height: 18px; color: var(--green-accent); }

        .checkout-summary { background-color: #fff; border: 1px solid var(--border-color-medium); border-radius: 5px; padding: 25px; position: sticky; top: 150px; }
        .checkout-summary h2 { font-size: 1.3em; font-weight: bold; color: var(--text-color-dark); margin-top: 0; margin-bottom: 20px; }
        .summary-item-list { max-height: 300px; overflow-y: auto; border-top: 1px solid var(--border-color-light); border-bottom: 1px solid var(--border-color-light); }
        .summary-item { display: flex; gap: 15px; padding: 15px 0; border-bottom: 1px solid var(--border-color-light); }
        .summary-item:last-child { border-bottom: none; }
        .summary-item-img { width: 60px; height: 60px; border: 1px solid var(--border-color-light); border-radius: 4px; object-fit: contain; }
        .summary-item-info { flex-grow: 1; }
        .summary-item-info .name { font-size: 0.9em; font-weight: bold; color: var(--text-color-dark); margin-bottom: 5px; display: block; }
        .summary-item-info .qty { font-size: 0.85em; color: var(--text-color-light); }
        .summary-item-price { font-size: 0.95em; font-weight: bold; color: var(--text-color-dark); }
        .summary-obs { margin-top: 20px; }
        .summary-obs label { font-size: 0.9em; font-weight: bold; color: var(--text-color-dark); display: block; margin-bottom: 8px; }
        .summary-obs textarea { width: 100%; height: 80px; border: 1px solid var(--border-color-medium); border-radius: 4px; padding: 10px; font-family: Arial, sans-serif; font-size: 0.9em; resize: vertical; }
        .summary-totals { margin-top: 20px; border-top: 1px solid var(--border-color-light); padding-top: 20px; }
        .summary-totals .total-row { display: flex; justify-content: space-between; font-size: 0.95em; color: var(--text-color-medium); margin-bottom: 10px; }
        .summary-totals .total-row.grand-total { font-size: 1.2em; font-weight: bold; color: var(--text-color-dark); margin-top: 15px; }
        .btn-finalizar { width: 100%; padding: 15px; font-size: 1.1em; font-weight: bold; border-radius: 4px; margin-top: 20px; }
        .btn-finalizar:disabled { background-color: var(--border-color-medium); border-color: var(--border-color-medium); cursor: not-allowed; }
        .btn-finalizar .spinner { width: 18px; height: 18px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 1s linear infinite; display: none; margin: 0 auto; }
        @keyframes spin { to { transform: rotate(360deg); } }

        #pix-payment-display { display: none; grid-template-columns: 2fr 1fr; gap: 30px; align-items: flex-start; }
        #pix-payment-box { background-color: #fff; border: 1px solid var(--border-color-medium); border-radius: 5px; padding: 30px; text-align: center; }
        #pix-payment-box h2 { font-size: 1.5em; color: var(--text-color-dark); margin-top: 0; margin-bottom: 20px; }
        #qr-code-container { width: 250px; height: 250px; margin: 0 auto 20px auto; background-color: white; padding: 15px; border-radius: 8px; border: 1px solid var(--border-color-medium); }
        #pix-code-input { width: 100%; padding: 10px; font-size: 0.9em; border: 1px solid var(--border-color-medium); border-radius: 4px; text-align: center; background-color: #f9f9f9; color: var(--text-color-medium); }
        #copy-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background-color: var(--text-color-dark); color: #fff; border-radius: 4px; margin-top: 15px; font-size: 0.9em; font-weight: bold; cursor: pointer; }
        #copy-btn svg { width: 16px; height: 16px; }
        .pix-timer { margin-top: 20px; font-size: 1em; color: var(--text-color-medium); }
        .pix-timer span { font-weight: bold; color: var(--error-color); }
        #success-popup-overlay { z-index: 2000; }
        #success-popup-overlay .modal-content { text-align: center; }

        .checkout-empty-cart { text-align: center; padding: 60px 20px; background-color: #fff; border: 1px solid var(--border-color-light); border-radius: 5px; margin: 20px 0; }
        .checkout-empty-cart h2 { font-size: 1.5em; color: var(--text-color-dark); margin-bottom: 15px; }
        .checkout-empty-cart p { font-size: 1em; color: var(--text-color-medium); margin-bottom: 25px; }
        .checkout-empty-cart .btn-primary { border-radius: 25px; padding: 12px 30px; }

        .loading-spinner-container { display: flex; justify-content: center; align-items: center; gap: 10px; padding: 20px; color: var(--text-color-medium); font-size: 0.9em; }
        .loading-spinner { display: inline-block; border: 3px solid rgba(0,0,0,0.2); border-radius: 50%; border-top-color: var(--primary-color); width: 20px; height: 20px; animation: spin 1s ease-in-out infinite; }
        .address-item label .envio-details-text { display: block; margin-top: 5px; }
        .address-item label .envio-prazo { font-size: 0.9em; color: var(--success-color); font-weight: 600; }


        /* ==========================================================
           NOVO CSS: Formulário de Cartão de Crédito
           ========================================================== */
        #credit-card-form {
            display: none; /* Escondido por padrão */
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color-light);
        }
        .form-group-cc {
            margin-bottom: 15px;
        }
        .form-group-cc label {
            display: block;
            font-size: 0.9em;
            font-weight: 600;
            color: var(--text-color-dark);
            margin-bottom: 5px;
        }
        .form-group-cc input,
        .form-group-cc select {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color-medium);
            border-radius: 4px;
            font-size: 1em;
        }
        .form-row-cc {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
        }
        .form-row-cc-installments {
             display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-top: 15px;
        }
        /* ========================================================== */

        @media (max-width: 992px) {
            .checkout-layout, #pix-payment-display { grid-template-columns: 1fr; }
            .checkout-summary { position: static; top: auto; margin-top: 20px; }
        }
        @media (max-width: 768px) {
            .step-header { padding: 15px; }
            .step-content { padding: 0 15px 15px 15px; }
            .step-buttons { flex-direction: column; }
            .form-row-cc { grid-template-columns: 1fr; } /* Empilha campos de validade/cvv */
        }
    </style>
</head>
<body class="checkout-page">

    <div class="sticky-header-wrapper">
        <?php include 'templates/header.php'; ?>
    </div>

    <main class="main-content container">

        <?php if (isset($errors['db'])): ?>
            <div class="checkout-empty-cart">
                <h2>Ops! Um erro ocorreu.</h2>
                <p class="general-error"><?php echo htmlspecialchars($page_alert_message); ?></p>
                <a href="<?php echo $is_reopening_pix ? 'pedidos.php' : 'index.php'; ?>" class="btn btn-primary">
                    <?php echo $is_reopening_pix ? 'Voltar para Meus Pedidos' : 'Voltar para a loja'; ?>
                </a>
            </div>

        <?php elseif (empty($cart_items_details) && !$is_reopening_pix): ?>

            <div class="checkout-empty-cart">
                <h2>Seu carrinho está vazio</h2>
                <p>Parece que você ainda não adicionou produtos à sua sacola.</p>
                <a href="index.php" class="btn btn-primary">Voltar para a loja</a>
            </div>

        <?php else: ?>

            <div class="checkout-layout" id="checkout-main-layout" style="<?php echo $is_reopening_pix ? 'display: none;' : 'grid;'; ?>">

                <div class="checkout-steps">

                    <div class="step-box step-active" id="step-box-1">
                        <div class="step-header" data-step="1">
                            <span class="step-number">1</span>
                            <span class="step-title">Informações pessoais</span>
                            <svg class="step-chevron open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" /></svg>
                        </div>
                        <div class="step-content" id="step-content-1">
                            <?php if ($user_data): ?>
                            <p>
                                <strong>Olá, <?php echo htmlspecialchars(explode(' ', $user_data['nome'])[0]); ?>!</strong>
                                <svg class="data-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            </p>
                            <p>
                                <?php echo htmlspecialchars($user_data['email']); ?><br>
                                <?php echo formatCPF($user_data['cpf']); ?><br>
                                <?php echo formatTelefone($user_data['telefone_celular']); ?>
                            </p>
                            <div class="step-buttons">
                                <a href="meus-dados.php?redirect=checkout.php" class="btn btn-secondary">Alterar Dados</a>
                                <button type="button" id="btn-step-1" class="btn btn-primary">Confirmar e Continuar</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="step-box step-disabled" id="step-box-2">
                        <div class="step-header" data-step="2">
                            <span class="step-number">2</span>
                            <span class="step-title">Endereço de entrega</span>
                            <svg class="step-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" /></svg>
                        </div>
                        <div class="step-content" id="step-content-2">
                            <form id="form-step-2">
                                <?php if (empty($enderecos)): ?>
                                    <p>Nenhum endereço cadastrado. Por favor, cadastre um endereço para continuar.</p>
                                <?php else: ?>
                                    <div class="address-list">
                                        <?php foreach ($enderecos as $endereco): ?>
                                        <div class="address-item">
                                            <input type="radio" name="endereco_id"
                                                   id="addr_<?php echo $endereco['id']; ?>"
                                                   value="<?php echo $endereco['id']; ?>"
                                                   data-cep="<?php echo htmlspecialchars($endereco['cep']); ?>"
                                                   <?php echo $endereco['is_principal'] ? 'checked' : ''; ?>>
                                            <label for="addr_<?php echo $endereco['id']; ?>">
                                                <strong>
                                                    <?php if ($endereco['is_principal']) echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.868 2.884c.321-.772 1.305-.772 1.626 0l1.83 4.401 4.753.39a.75.75 0 0 1 .416 1.299l-3.52 3.118.86 4.664c.125.72-.673 1.255-1.33.91l-4.143-2.42-4.143 2.42c-.657.345-1.455-.19-1.33-.91l.86-4.664-3.52-3.118a.75.75 0 0 1 .416-1.3l4.753-.39 1.83-4.401Z" clip-rule="evenodd" /></svg>'; ?>
                                                    <?php echo htmlspecialchars($endereco['destinatario']); ?> (<?php echo htmlspecialchars($endereco['nome_endereco']); ?>)
                                                </strong>
                                                <br>
                                                <span class="address-details">
                                                    <?php echo htmlspecialchars($endereco['endereco']); ?>, <?php echo htmlspecialchars($endereco['numero']); ?>
                                                    <?php echo $endereco['complemento'] ? ' - ' . htmlspecialchars($endereco['complemento']) : ''; ?><br>
                                                    <?php echo htmlspecialchars($endereco['bairro']); ?> - <?php echo htmlspecialchars($endereco['cidade']); ?>/<?php echo htmlspecialchars($endereco['estado']); ?><br>
                                                    CEP: <?php echo htmlspecialchars($endereco['cep']); ?>
                                                </span>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                        </div>
                                <?php endif; ?>

                                <a href="enderecos.php?redirect=checkout.php" class="btn btn-secondary btn-full-width" style="margin-top: 20px;">
                                    <?php echo empty($enderecos) ? 'Cadastrar endereço' : 'Gerenciar / Cadastrar outro'; ?>
                                </a>

                                <button type="button" id="btn-step-2" class="btn btn-primary btn-full-width" <?php echo empty($enderecos) ? 'disabled' : ''; ?>>
                                    Calcular Frete e Continuar
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="step-box step-disabled" id="step-box-3">
                        <div class="step-header" data-step="3">
                            <span class="step-number">3</span>
                            <span class="step-title">Formas de envio</span>
                            <svg class="step-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" /></svg>
                        </div>
                        <div class="step-content" id="step-content-3">

                            <div id="envio-loading" class="loading-spinner-container" style="display: none;">
                                <div class="loading-spinner"></div>
                                <span>Calculando frete para o CEP...</span>
                            </div>

                            <p id="envio-error-message" class="general-error" style="display: none;"></p>

                            <form id="form-step-3" class="address-list" style="display:block;">
                                <div id="envio-options-list">
                                    <p style="color: var(--text-color-light); font-style: italic;">Selecione um endereço para ver as opções.</p>
                                </div>

                                <button type="button" id="btn-step-3" class="btn btn-primary btn-full-width" disabled>
                                    Continuar para Pagamento
                                </button>
                            </form>
                            </div>
                    </div>

                    <div class="step-box step-disabled" id="step-box-4">
                        <div class="step-header" data-step="4">
                            <span class="step-number">4</span>
                            <span class="step-title">Forma de pagamento</span>
                            <svg class="step-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" /></svg>
                        </div>
                        <div class="step-content" id="step-content-4">
                            <form id="form-step-4" class="address-list">
                                <?php if (empty($formas_pagamento)): ?>
                                    <p>Nenhuma forma de pagamento disponível no momento.</p>
                                <?php else: ?>
                                    <?php foreach ($formas_pagamento as $pag): ?>
                                    <div class="address-item">
                                        <input type="radio" name="pag_id" id="pag_<?php echo $pag['id']; ?>" value="<?php echo $pag['id']; ?>" data-tipo="<?php echo $pag['tipo']; ?>">
                                        <label for="pag_<?php echo $pag['id']; ?>">
                                            <strong>
                                            <?php if ($pag['tipo'] == 'pix'): ?>
                                                <svg fill="#05e6e2" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" stroke="#05e6e2" stroke-width="0.00016" style="width: 20px; height: 20px;"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"><path d="M11.917 11.71a2.046 2.046 0 0 1-1.454-.602l-2.1-2.1a.4.4 0 0 0-.551 0l-2.108 2.108a2.044 2.044 0 0 1-1.454.602h-.414l2.66 2.66c.83.83 2.177.83 3.007 0l2.667-2.668h-.253zM4.25 4.282c.55 0 1.066.214 1.454.602l2.108 2.108a.39.39 0 0 0 .552 0l2.1-2.1a2.044 2.044 0 0 1 1.453-.602h.253L9.503 1.623a2.127 2.127 0 0 0-3.007 0l-2.66 2.66h.414z"></path><path d="m14.377 6.496-1.612-1.612a.307.307 0 0 1-.114.023h-.733c-.379 0-.75.154-1.017.422l-2.1 2.1a1.005 1.005 0 0 1-1.425 0L5.268 5.32a1.448 1.448 0 0 0-1.018-.422h-.9a.306.306 0 0 1-.109-.021L1.623 6.496c-.83.83-.83 2.177 0 3.008l1.618 1.618a.305.305 0 0 1 .108-.022h.901c.38 0 .75-.153 1.018-.421L7.375 8.57a1.034 1.034 0 0 1 1.426 0l2.1 2.1c.267.268.638.421 1.017.421h.733c.04 0 .079.01.114.024l1.612-1.612c.83-.83.83-2.178 0-3.008z"></path></g></svg>
                                            <?php elseif ($pag['tipo'] == 'cartao_credito'): ?>
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                                                </svg>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($pag['nome']); ?>
                                            </strong>
                                            <?php if (!empty($pag['instrucoes'])): ?>
                                                <small style="display: block;"><?php echo htmlspecialchars($pag['instrucoes']); ?></small>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <div id="credit-card-form">
                                    <div class="form-group-cc">
                                        <label for="cc-number">Número do Cartão</label>
                                        <input type="text" id="cc-number" placeholder="0000 0000 0000 0000" maxlength="19" autocomplete="cc-number">
                                    </div>
                                    <div class="form-group-cc">
                                        <label for="cc-name">Nome (como está no cartão)</label>
                                        <input type="text" id="cc-name" placeholder="JOAO C SILVA" autocomplete="cc-name">
                                    </div>
                                    <div class="form-row-cc">
                                        <div class="form-group-cc">
                                            <label for="cc-expiry">Validade (MM/AA)</label>
                                            <input type="text" id="cc-expiry" placeholder="MM/AA" maxlength="5" autocomplete="cc-exp">
                                        </div>
                                        <div class="form-group-cc">
                                            <label for="cc-year" style="display:none;">Ano</label>
                                            <input type="hidden" id="cc-month" autocomplete="cc-exp-month">
                                            <input type="hidden" id="cc-year" autocomplete="cc-exp-year">
                                        </div>
                                        <div class="form-group-cc">
                                            <label for="cc-cvv">CVV</label>
                                            <input type="text" id="cc-cvv" placeholder="123" maxlength="4" autocomplete="cc-csc">
                                        </div>
                                    </div>
                                     <div class="form-row-cc-installments">
                                        <div class="form-group-cc">
                                            <label for="cc-installments">Parcelas</label>
                                            <select id="cc-installments" name="cc-installments">
                                                <option value="1">1x de R$ <?php echo number_format($total_valor, 2, ',', '.'); ?> (sem juros)</option>
                                                <option value="2">2x de R$ <?php echo number_format($total_valor / 2, 2, ',', '.'); ?></option>
                                                <option value="3">3x de R$ <?php echo number_format($total_valor / 3, 2, ',', '.'); ?></option>
                                                <option value="4">4x de R$ <?php echo number_format($total_valor / 4, 2, ',', '.'); ?></option>
                                                <option value="5">5x de R$ <?php echo number_format($total_valor / 5, 2, ',', '.'); ?></option>
                                                <option value="6">6x de R$ <?php echo number_format($total_valor / 6, 2, ',', '.'); ?></option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                </form>
                        </div>
                    </div>

                </div>

                <aside class="checkout-summary">
                    <h2>Resumo do pedido</h2>
                    <div class="summary-item-list">
                        <?php foreach ($cart_items_details as $item): ?>
                        <div class="summary-item">
                            <img src="<?php echo htmlspecialchars($item['imagem_url']); ?>" alt="<?php echo htmlspecialchars($item['nome']); ?>" class="summary-item-img">
                            <div class="summary-item-info">
                                <span class="name"><?php echo htmlspecialchars($item['nome']); ?></span>
                                <span class="qty">Qtd: <?php echo $item['quantidade']; ?></span>
                            </div>
                            <span class="summary-item-price">R$ <?php echo number_format($item['preco_total'], 2, ',', '.'); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="summary-obs">
                        <label for="observacoes">Observações</label>
                        <textarea id="observacoes" name="observacoes" placeholder="Adicione informações relacionadas ao seu pedido..."></textarea>
                    </div>
                    <div class="summary-totals">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <span id="summary-subtotal">R$ <?php echo number_format($subtotal, 2, ',', '.'); ?></span>
                        </div>
                        <div class="total-row">
                            <span>Frete</span>
                            <span id="summary-frete">-</span>
                        </div>
                        <div class="total-row grand-total">
                            <span>Total do pedido</span>
                            <span id="summary-total">R$ <?php echo number_format($total_valor, 2, ',', '.'); ?></span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary btn-finalizar" id="btn-finalizar-pedido" disabled>
                        <span class="btn-text">Finalizar Pedido</span>
                        <div class="spinner"></div>
                    </button>
                </aside>
            </div>

            <div class="checkout-layout" id="pix-payment-display" style="<?php echo $is_reopening_pix ? 'display: grid;' : 'display: none;'; ?>">
                <div id="pix-payment-box">
                    <h2>Escaneie para Pagar</h2>
                    <p>Use o app do seu banco para ler o QR Code abaixo.</p>
                    <div id="qr-code-container"></div>
                    <p style="margin-top: 20px;">Ou copie o código:</p>
                    <input type="text" id="pix-code-input" readonly>
                    <button id="copy-btn" class="btn btn-secondary" style="margin-top: 10px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px; height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" /></svg>
                        <span class="btn-text">Copiar Código</span>
                    </button>
                    <p class="pix-timer">Este código expira em <span id="pix-countdown">10:00</span></p>
                    <p style="margin-top: 20px;">Estamos aguardando a confirmação do pagamento...</p>
                </div>

                <aside class="checkout-summary" style="opacity: 0.7;">
                    <h2>Resumo do pedido</h2>
                    <div class="summary-item-list">
                        <?php foreach ($cart_items_details as $item): ?>
                        <div class="summary-item">
                            <img src="<?php echo htmlspecialchars($item['imagem_url']); ?>" alt="<?php echo htmlspecialchars($item['nome']); ?>" class="summary-item-img">
                            <div class="summary-item-info">
                                <span class="name"><?php echo htmlspecialchars($item['nome']); ?></span>
                                <span class="qty">Qtd: <?php echo $item['quantidade']; ?></span>
                            </div>
                            <span class="summary-item-price">R$ <?php echo number_format($item['preco_total'], 2, ',', '.'); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="summary-totals" style="margin-top: 20px;">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <span id="summary-subtotal-final">R$ <?php echo number_format($subtotal, 2, ',', '.'); ?></span>
                        </div>
                        <div class="total-row">
                            <span>Frete</span>
                            <span id="summary-frete-final">R$ <?php echo number_format($frete_valor, 2, ',', '.'); ?></span>
                        </div>
                        <div class="total-row grand-total">
                            <span>Total do pedido</span>
                            <span id="summary-total-final">R$ <?php echo number_format($total_valor, 2, ',', '.'); ?></span>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="modal-overlay" id="success-popup-overlay">
                 <div class="modal-content" style="text-align: center;">
                     <svg style="width: 60px; height: 60px; color: var(--success-color); margin: 0 auto 15px auto;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                         <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                     </svg>
                     <h2 class="modal-title" style="margin-bottom: 10px;">Pagamento Aprovado!</h2>
                     <p style="font-size: 1em; color: var(--text-color-medium);">Obrigado por sua compra. Seu pedido foi confirmado e está sendo preparado.</p>
                     <a href="pedidos.php" class="btn btn-primary" style="margin-top: 20px; border-radius: 4px;">Ver Meus Pedidos</a>
                 </div>
            </div>

        <?php endif; ?>
    </main>

    <?php include 'templates/footer.php'; ?>



    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        // ==========================================================
        // LÓGICA DE PAGAMENTO PIX (Sem alteração)
        // ==========================================================
        let pollingInterval;
        let timerInterval;

        function generateQRCode(pixCode) {
            const container = document.getElementById('qr-code-container');
            if (!container) return;
            container.innerHTML = '';
            new QRCode(container, {
                text: pixCode,
                width: 220,
                height: 220,
                correctLevel: QRCode.CorrectLevel.H
            });
            document.getElementById('pix-code-input').value = pixCode;
        }

        function startCountdown(expirationISOString) {
            const countdownEl = document.getElementById('pix-countdown');
            if (!countdownEl) return;
            clearInterval(timerInterval);
            const expiracaoTime = new Date(expirationISOString).getTime();

            timerInterval = setInterval(() => {
                const agoraTime = new Date().getTime();
                const timeLeft = Math.max(0, Math.floor((expiracaoTime - agoraTime) / 1000));

                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    countdownEl.textContent = "Expirado";
                    const pixDisplay = document.getElementById('pix-payment-display');
                    if (pixDisplay) {
                        document.getElementById('pix-payment-box').innerHTML = "<h2>PIX Expirado</h2><p>Este PIX já expirou e foi cancelado automaticamente. Por favor, inicie um novo pedido.</p><a href='index.php' class='btn btn-primary'>Voltar à Loja</a>";
                        pixDisplay.style.gridTemplateColumns = '1fr'; // Centraliza
                        const summary = document.querySelector('#pix-payment-display .checkout-summary');
                        if (summary) summary.style.display = 'none'; // Esconde resumo
                    }
                } else {
                    const minutes = Math.floor(timeLeft / 60).toString().padStart(2, '0');
                    const seconds = (timeLeft % 60).toString().padStart(2, '0');
                    countdownEl.textContent = `${minutes}:${seconds}`;
                }
            }, 1000);
        }

        function startPolling(pedidoId) {
            clearInterval(pollingInterval);
            pollingInterval = setInterval(async () => {
                try {
                    const res = await fetch(`api/check_payment_status.php?pedido_id=${pedidoId}`);
                    if (!res.ok) { console.error("Erro no polling: " + res.status); return; }
                    const data = await res.json();

                    // ATUALIZADO: 'APROVADO' (do nosso DB) ou 'PAID' (direto da API)
                    if (data.status === 'APROVADO' || data.status === 'PAID') {
                        clearInterval(pollingInterval);
                        clearInterval(timerInterval);
                        const mainLayout = document.getElementById('checkout-main-layout');
                        const pixDisplay = document.getElementById('pix-payment-display');
                        if (mainLayout) mainLayout.style.display = 'none';
                        if (pixDisplay) pixDisplay.style.display = 'none';
                        const successPopup = document.getElementById('success-popup-overlay');
                        if (successPopup) {
                            successPopup.classList.add('modal-open');
                        }
                        setTimeout(() => {
                            window.location.href = `pedidos.php?order_id=${pedidoId}`;
                        }, 3000);
                    }
                } catch (e) {
                    console.error('Erro na rede durante o polling:', e);
                }
            }, 5000); // Verifica a cada 5 segundos
        }

        // Botão Copiar (Sem alteração)
         const copyBtn = document.getElementById('copy-btn');
         if (copyBtn) {
           const originalBtnHTML = copyBtn.innerHTML;
           const successHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px; height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg> <span class="btn-text">Copiado!</span>`;
           copyBtn.addEventListener('click', function() {
             const input = document.getElementById('pix-code-input');
             const pixCode = input.value;
             if (navigator.clipboard && navigator.clipboard.writeText) {
                 navigator.clipboard.writeText(pixCode).then(() => {
                     copyBtn.innerHTML = successHTML;
                     setTimeout(() => { copyBtn.innerHTML = originalBtnHTML; }, 2000);
                 }).catch(err => {
                     fallbackCopyText(input, copyBtn, originalBtnHTML, successHTML);
                 });
             } else {
                 fallbackCopyText(input, copyBtn, originalBtnHTML, successHTML);
             }
           });
           function fallbackCopyText(inputElement, btn, originalHTML, successHTML) {
             inputElement.select();
             inputElement.setSelectionRange(0, 99999);
             try {
                 document.execCommand('copy');
                 btn.innerHTML = successHTML;
                 setTimeout(() => { btn.innerHTML = originalHTML; }, 2000);
             } catch (err) {
                 alert('Não foi possível copiar o código. Tente manualmente.');
             }
           }
         }


        // ==========================================================
        // SCRIPT CENTRAL DOMContentLoaded (Acordeão e Frete)
        // ==========================================================
        document.addEventListener('DOMContentLoaded', function() {

            // --- INICIALIZAÇÃO E LÓGICA DE REABRIR PIX ---
            const checkoutLayout = document.getElementById('checkout-main-layout');

            <?php if ($is_reopening_pix && $pix_data_to_js): ?>

                // Oculta o layout principal
                if (checkoutLayout) checkoutLayout.style.display = 'none';
                document.getElementById('pix-payment-display').style.display = 'grid';
                const pixData = <?php echo $pix_data_to_js; ?>;
                startCountdown(pixData.expira_em);
                startPolling(pixData.pedidoId);
                generateQRCode(pixData.pix_code); // Gera o QR Code

            <?php else: // Lógica Normal do Acordeão ?>

                if (<?php echo empty($cart_items_details) ? 'false' : 'true'; ?>) {

                    const btnFinalizar = document.getElementById('btn-finalizar-pedido');
                    const subtotal = <?php echo $subtotal; ?>;
                    let custoFrete = 0.00;
                    let freteSelecionado = false;
                    let pagamentoSelecionado = false;

                    function updateTotals() {
                        const total = subtotal + custoFrete;
                        document.getElementById('summary-frete').textContent = freteSelecionado ? 'R$ ' + custoFrete.toFixed(2).replace('.', ',') : '-';
                        document.getElementById('summary-total').textContent = 'R$ ' + total.toFixed(2).replace('.', ',');

                        const freteFinal = document.getElementById('summary-frete-final');
                        const totalFinal = document.getElementById('summary-total-final');
                        if(freteFinal) freteFinal.textContent = freteSelecionado ? 'R$ ' + custoFrete.toFixed(2).replace('.', ',') : '-';
                        if(totalFinal) totalFinal.textContent = 'R$ ' + total.toFixed(2).replace('.', ',');

                        // Atualiza as parcelas do cartão de crédito
                        const installmentsSelect = document.getElementById('cc-installments');
                        if (installmentsSelect) {
                            for (let i = 1; i <= 6; i++) {
                                const option = installmentsSelect.querySelector(`option[value="${i}"]`);
                                const valorParcela = (total / i).toFixed(2).replace('.', ',');
                                if(option) {
                                    option.textContent = `${i}x de R$ ${valorParcela} ${i > 1 ? '' : '(sem juros)'}`;
                                }
                            }
                        }
                    }

                    function activateStep(stepNum) {
                        for (let i = 1; i <= 4; i++) {
                            const step = document.getElementById('step-box-' + i);
                            const content = document.getElementById('step-content-' + i);
                            const chevron = step.querySelector('.step-chevron');
                            if (!step || !content || !chevron) continue;

                            step.classList.remove('step-active', 'step-complete', 'step-disabled');

                            if (i < stepNum) {
                                step.classList.add('step-complete');
                                content.style.display = 'none';
                                chevron.classList.remove('open');
                            } else if (i === stepNum) {
                                step.classList.add('step-active');
                                content.style.display = 'block';
                                chevron.classList.add('open');
                            } else {
                                step.classList.add('step-disabled');
                                content.style.display = 'none';
                                chevron.classList.remove('open');
                            }
                        }
                        btnFinalizar.disabled = !(stepNum === 4 && pagamentoSelecionado && freteSelecionado);
                    }

                    document.querySelectorAll('.step-header').forEach(header => {
                        header.addEventListener('click', function() {
                            const stepBox = this.closest('.step-box');
                            const stepNum = parseInt(this.getAttribute('data-step'));
                            if (stepBox.classList.contains('step-disabled')) return;

                            if (stepBox.classList.contains('step-active')) {
                                const content = stepBox.querySelector('.step-content');
                                const chevron = this.querySelector('.step-chevron');
                                if (content.style.display === 'block') {
                                    content.style.display = 'none';
                                    chevron.classList.remove('open');
                                } else {
                                    content.style.display = 'block';
                                    chevron.classList.add('open');
                                }
                            } else {
                                activateStep(stepNum);
                            }
                        });
                    });

                    // Passo 1: Dados Pessoais
                    document.getElementById('btn-step-1').addEventListener('click', function() { activateStep(2); });


                    // ▼▼▼ Lógica de Frete (AJAX) ▼▼▼

                    const btnStep2 = document.getElementById('btn-step-2');
                    const radiosEndereco = document.querySelectorAll('input[name="endereco_id"]');
                    const formEnvio = document.getElementById('form-step-3');
                    const btnStep3 = document.getElementById('btn-step-3');
                    const envioLoading = document.getElementById('envio-loading');
                    const envioOptionsList = document.getElementById('envio-options-list');
                    const envioErrorMessage = document.getElementById('envio-error-message');

                    async function calcularFreteCustom(cep) {
                        envioOptionsList.innerHTML = '';
                        envioErrorMessage.style.display = 'none';
                        envioLoading.style.display = 'flex';
                        btnStep3.disabled = true;
                        freteSelecionado = false;
                        custoFrete = 0.00;
                        updateTotals();
                        btnStep2.disabled = true;
                        btnStep2.innerHTML = '<span class="loading-spinner"></span>';

                        const formData = new FormData();
                        formData.append('cep', cep);

                        try {
                            const response = await fetch('api/calcular_frete_custom.php', {
                                method: 'POST',
                                body: formData
                            });

                            if (!response.ok) { throw new Error('Erro na rede: ' + response.statusText); }
                            const data = await response.json();
                            envioLoading.style.display = 'none';

                            if (data.success && data.opcoes.length > 0) {
                                data.opcoes.forEach((envio, index) => {
                                    const custoFormatado = parseFloat(envio.custo_base).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                                    const itemHTML = `
                                    <div class="address-item">
                                        <input type="radio" name="envio_id" id="envio_${envio.id}"
                                               value="${envio.id}"
                                               data-custo="${envio.custo_base}"
                                               ${index === 0 ? 'checked' : ''}>
                                        <label for="envio_${envio.id}">
                                            <strong>${envio.nome} (${custoFormatado})</strong>
                                            <span class="envio-details-text">${envio.descricao}</span><br>
                                            <span class="envio-prazo">Prazo para sua região: ${envio.prazo_estimado_dias} dias úteis</span>
                                        </label>
                                    </div>`;
                                    envioOptionsList.innerHTML += itemHTML;
                                });

                                const firstRadio = envioOptionsList.querySelector('input[name="envio_id"]');
                                if (firstRadio) {
                                    firstRadio.dispatchEvent(new Event('change', { 'bubbles': true }));
                                }
                            } else {
                                envioErrorMessage.textContent = data.message || 'Nenhuma forma de envio disponível para este CEP.';
                                envioErrorMessage.style.display = 'block';
                            }
                        } catch (error) {
                            console.error('Erro ao calcular frete:', error);
                            envioLoading.style.display = 'none';
                            envioErrorMessage.textContent = 'Erro ao calcular o frete. Tente novamente.';
                            envioErrorMessage.style.display = 'block';
                        } finally {
                            btnStep2.disabled = false;
                            btnStep2.innerHTML = 'Calcular Frete e Continuar';
                            activateStep(3);
                        }
                    }

                    if (btnStep2) {
                        btnStep2.addEventListener('click', async function() {
                            const selEndereco = document.querySelector('input[name="endereco_id"]:checked');
                            if (!selEndereco) {
                                alert("Por favor, selecione um endereço.");
                                return;
                            }
                            const cep = selEndereco.getAttribute('data-cep');
                            await calcularFreteCustom(cep);
                        });
                    }

                    if(btnStep3) {
                        btnStep3.addEventListener('click', function() {
                            if (!freteSelecionado) {
                                alert("Por favor, selecione uma forma de envio.");
                                return;
                            }
                            activateStep(4);
                        });
                    }

                    formEnvio.addEventListener('change', function(e) {
                        if (e.target.name === 'envio_id' && e.target.checked) {
                            custoFrete = parseFloat(e.target.getAttribute('data-custo'));
                            freteSelecionado = true;
                            updateTotals();
                            if (btnStep3) btnStep3.disabled = false;
                        }
                    });


                    // ==========================================================
                    // NOVO: LÓGICA DO PASSO 4 (Pagamento + Cartão)
                    // ==========================================================
                    const radiosPagamento = document.querySelectorAll('input[name="pag_id"]');
                    const formCartao = document.getElementById('credit-card-form');

                    radiosPagamento.forEach(radio => {
                        radio.addEventListener('change', function() {
                            if (this.checked) {
                                pagamentoSelecionado = true;
                                if (btnFinalizar) btnFinalizar.disabled = !freteSelecionado;

                                // Mostra/Esconde o formulário de Cartão
                                if (this.dataset.tipo === 'cartao_credito') {
                                    formCartao.style.display = 'block';
                                } else {
                                    formCartao.style.display = 'none';
                                }
                            }
                        });
                    });

                    // Máscara simples para Cartão 0000 0000 0000 0000
                    const ccNumber = document.getElementById('cc-number');
                    if(ccNumber) {
                        ccNumber.addEventListener('input', function(e) {
                            let v = e.target.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
                            let matches = v.match(/\d{4,16}/g);
                            let match = matches && matches[0] || '';
                            let parts = [];
                            for (let i=0, len=match.length; i<len; i+=4) {
                                parts.push(match.substring(i, i+4));
                            }
                            if (parts.length) {
                                e.target.value = parts.join(' ');
                            } else {
                                e.target.value = v;
                            }
                        });
                    }

                    // Máscara simples para Validade MM/AA
                    const ccExpiry = document.getElementById('cc-expiry');
                    if(ccExpiry) {
                        ccExpiry.addEventListener('input', function(e) {
                            let v = e.target.value.replace(/[^0-9]/gi, '');
                            if (v.length > 2) {
                                e.target.value = v.substring(0, 2) + '/' + v.substring(2, 4);
                            } else {
                                e.target.value = v;
                            }
                            // Salva mês e ano em campos hidden
                            const parts = e.target.value.split('/');
                            document.getElementById('cc-month').value = parts[0] || '';
                            document.getElementById('cc-year').value = parts[1] ? '20' + parts[1] : '';
                        });
                    }

                    // --- INICIALIZAÇÃO ---
                    const checkedEndereco = document.querySelector('input[name="endereco_id"]:checked');
                    if (checkedEndereco && btnStep2) { btnStep2.disabled = false; }
                    activateStep(1); // Garante que o Passo 1 esteja ativo ao carregar


                    // --- LISTENER DO BOTÃO FINALIZAR (FETCH) ---
                    if (btnFinalizar) {
                        btnFinalizar.addEventListener('click', async (e) => {
                            e.preventDefault();
                            const selEndereco = document.querySelector('input[name="endereco_id"]:checked');
                            const selEnvio = document.querySelector('input[name="envio_id"]:checked');
                            const selPagamento = document.querySelector('input[name="pag_id"]:checked');
                            const obs = document.getElementById('observacoes').value;

                            if (!selEndereco || !selEnvio || !selPagamento) {
                                alert("Erro: Dados incompletos. Por favor, revise os passos.");
                                return;
                            }

                            const tipoPagamento = selPagamento.dataset.tipo;
                            let payload = {
                                endereco_id: selEndereco.value,
                                envio_id: selEnvio.value,
                                pag_id: selPagamento.value,
                                observacoes: obs,
                                card_data: null,
                                installments: 1
                            };

                            // Se for Cartão, valida e coleta os dados do cartão
                            if (tipoPagamento === 'cartao_credito') {
                                const card_data = {
                                    number: document.getElementById('cc-number').value,
                                    holder_name: document.getElementById('cc-name').value,
                                    exp_month: document.getElementById('cc-month').value,
                                    exp_year: document.getElementById('cc-year').value,
                                    cvv: document.getElementById('cc-cvv').value
                                };
                                const installments = document.getElementById('cc-installments').value;

                                // Validação simples
                                if (!card_data.number || !card_data.holder_name || !card_data.exp_month || !card_data.exp_year || !card_data.cvv || !installments) {
                                    alert('Por favor, preencha todos os dados do cartão de crédito.');
                                    return;
                                }

                                payload.card_data = card_data;
                                payload.installments = installments;
                            }

                            // Animação do botão
                            btnFinalizar.disabled = true;
                            btnFinalizar.querySelector('.btn-text').style.display = 'none';
                            btnFinalizar.querySelector('.spinner').style.display = 'inline-block';

                            try {
                                const response = await fetch('api/create_payment.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify(payload) // Envia o payload completo
                                });
                                const data = await response.json();

                                if (data.status === 'success') {
                                    // SUCESSO (PIX)
                                    document.getElementById('checkout-main-layout').style.display = 'none';
                                    document.getElementById('pix-payment-display').style.display = 'grid';
                                    generateQRCode(data.pix_code);
                                    startCountdown(data.expira_em);
                                    startPolling(data.pedidoId);

                                } else if (data.status === 'success_cc') {
                                    // SUCESSO (CARTÃO DE CRÉDITO)
                                    // O pagamento foi aprovado direto, mostra o popup de sucesso.
                                    clearInterval(pollingInterval);
                                    clearInterval(timerInterval);
                                    document.getElementById('checkout-main-layout').style.display = 'none';
                                    document.getElementById('pix-payment-display').style.display = 'none';

                                    const successPopup = document.getElementById('success-popup-overlay');
                                    if (successPopup) {
                                        successPopup.classList.add('modal-open');
                                    }
                                    setTimeout(() => {
                                        window.location.href = `pedidos.php?order_id=${data.pedidoId}`;
                                    }, 3000);

                                } else {
                                    // ERRO (API recusou, etc.)
                                    alert('Erro: ' + data.message);
                                    btnFinalizar.disabled = false;
                                    btnFinalizar.querySelector('.btn-text').style.display = 'inline-block';
                                    btnFinalizar.querySelector('.spinner').style.display = 'none';
                                }
                            } catch (err) {
                                alert('Erro de conexão. Tente novamente.');
                                console.error(err);
                                btnFinalizar.disabled = false;
                                btnFinalizar.querySelector('.btn-text').style.display = 'inline-block';
                                btnFinalizar.querySelector('.spinner').style.display = 'none';
                            }
                        });
                    }
                } // Fim do IF (carrinho não está vazio)

            <?php endif; ?>

        }); // Fim do DOMContentLoaded
    </script>

</body>
</html>