<?php
// suporte.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';
require_once 'funcoes.php'; // Para carregarConfigApi

// Carrega as constantes (NOME_DA_LOJA, Mailgun keys, etc.)
if (isset($pdo)) {
    carregarConfigApi($pdo);
}

// --- 1. VERIFICAR LOGIN (NÃO MAIS PROTEGER) ---
$is_logged_in = (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true);
$user_id = $_SESSION['user_id'] ?? null;

$errors = [];
$form_data = $_POST; // Guarda os dados para repopular o formulário
$success_message = '';
$pedidos_concluidos = [];
$tickets_abertos = [];

// --- 2. DEFINIR MOTIVOS (Separados por login) ---
$motivos_contato_logado = [
    'DUVIDA_PRODUTO' => 'Dúvida sobre um produto',
    'PEDIDO_ATRASADO' => 'Meu pedido está atrasado',
    'PRODUTO_DEFEITO' => 'Produto com defeito/errado',
    'PROBLEMA_PAGAMENTO' => 'Problema com pagamento',
    'CANCELAMENTO' => 'Cancelar/Devolver pedido',
    'OUTRO' => 'Outro Assunto (Logado)'
];
$motivos_contato_guest = [
    'DUVIDA_PRODUTO' => 'Dúvida sobre um produto',
    'PROBLEMA_LOGIN' => 'Problema para acessar a conta',
    'PROBLEMA_PAGAMENTO' => 'Problema com pagamento (Não logado)',
    'OUTRO' => 'Outro Assunto (Não logado)'
];
$motivos_contato = $is_logged_in ? $motivos_contato_logado : $motivos_contato_guest;


// --- 3. LÓGICA DE SALVAR (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['abrir_ticket'])) {

    $motivo = trim($_POST['motivo'] ?? '');
    $assunto = trim($_POST['assunto'] ?? '');
    $mensagem = trim($_POST['mensagem'] ?? '');
    $pedido_id = null;
    $guest_nome = null;
    $guest_email = null;

    // Validações
    if (empty($motivo) || !array_key_exists($motivo, $motivos_contato)) {
        $errors['motivo'] = "Por favor, selecione um motivo válido.";
    }
    if (empty($assunto)) {
        $errors['assunto'] = "O campo Assunto é obrigatório.";
    }
    if (strlen($assunto) > 255) {
        $errors['assunto'] = "O Assunto deve ter no máximo 255 caracteres.";
    }
    if (empty($mensagem)) {
        $errors['mensagem'] = "A Mensagem é obrigatória.";
    }

    if ($is_logged_in) {
        // Validação de Logado
        $pedido_id = filter_input(INPUT_POST, 'pedido_id', FILTER_VALIDATE_INT);
        if (in_array($motivo, ['PEDIDO_ATRASADO', 'PRODUTO_DEFEITO', 'CANCELAMENTO']) && empty($pedido_id)) {
            $errors['pedido_id'] = "Você deve selecionar o pedido relacionado a este motivo.";
        }
        if (!in_array($motivo, ['PEDIDO_ATRASADO', 'PRODUTO_DEFEITO', 'CANCELAMENTO'])) {
            $pedido_id = null;
        }
    } else {
        // Validação de Visitante
        $guest_nome = trim($_POST['guest_nome'] ?? '');
        $guest_email = trim(strtolower($_POST['guest_email'] ?? ''));
        if (empty($guest_nome)) {
            $errors['guest_nome'] = "Seu nome é obrigatório.";
        }
        if (empty($guest_email)) {
            $errors['guest_email'] = "Seu e-mail é obrigatório.";
        } elseif (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
            $errors['guest_email'] = "Formato de e-mail inválido.";
        }
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO tickets (usuario_id, pedido_id, status_id, motivo, assunto, mensagem, guest_nome, guest_email, data_criacao, ultima_atualizacao)
                    VALUES (:uid, :pid, 1, :motivo, :assunto, :msg, :gnome, :gmail, NOW(), NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':uid' => $user_id,
                ':pid' => $pedido_id,
                ':motivo' => $motivos_contato[$motivo],
                ':assunto' => $assunto,
                ':msg' => $mensagem,
                ':gnome' => $guest_nome,
                ':gmail' => $guest_email
            ]);

            $success_message = "Ticket aberto com sucesso! Nossa equipe responderá em breve no e-mail informado.";
            $form_data = []; // Limpa o formulário

        } catch (PDOException $e) {
            $errors['db'] = "Erro ao abrir o ticket. Tente novamente.";
            error_log("Erro ao salvar ticket: " . $e->getMessage());
        }
    }
}


// --- 4. LÓGICA DE BUSCA (GET - Somente se logado) ---
if ($is_logged_in) {
    try {
        // Buscar pedidos do usuário (para o dropdown)
        $stmt_pedidos = $pdo->prepare("SELECT id, criado_em, valor_total FROM pedidos WHERE usuario_id = :uid ORDER BY criado_em DESC");
        $stmt_pedidos->execute(['uid' => $user_id]);
        $pedidos_concluidos = $stmt_pedidos->fetchAll();

        // Buscar tickets anteriores do usuário
        $stmt_tickets = $pdo->prepare("
            SELECT t.*, ts.nome AS status_nome, ts.cor_badge
            FROM tickets t
            JOIN ticket_status ts ON t.status_id = ts.id
            WHERE t.usuario_id = :uid
            ORDER BY t.ultima_atualizacao DESC
        ");
        $stmt_tickets->execute(['uid' => $user_id]);
        $tickets_abertos = $stmt_tickets->fetchAll();

    } catch (PDOException $e) {
        $errors['db_load'] = "Erro ao carregar dados da página: " . $e->getMessage();
    }
}

// Carregar a cor de hover do DB (para o botão)
$cor_acento_hover = defined('cor_acento_hover') ? cor_acento_hover : '#8cc600';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suporte ao Cliente - Minha Conta</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS ESPECÍFICO DESTA PÁGINA (Layout Perfil + Formulário Suporte)
           ========================================================== */

        :root {
            --green-accent: #a4d32a;
            --text-color-dark: #333;
            --text-color-medium: #666;
            --text-color-light: #999;
            --border-color-light: #eee;
            --border-color-medium: #ccc;
            --error-color: #ff4444;
            --success-color: #5cb85c;
            --cor-acento-hover: <?php echo htmlspecialchars($cor_acento_hover); ?>;
            --cor-fundo-hover: <?php echo defined('cor_fundo_hover_claro') ? cor_fundo_hover_claro : '#f2f2f2'; ?>;
        }

        /* --- Layout da Página de Perfil --- */
        .profile-title { font-size: 1.8em; font-weight: bold; color: var(--text-color-dark); margin: 15px 0 25px 172px; }
        .profile-layout { display: flex; gap: 30px; align-items: flex-start; }

        /* --- Barra Lateral de Perfil --- */
        .profile-sidebar { width: 260px; flex-shrink: 0; background-color: #fff; border: 1px solid var(--border-color-light); box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: -4px; }
        .profile-sidebar nav ul { list-style: none; padding: 0; margin: 0; }
        .profile-sidebar nav li { border-bottom: 1px solid var(--border-color-light); }
        .profile-sidebar nav li:last-child { border-bottom: none; }
        .profile-sidebar nav a { display: flex; align-items: center; gap: 15px; padding: 18px 20px; font-size: 0.95em; color: var(--text-color-medium); transition: background-color 0.2s ease, color 0.2s ease; }
        .profile-sidebar nav a svg { width: 20px; height: 20px; stroke-width: 2; color: var(--text-color-light); transition: color 0.2s ease; }
        .profile-sidebar nav a:hover { background-color: #fdfdfd; color: var(--green-accent); }
        .profile-sidebar nav a:hover svg { color: var(--green-accent); }
        .profile-sidebar nav a.active { background-color: #f5f5f5; color: var(--text-color-dark); font-weight: bold; }
        .profile-sidebar nav a.active svg { color: var(--text-color-dark); }

        /* --- Conteúdo do Perfil --- */
        .profile-content { flex-grow: 1; }
        .data-card {
            background-color: #fff;
            border: 1px solid var(--border-color-light);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            padding: 30px;
            margin-bottom: 30px;
        }
        .data-card h2 {
            font-size: 1.3em;
            font-weight: bold;
            color: var(--text-color-dark);
            margin-top: 0;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color-light);
        }

        .profile-layout.guest-layout .profile-sidebar { display: none; }
        .profile-layout.guest-layout .profile-content {
            flex-grow: 1;
            max-width: 800px;
            margin: 0 auto;
        }

        /* --- Estilos do Formulário de Suporte --- */
        .form-grid-guest { display: grid; grid-template-columns: 1fr 1fr; gap: 0 20px; }
        .form-group { margin-bottom: 18px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: bold; font-size: 0.9em; color: var(--text-color-dark); }
        .form-group label span { color: var(--error-color); }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group textarea,
        .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border-color-medium);
            border-radius: 4px; box-sizing: border-box; font-size: 1em; background-color: #fff;
        }
        .form-group textarea { min-height: 120px; resize: vertical; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            border-color: var(--green-accent);
            box-shadow: 0 0 0 2px rgba(154, 215, 0, 0.2);
            outline: none;
        }
        .error-message { color: var(--error-color); font-size: 0.8em; margin-top: 4px; display: block; }
        input.error-border, select.error-border, textarea.error-border { border-color: var(--error-color) !important; }
        .general-error { color: var(--error-color); font-weight: bold; text-align: center; margin-bottom: 15px; }
        .general-success {
            background-color: #f0f9eb; border: 1px solid var(--success-color); color: var(--success-color);
            padding: 15px; border-radius: 4px; margin-bottom: 20px; text-align: center;
        }

        /* --- Estilos da Lista de Tickets --- */
        .ticket-list { list-style: none; padding: 0; margin: 0; }
        .ticket-item {
            display: flex; justify-content: space-between; align-items: center; padding: 15px;
            border: 1px solid var(--border-color-light); border-radius: 8px; margin-bottom: 15px; background-color: #fcfcfc;
        }
        .ticket-info { flex-grow: 1; }
        .ticket-info a { text-decoration: none; }
        .ticket-info .ticket-assunto { font-size: 1.1em; color: var(--text-color-dark); font-weight: bold; margin: 0 0 5px 0; }
        .ticket-info .ticket-assunto:hover { color: var(--green-accent); }
        .ticket-info .ticket-meta { font-size: 0.85em; color: var(--text-color-medium); }
        .ticket-status { flex-shrink: 0; text-align: right; }
        .ticket-status .status-badge {
            font-size: 0.8em; font-weight: bold; padding: 5px 12px; border-radius: 20px;
            text-transform: uppercase; color: #fff; background-color: var(--cor-status, #888);
        }
        .no-tickets { color: var(--text-color-medium); text-align: center; }

        /* --- Botão Salvar --- */
        .btn-salvar {
             display: inline-block; padding: 10px 25px; border-radius: 4px; font-size: 0.9em;
             font-weight: bold; text-align: center; text-decoration: none; cursor: pointer;
             transition: all 0.3s ease; border: 1px solid var(--green-accent);
             background-color: var(--green-accent); color: #fff;
        }
        .btn-salvar:hover {
            background-color: var(--cor-acento-hover);
            border-color: var(--cor-acento-hover);
        }

        /* ==========================================================
           ⭐ CSS ATUALIZADO: SlideDown e Acordeão de Pedidos
           ========================================================== */

        #ticket-details-wrapper,
        #pedido-id-group {
            display: none;
        }

        .order-selector-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--border-color-medium);
            border-radius: 4px;
            padding: 10px;
            background-color: #fdfdfd;
        }
        .order-selector-card {
            border: 2px solid var(--border-color-light);
            border-radius: 5px;
            padding: 15px;
            transition: border-color 0.2s ease, background-color 0.2s ease;
            cursor: pointer; /* ⭐ NOVO: O card todo é clicável */
        }
        .order-selector-card:hover {
            background-color: var(--cor-fundo-hover);
        }
        .order-selector-card.active {
            border-color: var(--green-accent);
            background-color: #fafff0;
        }
        .order-selector-card .order-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            pointer-events: none; /* Facilita o clique no card */
        }
        .order-selector-card .order-card-header strong {
            font-size: 1.1em;
            color: var(--text-color-dark);
        }
        .order-selector-card .order-card-header .order-card-price {
            font-size: 1em;
            font-weight: bold;
            color: var(--text-color-medium);
        }
        .order-selector-card .order-card-date {
            font-size: 0.9em;
            color: var(--text-color-light);
            pointer-events: none;
        }

        .product-item-preview-list {
            display: none; /* Oculto até ser preenchido */
            padding-left: 20px;
            margin-top: 10px;
            font-size: 0.9em;
            list-style-type: '✓ ';
            color: var(--text-color-medium);
        }
        .product-item-preview-list li {
            padding-left: 8px;
            margin-bottom: 3px;
        }

        .product-item-loader {
            display: none; /* Oculto */
            text-align: center;
            padding: 10px;
        }
        .product-item-loader span {
            font-style: italic;
            color: var(--text-color-light);
        }

        .order-selector-card.loading .product-item-loader {
            display: block;
        }
        .order-selector-card.preview-open .toggle-products-btn {
            display: none;
        }

        .toggle-products-btn {
            font-size: 0.85em;
            color: var(--green-accent);
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
            display: inline-block;
            text-decoration: underline;
        }
        .toggle-products-btn:hover {
            color: var(--cor-acento-hover);
        }

        .hidden-radio {
            display: none; /* O rádio real fica oculto */
        }

        #selected-order-summary {
            display: none; /* Começa oculto */
            background-color: #fafff0;
            border: 2px solid var(--green-accent);
            border-radius: 5px;
            padding: 15px;
            margin-top: -2px;
            margin-bottom: 18px;
            position: relative;
            z-index: 2;
        }
        #selected-order-summary p {
            margin: 0;
            font-size: 0.95em;
            color: var(--text-color-dark);
        }
        #selected-order-summary strong {
            font-size: 1.1em;
        }
        #selected-order-summary a {
            font-size: 0.9em;
            color: var(--green-accent);
            font-weight: bold;
            text-decoration: underline;
            cursor: pointer;
            margin-left: 10px;
        }
        #selected-order-summary a:hover {
            color: var(--cor-acento-hover);
        }


        /* Responsividade */
        @media (max-width: 992px) {
            .profile-layout { flex-direction: column; }
            .profile-sidebar { width: 100%; margin-bottom: 20px; }
            .profile-content { width: 100%; }
            .profile-title {
                font-size: 1.8em;
                font-weight: bold;
                color: var(--text-color-dark);
                margin: 15px 0 25px 0;
            }
        }
         @media (max-width: 768px) {
            .form-grid-guest { grid-template-columns: 1fr; gap: 0; }
            .profile-title {
                font-size: 1.8em;
                font-weight: bold;
                color: var(--text-color-dark);
                margin: 15px 0 25px 0;
            }
         }
    </style>
</head>
<body class="<?php echo $is_logged_in ? 'profile-page' : 'suporte-guest'; ?>">

    <?php include 'templates/header.php'; ?>

    <main class="main-content container">

        <h1 class="profile-title">Suporte ao Cliente</h1>

        <div class="profile-layout <?php echo !$is_logged_in ? 'guest-layout' : ''; ?>">

            <?php if ($is_logged_in): ?>
            <aside class="profile-sidebar">
                <nav>
                    <ul>
                        <li><a href="perfil.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg><span>Minha conta</span></a></li>
                        <li><a href="pedidos.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg><span>Meus pedidos</span></a></li>
                        <li><a href="meus-dados.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4z" /></svg><span>Meus dados</span></a></li>
                        <li><a href="enderecos.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg><span>Meus endereços</span></a></li>
                        <li><a href="lista-desejos.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg><span>Lista de desejos</span></a></li>
                        <li><a href="suporte.php" class="active"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg><span>Suporte</span></a></li>
                        <li><a href="alterar-senha.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg><span>Alterar senha</span></a></li>
                        <li><a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg><span>Sair</span></a></li>
                    </ul>
                </nav>
            </aside>
            <?php endif; ?>

            <section class="profile-content">

                <div class="data-card">
                    <h2>Abrir Novo Ticket de Suporte</h2>

                    <?php if ($success_message): ?>
                        <div class="general-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>
                    <?php if (isset($errors['db']) || isset($errors['db_load'])): ?>
                        <div class="general-error"><?php echo $errors['db'] ?? $errors['db_load']; ?></div>
                    <?php endif; ?>

                    <form action="suporte.php" method="POST" id="form-suporte">

                        <?php if (!$is_logged_in): ?>
                            <p>Como você não está logado, por favor, preencha seus dados de contato para que possamos respondê-lo.</p>
                            <div class="form-grid-guest">
                                <div class="form-group">
                                    <label for="guest_nome"><span>*</span> Seu Nome:</label>
                                    <input type="text" id="guest_nome" name="guest_nome" required value="<?php echo htmlspecialchars($form_data['guest_nome'] ?? ''); ?>" class="<?php echo isset($errors['guest_nome']) ? 'error-border' : ''; ?>">
                                    <?php if(isset($errors['guest_nome'])): ?><span class="error-message"><?php echo $errors['guest_nome']; ?></span><?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label for="guest_email"><span>*</span> Seu E-mail:</label>
                                    <input type="email" id="guest_email" name="guest_email" required value="<?php echo htmlspecialchars($form_data['guest_email'] ?? ''); ?>" class="<?php echo isset($errors['guest_email']) ? 'error-border' : ''; ?>">
                                    <?php if(isset($errors['guest_email'])): ?><span class="error-message"><?php echo $errors['guest_email']; ?></span><?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="motivo"><span>*</span> Motivo do Contato:</label>
                            <select id="motivo" name="motivo" required class="<?php echo isset($errors['motivo']) ? 'error-border' : ''; ?>">
                                <option value="">-- Selecione o motivo --</option>
                                <?php foreach ($motivos_contato as $chave => $texto): ?>
                                    <option value="<?php echo $chave; ?>" <?php echo (isset($form_data['motivo']) && $form_data['motivo'] == $chave) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($texto); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if(isset($errors['motivo'])): ?><span class="error-message"><?php echo $errors['motivo']; ?></span><?php endif; ?>
                        </div>

                        <div id="ticket-details-wrapper">

                            <?php if ($is_logged_in): ?>
                            <div class="form-group" id="pedido-id-group">
                                <label><span>*</span> Sobre qual pedido?</label>

                                <?php if(isset($errors['pedido_id'])): ?><span class="error-message" style="margin-bottom: 5px;"><?php echo $errors['pedido_id']; ?></span><?php endif; ?>

                                <div id="selected-order-summary">
                                    <p>Selecionado: <strong>Pedido #<span id="selected-order-id"></span></strong> <a id="change-order-btn">(Alterar)</a></p>
                                </div>

                                <div class="order-selector-list">
                                    <?php if (empty($pedidos_concluidos)): ?>
                                        <p class="no-tickets">Você não possui pedidos para selecionar.</p>
                                    <?php else: ?>
                                        <?php foreach ($pedidos_concluidos as $pedido): ?>
                                        <div class="order-selector-card" data-id="<?php echo $pedido['id']; ?>">
                                            <input type="radio" name="pedido_id" value="<?php echo $pedido['id']; ?>" id="radio_pedido_<?php echo $pedido['id']; ?>" class="hidden-radio"
                                                   <?php echo (isset($form_data['pedido_id']) && $form_data['pedido_id'] == $pedido['id']) ? 'checked' : ''; ?>>

                                            <div class="order-card-header">
                                                <strong>Pedido #<?php echo $pedido['id']; ?></strong>
                                                <span class="order-card-price">R$ <?php echo number_format($pedido['valor_total'], 2, ',', '.'); ?></span>
                                            </div>
                                            <span class="order-card-date">Feito em: <?php echo date('d/m/Y', strtotime($pedido['criado_em'])); ?></span>

                                            <span class="toggle-products-btn">Ver produtos</span>
                                            <div class="product-item-loader">
                                                <span>Carregando itens...</span>
                                            </div>
                                            <ul class="product-item-preview-list">
                                                </ul>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="form-group" id="assunto-group">
                                <label for="assunto"><span>*</span> Assunto:</label>
                                <input type="text" id="assunto" name="assunto" required value="<?php echo htmlspecialchars($form_data['assunto'] ?? ''); ?>" class="<?php echo isset($errors['assunto']) ? 'error-border' : ''; ?>" maxlength="255">
                                <?php if(isset($errors['assunto'])): ?><span class="error-message"><?php echo $errors['assunto']; ?></span><?php endif; ?>
                            </div>

                            <div class="form-group" id="mensagem-group">
                                <label for="mensagem"><span>*</span> Mensagem:</label>
                                <textarea id="mensagem" name="mensagem" required class="<?php echo isset($errors['mensagem']) ? 'error-border' : ''; ?>" placeholder="Descreva seu problema ou dúvida em detalhes..."><?php echo htmlspecialchars($form_data['mensagem'] ?? ''); ?></textarea>
                                <?php if(isset($errors['mensagem'])): ?><span class="error-message"><?php echo $errors['mensagem']; ?></span><?php endif; ?>
                            </div>

                            <div class="submit-button-container" style="text-align: right;" id="btn-submit-group">
                                <button type="submit" name="abrir_ticket" class="btn-salvar">Abrir Ticket</button>
                            </div>

                        </div> </form>
                </div>

                <?php if ($is_logged_in): ?>
                <div class="data-card">
                    <h2>Meus Tickets</h2>

                    <?php if (isset($errors['db_load']) && empty($errors['db'])): ?>
                        <div class="general-error"><?php echo $errors['db_load']; ?></div>
                    <?php elseif (empty($tickets_abertos)): ?>
                        <p class="no-tickets">Você ainda não abriu nenhum ticket de suporte.</p>
                    <?php else: ?>
                        <ul class="ticket-list">
                            <?php foreach ($tickets_abertos as $ticket): ?>
                            <li class="ticket-item">
                                <div class="ticket-info">
                                    <a href="detalhes_ticket.php?id=<?php echo $ticket['id']; ?>">
                                        <div class="ticket-assunto"><?php echo htmlspecialchars($ticket['assunto']); ?></div>
                                    </a>
                                    <div class="ticket-meta">
                                        Ticket #<?php echo $ticket['id']; ?> | Motivo: <?php echo htmlspecialchars($ticket['motivo']); ?>
                                        <?php if ($ticket['pedido_id']): ?>
                                            | Pedido #<?php echo $ticket['pedido_id']; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="ticket-status">
                                    <span class="status-badge" style="--cor-status: <?php echo htmlspecialchars($ticket['cor_badge']); ?>;">
                                        <?php echo htmlspecialchars($ticket['status_nome']); ?>
                                    </span>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </section>
        </div>
    </main>

    <?php include 'templates/footer.php'; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        $(document).ready(function() {

            // --- Variáveis Globais do Formulário ---
            const motivoSelect = $('#motivo');
            const detailsWrapper = $('#ticket-details-wrapper');
            const pedidoGroup = $('#pedido-id-group');
            const orderList = $('.order-selector-list');
            const orderSummary = $('#selected-order-summary');
            const motivosDePedido = ['PEDIDO_ATRASADO', 'PRODUTO_DEFEITO', 'CANCELAMENTO'];
            const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;

            // --- 1. LÓGICA DE SLIDEDOWN DO FORMULÁRIO (Motivo) ---
            function checkFormState() {
                const motivoValor = motivoSelect.val();

                if (motivoValor !== '') {
                    detailsWrapper.slideDown(300);
                } else {
                    detailsWrapper.slideUp(300);
                }

                if (isLoggedIn) {
                    if (motivosDePedido.includes(motivoValor)) {
                        pedidoGroup.slideDown(300);
                    } else {
                        pedidoGroup.slideUp(300);
                        resetOrderSelection();
                    }
                }
            }

            // --- 2. LÓGICA DO ACORDEÃO E SELEÇÃO DE PEDIDOS ---

            // ⭐ 2.1 Clicar em "Ver produtos" (Acordeão de "Espiar")
            $('.toggle-products-btn').on('click', function(e) {
                e.stopPropagation(); // Impede que o card principal (que seleciona) seja clicado

                const $clickedCard = $(this).closest('.order-selector-card');
                const $clickedList = $clickedCard.find('.product-item-preview-list');
                const pedidoId = $clickedCard.data('id');
                const wasOpen = $clickedCard.hasClass('preview-open');

                // 1. Fecha TODOS OS OUTROS previews abertos
                $('.order-selector-card.preview-open').not($clickedCard).each(function() {
                    $(this).removeClass('preview-open');
                    $(this).find('.product-item-preview-list').slideUp(200);
                });

                // 2. Abre ou fecha o card clicado
                if (wasOpen) {
                     $clickedList.slideUp(200);
                     $clickedCard.removeClass('preview-open');
                } else {
                    $clickedCard.addClass('preview-open');
                    if (!$clickedCard.hasClass('loaded') && !$clickedCard.hasClass('loading')) {
                        fetchOrderItems(pedidoId, $clickedCard); // Função busca e abre o slide
                    } else {
                        $clickedList.slideDown(200); // Apenas reabre
                    }
                }
            });

            // ⭐ 2.2 Clicar no Card (Seleciona o pedido)
            $('.order-selector-card').on('click', function(e) {
                // Se o clique foi no botão "Ver produtos", ignora este evento
                if ($(e.target).hasClass('toggle-products-btn')) {
                    return;
                }

                const $card = $(this);
                const pedidoId = $card.data('id');

                // Fecha o preview (se estiver aberto)
                if ($card.hasClass('preview-open')) {
                    $card.removeClass('preview-open');
                    $card.find('.product-item-preview-list').slideUp(200);
                }

                // 1. Marca este como ativo
                $('.order-selector-card').removeClass('active');
                $card.addClass('active');
                $card.find('.hidden-radio').prop('checked', true);

                // 2. Recolhe a lista
                orderList.slideUp(300);

                // 3. Mostra o resumo
                orderSummary.find('#selected-order-id').text(pedidoId);
                orderSummary.slideDown(200);
            });

            // 2.3 Clicar em "(Alterar)" no resumo
            $('#change-order-btn').on('click', function() {
                orderSummary.slideUp(200);
                orderList.slideDown(300);
                // Desmarca o rádio para forçar nova seleção
                $('.order-selector-card.active').removeClass('active');
                $('.hidden-radio').prop('checked', false);
            });

            // 2.4 Função para resetar a seleção de pedidos
            function resetOrderSelection() {
                orderSummary.slideUp(200);
                $('.order-selector-card.active').removeClass('active');
                $('.hidden-radio').prop('checked', false);
                $('.product-item-preview-list').slideUp(200).removeClass('preview-open');
            }

            // 2.5 Função FETCH (AJAX) para buscar os itens
            async function fetchOrderItems(pedidoId, $cardElement) {
                $cardElement.addClass('loading');
                const $loader = $cardElement.find('.product-item-loader');
                const $list = $cardElement.find('.product-item-preview-list');

                try {
                    $loader.slideDown(100);
                    const response = await fetch(`api/get_order_items.php?id=${pedidoId}`);
                    const data = await response.json();

                    if (response.ok && data.status === 'success') {
                        $list.empty();

                        if (data.items.length > 0) {
                            data.items.forEach(item => {
                                $list.append(`<li>${item.produto_nome} (x${item.quantidade})</li>`);
                            });
                        } else {
                            $list.append(`<li>Nenhum item encontrado.</li>`);
                        }

                        $loader.slideUp(100);
                        $list.slideDown(300); // Animação de abertura
                        $cardElement.addClass('loaded');

                    } else {
                        $loader.html(`<span>Erro: ${data.message}</span>`);
                    }

                } catch (error) {
                    console.error("Erro no fetch:", error);
                    $loader.html(`<span>Erro de conexão.</span>`);
                } finally {
                    $cardElement.removeClass('loading');
                }
            }

            // --- 3. INICIALIZAÇÃO (Ao carregar a página) ---

            const motivoInicial = motivoSelect.val();
            if (motivoInicial !== '' || !isLoggedIn) {
                detailsWrapper.show();
            }
            if (isLoggedIn && motivosDePedido.includes(motivoInicial)) {
                 pedidoGroup.show();
            }

            const pedidoInicial = $('input[name="pedido_id"]:checked').val();
            if (pedidoInicial) {
                orderSummary.find('#selected-order-id').text(pedidoInicial);
                orderSummary.show();
                orderList.hide();
            }

            motivoSelect.on('change', checkFormState);
        });
    </script>
</body>
</html>