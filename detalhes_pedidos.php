<?php
// detalhes_pedido.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php'; // Inclui a conexão $pdo
date_default_timezone_set('America/Sao_Paulo');

// --- 1. PROTEÇÃO DA PÁGINA E OBTENÇÃO DO ID ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    $_SESSION['redirect_url'] = basename($_SERVER['REQUEST_URI']);
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_nome = $_SESSION['user_nome'] ?? 'Cliente'; // Pega o nome do usuário da sessão
$pedido_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$pedido_id) {
    header('Location: pedidos.php');
    exit;
}

$pedido = null;
$itens_pedido = [];
$historico_avaliacoes = []; // Para a coluna da direita
$errors = [];
$page_alert_message = '';
$flash_message = ''; // Para mensagens de sucesso da avaliação

// ==========================================================
// NOVO: LÓGICA PARA SALVAR AVALIAÇÃO (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_avaliacao'])) {

    $produto_id_avaliado = filter_input(INPUT_POST, 'produto_id', FILTER_VALIDATE_INT);
    $classificacao = filter_input(INPUT_POST, 'classificacao', FILTER_VALIDATE_INT);
    $comentario = trim($_POST['comentario'] ?? '');

    // Prioriza a URL colada (via JS) ou processa o upload do arquivo
    $foto_url_final = trim($_POST['foto_url_colada'] ?? '');

    // 1. Lógica de Upload (se um arquivo foi selecionado)
    if (isset($_FILES['foto_arquivo']) && $_FILES['foto_arquivo']['error'] == 0) {
        $target_dir = "uploads/reviews/"; // Relativo à raiz do site
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        // Validação básica
        $check = @getimagesize($_FILES["foto_arquivo"]["tmp_name"]);
        $imageFileType = strtolower(pathinfo($_FILES["foto_arquivo"]["name"], PATHINFO_EXTENSION));

        if ($check !== false && in_array($imageFileType, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            // Renomeia o arquivo para evitar conflitos
            $new_filename = $target_dir . uniqid('review_', true) . '.' . $imageFileType;
            if (move_uploaded_file($_FILES["foto_arquivo"]["tmp_name"], $new_filename)) {
                $foto_url_final = "/" . $new_filename; // Salva o caminho relativo
            } else {
                $errors['upload'] = "Erro ao mover o arquivo enviado.";
            }
        } else {
            $errors['upload'] = "Arquivo não é uma imagem válida (Permitidos: jpg, png, gif, webp).";
        }
    }

    // Validação do Formulário
    if (empty($produto_id_avaliado) || $classificacao === false || $classificacao < 1 || $classificacao > 5) {
        $errors['form'] = "Por favor, selecione um produto e uma classificação (estrelas).";
    }

    if (empty($errors)) {
        try {
            // 2. Verificar se o usuário realmente comprou este item neste pedido (Segurança)
            $stmt_check = $pdo->prepare("
                SELECT COUNT(*)
                FROM pedidos_itens pi
                JOIN pedidos p ON pi.pedido_id = p.id
                WHERE p.id = :pedido_id
                  AND p.usuario_id = :user_id
                  AND pi.produto_id = :produto_id
            ");
            $stmt_check->execute([
                ':pedido_id' => $pedido_id,
                ':user_id' => $user_id,
                ':produto_id' => $produto_id_avaliado
            ]);

            if ($stmt_check->fetchColumn() > 0) {
                // 3. Verificar se já existe uma avaliação (UPDATE) ou se é nova (INSERT)
                $stmt_find = $pdo->prepare("SELECT id FROM avaliacoes_produto WHERE usuario_id = :uid AND produto_id = :pid LIMIT 1");
                $stmt_find->execute(['uid' => $user_id, 'pid' => $produto_id_avaliado]);
                $existing_id = $stmt_find->fetchColumn();

                if ($existing_id) {
                    // UPDATE
                    $sql = "UPDATE avaliacoes_produto SET
                                classificacao = :class,
                                comentario = :coment,
                                foto_url = :foto,
                                data_avaliacao = NOW(),
                                aprovado = TRUE -- Aprovado automaticamente
                            WHERE id = :id AND usuario_id = :uid";
                    $stmt_save = $pdo->prepare($sql);
                    $stmt_save->execute([
                        ':class' => $classificacao,
                        ':coment' => $comentario,
                        ':foto' => $foto_url_final,
                        ':id' => $existing_id,
                        ':uid' => $user_id
                    ]);
                } else {
                    // INSERT
                    $sql = "INSERT INTO avaliacoes_produto
                                (produto_id, usuario_id, nome_avaliador, classificacao, comentario, foto_url, aprovado, data_avaliacao)
                            VALUES
                                (:pid, :uid, :nome, :class, :coment, :foto, TRUE, NOW())";
                    $stmt_save = $pdo->prepare($sql);
                    $stmt_save->execute([
                        ':pid' => $produto_id_avaliado,
                        ':uid' => $user_id,
                        ':nome' => $user_nome, // Nome do usuário logado
                        ':class' => $classificacao,
                        ':coment' => $comentario,
                        ':foto' => $foto_url_final
                    ]);
                }
                $flash_message = "Avaliação enviada com sucesso!";
            } else {
                $errors['auth'] = "Erro: Você não pode avaliar um produto que não comprou.";
            }
        } catch (PDOException $e) {
            $errors['db_save'] = "Erro ao salvar avaliação: " . $e->getMessage();
        }
    }
}


// ==========================================================
// LÓGICA DE BUSCA (GET)
// ==========================================================
try {
    // --- 2. BUSCAR DADOS DO PEDIDO E VALIDAR PROPRIEDADE ---
    $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = :pedido_id AND usuario_id = :user_id");
    $stmt->execute(['pedido_id' => $pedido_id, 'user_id' => $user_id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        $errors['db'] = "Pedido não encontrado ou acesso negado.";
        $page_alert_message = $errors['db'];
    } else {
        // --- 3. BUSCAR ITENS DO PEDIDO (USANDO pedidos_itens) ---
        // Adicionamos a busca pelo status de avaliação do usuário para cada item
        $stmt_itens = $pdo->prepare("
            SELECT
                ip.quantidade,
                ip.preco_unitario,
                (ip.preco_unitario * ip.quantidade) AS preco_total_calculado,
                prod.nome AS nome_produto,
                prod.imagem_url,
                prod.id AS produto_id,
                (SELECT id FROM avaliacoes_produto WHERE usuario_id = :user_id AND produto_id = prod.id LIMIT 1) AS avaliacao_id_existente
            FROM
                pedidos_itens ip
            JOIN
                produtos prod ON ip.produto_id = prod.id
            WHERE
                ip.pedido_id = :pedido_id
        ");
        $stmt_itens->execute(['pedido_id' => $pedido_id, 'user_id' => $user_id]);
        $itens_pedido = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

        // --- 4. NOVO: BUSCAR HISTÓRICO DE AVALIAÇÕES DESTE USUÁRIO PARA ESTES PRODUTOS ---
        if (!empty($itens_pedido)) {
            $product_ids_in_order = array_column($itens_pedido, 'produto_id');
            $placeholders = implode(',', array_fill(0, count($product_ids_in_order), '?'));

            $sql_hist = "
                SELECT ap.*, p.nome as produto_nome
                FROM avaliacoes_produto ap
                JOIN produtos p ON ap.produto_id = p.id
                WHERE ap.usuario_id = ? AND ap.produto_id IN ($placeholders)
                ORDER BY ap.data_avaliacao DESC
            ";

            $params_hist = array_merge([$user_id], $product_ids_in_order);
            $stmt_hist = $pdo->prepare($sql_hist);
            $stmt_hist->execute($params_hist);
            $historico_avaliacoes = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
        }
    }

} catch (PDOException $e) {
    if (empty($errors['db_save'])) { // Só exibe erro de load se não houver erro de save
        $errors['db'] = "Erro ao carregar detalhes do pedido: " . $e->getMessage();
        $page_alert_message = $errors['db'];
    }
}

/** Funções helper */
function getTimelineStatus($status) {
    $status = strtoupper($status);
    if ($status === 'PEDIDO FEITO' || $status === 'PENDENTE' || $status === 'AGUARDANDO PAGAMENTO' || $status === 'PROCESSANDO') {
        return 1;
    }
    if ($status === 'APROVADO') {
        return 2;
    }
    if ($status === 'EM TRANSPORTE' || $status === 'ENVIADO') {
        return 3;
    }
    if ($status === 'ENTREGUE' || $status === 'CONCLUIDO') {
        return 4;
    }
    return 1;
}
function getStatusClass($status) {
    $status_upper = strtoupper($status);
    if (str_contains($status_upper, 'PENDENTE') || str_contains($status_upper, 'AGUARDANDO')) return 'warning';
    if (str_contains($status_upper, 'APROVADO') || str_contains($status_upper, 'CONCLUIDO') || str_contains($status_upper, 'ENTREGUE')) return 'success';
    if (str_contains($status_upper, 'CANCELADO')) return 'danger';
    if (str_contains($status_upper, 'ENVIADO') || str_contains($status_upper, 'TRANSPORTE')) return 'info';
    return 'default';
}

// --- CALCULO DO PROGRESSO PARA INJEÇÃO CSS ---
if ($pedido) {
    $current_step = getTimelineStatus($pedido['status']);
    $progress_percent = min(100, (($current_step - 1) / 3.85) * 100);
    $formatted_percent = rtrim(rtrim(number_format($progress_percent, 4, '.', ''), '0'), '.');

    // Flag de Pedido Entregue
    $pedido_entregue = (strtoupper($pedido['status']) === 'ENTREGUE' || strtoupper($pedido['status']) === 'CONCLUIDO');
} else {
    $formatted_percent = 0;
    $pedido_entregue = false;
}

// Pega mensagens flash (se houver, após o POST)
if (!empty($flash_message)) {
    $page_alert_message = $flash_message;
} elseif (!empty($errors)) {
    $page_alert_message = implode("<br>", $errors);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do Pedido #<?php echo $pedido_id; ?> - Minha Loja</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">
    <style>
        /* ==========================================================
           CSS ESPECÍFICO DESTA PÁGINA (Detalhes do Pedido e Timeline)
           ========================================================== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0 25px 0;
            padding-bottom: 10px;
        }
        .page-header h1 {
            font-size: 1.8em;
            color: var(--text-color-dark);
            margin: 0;
        }
        .status-badge {
            font-size: 1em;
            font-weight: bold;
            padding: 5px 15px;
            border-radius: 20px;
            text-transform: uppercase;
            color: #fff;
        }
        .status-badge.success { background-color: var(--success-color); }
        .status-badge.warning { background-color: #ffc107; color: var(--text-color-dark); }
        .status-badge.danger { background-color: var(--error-color); }
        .status-badge.info { background-color: #17a2b8; }
        .status-badge.default { background-color: var(--text-color-medium); }

        /* --- Timeline --- */
        .status-timeline {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px 10px;
            margin-bottom: 25px;
            border: 1px solid var(--border-color-light);
            border-radius: 5px;
            background-color: #fcfcfc;
            position: relative;
            overflow-x: auto;
            min-height: 80px;
        }
        .status-timeline::before {
            content: '';
            position: absolute;
            top: 41px;
            left: 12%;
            width: 74%;
            height: 4px;
            background-color: var(--border-color-medium);
            z-index: 1;
        }

        .timeline-step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 2;
            min-width: 100px;
            padding-top: 15px;
            top: 7px;
        }
        .step-icon {
            width: 30px;
            height: 30px;
            background-color: #fff;
            border: 4px solid var(--border-color-medium);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            color: var(--text-color-medium);
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
        }
        .step-icon svg { width: 16px; height: 16px; stroke-width: 2.5; }
        .step-text {
            font-size: 0.85em;
            font-weight: normal;
            color: var(--text-color-light);
            line-height: 1.2;
            display: block;
            margin-top: 24px;
            position: relative;
            left: 0;
        }
        .timeline-step.complete .step-icon {
            background-color: var(--green-accent);
            border-color: var(--green-accent);
            color: #fff;
        }
        .timeline-step.complete .step-text {
            font-weight: bold;
            color: var(--text-color-dark);
        }
        .timeline-step.active .step-icon {
            border-color: var(--green-accent);
            transform: translateX(-50%) scale(1.1);
            box-shadow: 0 0 0 5px rgba(154, 215, 0, 0.3);
        }
        .status-timeline .progress-bar-fill {
            content: '';
            position: absolute;
            top: 41px;
            left: 12%;
            height: 4px;
            background-color: var(--green-accent);
            z-index: 1;
            transition: width 0.6s ease;
            width: calc(var(--timeline-progress) - 20px);
        }

        /* --- Layout dos Detalhes --- */
        .detail-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            align-items: flex-start;
        }
        .detail-card {
            background-color: #fff;
            border: 1px solid var(--border-color-medium);
            border-radius: 5px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .detail-card h2 {
            font-size: 1.2em;
            font-weight: bold;
            color: var(--text-color-dark);
            margin-top: 0;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color-light);
        }
        .item-list { display: flex; flex-direction: column; gap: 15px; }
        .item-row {
            display: flex;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px dashed var(--border-color-light);
            align-items: flex-start; /* Alinha topo */
            flex-wrap: wrap;
        }
        .item-row:last-child { border-bottom: none; }
        .item-img { width: 60px; height: 60px; border: 1px solid var(--border-color-light); border-radius: 4px; object-fit: contain; }
        .item-info { flex-grow: 1; }
        .item-info .name { font-size: 1em; font-weight: bold; color: var(--text-color-dark); margin-bottom: 3px; display: block; }
        .item-info .qty-price { font-size: 0.9em; color: var(--text-color-medium); }
        .item-total-price { font-size: 1em; font-weight: bold; color: var(--text-color-dark); }
        .detail-card.observacoes p { font-style: italic; color: var(--text-color-medium); line-height: 1.5; margin: 0; white-space: pre-wrap; }
        .detail-card.observacoes .no-obs { color: var(--text-color-light); }
        .summary-block { padding: 15px 0; }
        .summary-block h3 { font-size: 1.1em; font-weight: bold; color: var(--text-color-dark); margin-top: 0; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px dotted var(--border-color-light); }
        .summary-block p { font-size: 0.9em; color: var(--text-color-medium); line-height: 1.5; margin: 5px 0; }
        .summary-block strong { color: var(--text-color-dark); font-weight: bold; }
        .summary-block .address-line { display: block; }
        .summary-totals { margin-top: 20px; border-top: 1px solid var(--border-color-medium); padding-top: 15px; }
        .summary-totals .total-row { display: flex; justify-content: space-between; font-size: 1em; color: var(--text-color-medium); margin-bottom: 8px; }
        .summary-totals .total-row.grand-total { font-size: 1.3em; font-weight: bold; color: var(--text-color-dark); margin-top: 15px; }
        .summary-totals .total-row span:last-child { font-weight: bold; }
        .detail-actions { margin-top: 25px; display: flex; gap: 10px; }
        .detail-actions .btn { min-width: auto; }
        .general-error { color: var(--error-color); font-weight: bold; text-align: center; margin-bottom: 15px; }

        /* ==========================================================
           NOVO: CSS DO CONTAINER DE AVALIAÇÃO (Layout Stacked)
           ========================================================== */

        #review-container {
            margin-top: 20px;
            border-top: 1px solid var(--border-color-medium);
            padding-top: 20px;
        }

        /* O formulário e o histórico agora são .detail-card */
        #review-form-card, #review-history-card {
            margin-top: 0; /* O container já tem espaçamento */
        }

        /* Formulário de Avaliação */
        .review-form .form-group { margin-bottom: 15px; }
        .review-form label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 0.9em; }
        .review-form select,
        .review-form textarea,
        .review-form input[type="text"],
        .review-form input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color-medium);
            border-radius: 4px;
            font-size: 1em;
            box-sizing: border-box;
        }
        .review-form textarea { min-height: 80px; resize: vertical; }

        /* Estilo das Estrelas (Input) */
        .star-rating-input {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            font-size: 1.8em;
            margin-bottom: 10px;
        }
        .star-rating-input input[type="radio"] {
            display: none;
        }
        .star-rating-input label {
            color: #ddd;
            cursor: pointer;
            transition: color 0.2s ease;
            padding: 0 2px;
            font-size: 1.2em;
        }
        .star-rating-input:hover label { color: orange; }
        .star-rating-input label:hover ~ label { color: orange; }
        .star-rating-input input[type="radio"]:checked ~ label { color: orange; }

        /* Novo: Input de Arquivo/Colar */
        .file-paste-area {
            border: 2px dashed var(--border-color-medium);
            border-radius: 5px;
            padding: 20px;
            text-align: center;
            background-color: #fcfcfc;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .file-paste-area:hover { background-color: var(--border-color-light); }
        .file-paste-area.dragging { background-color: var(--cor-acento-fundo-hover); border-color: var(--green-accent); }
        .file-paste-area p { margin: 0; color: var(--text-color-medium); }
        #review-image-preview { max-width: 150px; max-height: 150px; margin-top: 10px; border-radius: 4px; }

        /* Histórico de Avaliações */
        .review-history-list .avaliacao-item {
            border: 1px solid var(--border-color-light);
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
            background: #fff;
        }
        .review-history-list .avaliacao-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; gap: 10px; }
        .review-history-list .avaliador-info { display: flex; align-items: center; gap: 8px; }
        .review-history-list .avaliador-avatar { width: 25px; height: 25px; background: var(--border-color-light); border-radius: 50%; display:flex; align-items:center; justify-content:center; }
        .review-history-list .avaliador-avatar svg { width: 16px; height: 16px; fill: var(--text-color-medium); }
        .review-history-list .avaliador-nome-data strong { font-size: 0.9em; font-weight: 700; color: var(--text-color-dark); }
        .review-history-list .avaliacao-date { font-size: 0.8em; color: var(--text-color-light); }
        .review-history-list .avaliacao-rating { font-size: 1.1em; color: orange; }
        .review-history-list .avaliacao-comentario { font-size: 0.9em; color: var(--text-color-medium); margin: 5px 0; }
        .review-history-list .avaliacao-foto img { max-width: 80px; max-height: 80px; border-radius: 4px; margin-top: 5px; }

        /* --- Responsividade (Ajustes para Avaliação) --- */
        @media (max-width: 992px) {
            .detail-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: flex-start; margin-top: 0; }
            .item-row { flex-direction: column; align-items: flex-start; }
            .item-total-price { order: 0; align-self: flex-end; }
            .item-row > img { order: 1; }
            .item-info { order: 2; width: 100%; }
            .status-timeline .progress-bar-fill {
                content: '';
                position: absolute;
                top: 41px;
                left: 18%;
                height: 4px;
                background-color: var(--green-accent);
                z-index: 1;
                transition: width 0.6sease;
                width: calc(var(--timeline-progress) - 20px);
            }
            .status-timeline::before {
                content: '';
                position: absolute;
                top: 41px;
                left: 12%;
                width: 77%;
                height: 4px;
                background-color: var(--border-color-medium);
                z-index: 1;
            }
            .step-text {
                font-size: 10px;
                font-weight: normal;
                color: var(--text-color-light);
                line-height: 1.2;
                display: block;
                margin-top: 24px;
                position: relative;
                left: 0;
            }
            .status-timeline {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding: 20px 10px;
                margin-bottom: 25px;
                border: 1px solid var(--border-color-light);
                border-radius: 5px;
                background-color: #fcfcfc;
                position: relative;
                overflow-x: auto;
                min-height: 80px;
                margin-left: -32px;
            }
        }
    </style>
</head>
<body class="checkout-page">

    <?php include 'templates/header.php'; ?>

    <main class="main-content container">
        <?php if ($pedido): ?>

        <div class="page-header">
            <h1>Detalhes do Pedido #<?php echo $pedido['id']; ?></h1>
            <span class="status-badge <?php echo getStatusClass($pedido['status']); ?>">
                <?php echo htmlspecialchars($pedido['status']); ?>
            </span>
        </div>

        <div class="status-timeline" style="--timeline-progress: <?php echo $formatted_percent; ?>%;">
            <div class="progress-bar-fill"></div>
            <div class="timeline-step <?php echo $current_step >= 1 ? 'complete' : ''; ?> <?php echo $current_step == 1 ? 'active' : ''; ?>">
                <div class="step-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z"/></svg></div>
                <span class="step-text">Pedido Feito</span>
            </div>
            <div class="timeline-step <?php echo $current_step >= 2 ? 'complete' : ''; ?> <?php echo $current_step == 2 ? 'active' : ''; ?>">
                <div class="step-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></div>
                <span class="step-text">Pagamento Aprovado</span>
            </div>
            <div class="timeline-step <?php echo $current_step >= 3 ? 'complete' : ''; ?> <?php echo $current_step == 3 ? 'active' : ''; ?>">
                <div class="step-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg></div>
                <span class="step-text">Em Transporte</span>
            </div>
            <div class="timeline-step <?php echo $current_step >= 4 ? 'complete' : ''; ?> <?php echo $current_step == 4 ? 'active' : ''; ?>">
                <div class="step-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                <span class="step-text">Pedido Entregue</span>
            </div>
        </div>

        <div class="detail-layout">

            <section>
                <div class="detail-card">
                    <h2>Itens do Pedido</h2>
                    <?php if (!empty($itens_pedido)): ?>
                    <div class="item-list">
                        <?php
                        foreach ($itens_pedido as $item):
                            $preco_total_item = $item['preco_total_calculado'];
                        ?>
                        <div class="item-row">
                            <img src="<?php echo htmlspecialchars($item['imagem_url'] ?? 'assets/placeholder.jpg'); ?>" alt="<?php echo htmlspecialchars($item['nome_produto']); ?>" class="item-img">

                            <div class="item-info">
                                <a href="produto_detalhe.php?id=<?php echo $item['produto_id']; ?>" class="name"><?php echo htmlspecialchars($item['nome_produto']); ?></a>
                                <span class="qty-price">
                                    <?php echo $item['quantidade']; ?> x R$ <?php echo number_format($item['preco_unitario'], 2, ',', '.'); ?>
                                </span>
                            </div>

                            <span class="item-total-price">R$ <?php echo number_format($preco_total_item, 2, ',', '.'); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                        <p style="color: var(--error-color);">Nenhum item encontrado para este pedido.</p>
                    <?php endif; ?>
                </div>

                <div class="detail-card observacoes">
                    <h2>Observações do Cliente</h2>
                    <?php if (!empty($pedido['observacoes'])): ?>
                        <p><?php echo nl2br(htmlspecialchars($pedido['observacoes'])); ?></p>
                    <?php else: ?>
                        <p class="no-obs">O cliente não adicionou observações.</p>
                    <?php endif; ?>
                </div>
                <div class="detail-actions">
                    <a href="pedidos.php" class="btn btn-secondary">Voltar para Meus Pedidos</a>

                    <?php if ($pedido['status'] == 'PENDENTE' && strtoupper($pedido['pag_nome']) == 'PIX'): ?>

                        <a href="checkout.php?reopen_pix=<?php echo $pedido['id']; ?>" class="btn btn-primary">Gerar 2ª Via PIX</a>

                    <?php elseif (in_array(strtoupper($pedido['status']), ['EM TRANSPORTE', 'ENTREGUE'])): ?>

                        <a href="rastreio.php" class="btn btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-truck" viewBox="0 0 16 16">
                                <path d="M0 3.5A1.5 1.5 0 0 1 1.5 2h9A1.5 1.5 0 0 1 12 3.5V5h1.02a1.5 1.5 0 0 1 1.17.563l1.481 1.85a1.5 1.5 0 0 1 .329.938V10.5a1.5 1.5 0 0 1-1.5 1.5H14a2 2 0 1 1-4 0H5a2 2 0 1 1-3.998-.085A1.5 1.5 0 0 1 0 10.5zm1.294 7.456A2 2 0 0 1 4.732 11h5.536a2 2 0 0 1 .732-.732V3.5a.5.5 0 0 0-.5-.5h-9a.5.5 0 0 0-.5.5v7a.5.5 0 0 0 .294.456M12 10a2 2 0 0 1 1.732 1h.768a.5.5 0 0 0 .5-.5V8.35a.5.5 0 0 0-.11-.312l-1.48-1.85A.5.5 0 0 0 13.02 6H12zm-9 1a1 1 0 1 0 0 2 1 1 0 0 0 0-2m9 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2"/>
                            </svg>
                            Acompanhar Rastreio
                        </a>

                    <?php endif; ?>
                </div>

                <?php if ($pedido_entregue): ?>
                <section id="review-container">

                    <div class="detail-card" id="review-form-card">
                        <h2>Deixe sua avaliação</h2>
                        <form action="detalhes_pedidos.php?id=<?php echo $pedido_id; ?>" method="POST" class="review-form" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="produto_id">Qual produto você quer avaliar?</label>
                                <select id="produto_id" name="produto_id" required>
                                    <option value="">-- Selecione o item --</option>
                                    <?php foreach ($itens_pedido as $item): ?>
                                        <option value="<?php echo $item['produto_id']; ?>">
                                            <?php echo htmlspecialchars($item['nome_produto']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Sua Nota (1 a 5 estrelas):</label>
                                <div class="star-rating-input">
                                    <input type="radio" id="star5" name="classificacao" value="5" /><label for="star5" title="5 estrelas">★</label>
                                    <input type="radio" id="star4" name="classificacao" value="4" /><label for="star4" title="4 estrelas">★</label>
                                    <input type="radio" id="star3" name="classificacao" value="3" /><label for="star3" title="3 estrelas">★</label>
                                    <input type="radio" id="star2" name="classificacao" value="2" /><label for="star2" title="2 estrelas">★</label>
                                    <input type="radio" id="star1" name="classificacao" value="1" required /><label for="star1" title="1 estrela">★</label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="comentario">Seu comentário (ou cole um print aqui):</label>
                                <textarea id="comentario" name="comentario" rows="4" placeholder="O que você achou do produto? Você também pode colar (Ctrl+V) uma imagem aqui."></textarea>
                            </div>

                            <div class="form-group">
                                <label for="foto_arquivo">Ou envie uma foto do seu dispositivo:</label>
                                <div class="file-paste-area" id="file-drop-area">
                                    <p>Arraste uma foto aqui, ou clique para selecionar.</p>
                                    <img id="review-image-preview" src="" alt="Preview da Imagem" style="display: none;">
                                </div>
                                <input type="file" id="foto_arquivo" name="foto_arquivo" accept="image/*" style="display: none;">
                                <input type="hidden" name="foto_url_colada" id="foto_url_colada">
                            </div>

                            <button type="submit" name="submit_avaliacao" class="btn btn-primary">Enviar Avaliação</button>
                        </form>
                    </div>

                    <div class="detail-card" id="review-history-card">
                        <h3>Suas Avaliações (deste pedido)</h3>
                        <div class="review-history-list">
                            <?php if (empty($historico_avaliacoes)): ?>
                                <p style="color: var(--text-color-medium);">Você ainda não avaliou nenhum item deste pedido.</p>
                            <?php else: ?>
                                <?php foreach ($historico_avaliacoes as $hist_aval): ?>
                                <div class="avaliacao-item">
                                    <div class="avaliacao-header">
                                        <div class="avaliador-info">
                                            <div class="avaliador-avatar">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.615-7.812-1.7a.75.75 0 0 1-.437-.695Z" clip-rule="evenodd" /></svg>
                                            </div>
                                            <div class="avaliador-nome-data">
                                                <strong><?php echo htmlspecialchars($hist_aval['produto_nome']); ?></strong>
                                            </div>
                                        </div>
                                        <span class="avaliacao-date"><?php echo (new DateTime($hist_aval['data_avaliacao']))->format('d/m/Y'); ?></span>
                                    </div>
                                    <div class="avaliacao-rating">
                                        <?php
                                            $classif = (int)$hist_aval['classificacao'];
                                            for ($i = 0; $i < $classif; $i++) { echo '<span class="stars">★</span>'; }
                                            for ($i = 0; $i < (5 - $classif); $i++) { echo '<span class="stars" style="opacity: 0.3;">★</span>'; }
                                        ?>
                                    </div>
                                    <p class="avaliacao-comentario"><?php echo htmlspecialchars($hist_aval['comentario'] ?? 'Sem comentário.'); ?></p>
                                    <?php if (!empty($hist_aval['foto_url'])): ?>
                                        <div class="avaliacao-foto">
                                            <img src="<?php echo htmlspecialchars($hist_aval['foto_url']); ?>" alt="Foto da Avaliação">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </section>
                <?php endif; ?>
                </section>


            <aside class="detail-card">
                <h2>Resumo da Transação</h2>
                <div class="summary-block">
                    <h3>Totais</h3>
                    <div class="summary-totals">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <span>R$ <?php echo number_format($pedido['valor_subtotal'], 2, ',', '.'); ?></span>
                        </div>
                        <div class="total-row">
                            <span>Frete (<?php echo htmlspecialchars($pedido['envio_nome']); ?>)</span>
                            <span>R$ <?php echo number_format($pedido['valor_frete'], 2, ',', '.'); ?></span>
                        </div>
                        <div class="total-row grand-total">
                            <span>Total pago</span>
                            <span>R$ <?php echo number_format($pedido['valor_total'], 2, ',', '.'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="summary-block">
                    <h3>Endereço de Entrega</h3>
                    <p>
                        <strong><?php echo htmlspecialchars($pedido['endereco_destinatario']); ?></strong><br>
                        <span class="address-line"><?php echo htmlspecialchars($pedido['endereco_logradouro']); ?>, <?php echo htmlspecialchars($pedido['endereco_numero']); ?> <?php echo $pedido['endereco_complemento'] ? ' - ' . htmlspecialchars($pedido['endereco_complemento']) : ''; ?></span>
                        <span class="address-line"><?php echo htmlspecialchars($pedido['endereco_bairro']); ?> - <?php echo htmlspecialchars($pedido['endereco_cidade']); ?>/<?php echo htmlspecialchars($pedido['endereco_estado']); ?></span>
                        <span class="address-line">CEP: <?php echo htmlspecialchars($pedido['endereco_cep']); ?></span>
                    </p>
                </div>

                <div class="summary-block">
                    <h3>Envio e Prazo</h3>
                    <p>
                        <strong>Método:</strong> <?php echo htmlspecialchars($pedido['envio_nome']); ?><br>
                        <strong>Estimativa:</strong> <?php echo htmlspecialchars($pedido['envio_prazo_dias']); ?> dias úteis
                    </p>
                </div>

                <div class="summary-block">
                    <h3>Pagamento</h3>
                    <p>
                        <strong>Método:</strong> <?php echo htmlspecialchars($pedido['pag_nome']); ?><br>
                        <?php if (strtoupper($pedido['status']) == 'PENDENTE'): ?>
                            <strong style="color: var(--warning-color);">Aguardando processamento/confirmação.</strong>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="summary-block" style="border-top: 1px dotted var(--border-color-light); padding-top: 15px;">
                    <h3>Detalhes do Pedido</h3>
                    <p>
                        <strong>ID do Pedido:</strong> #<?php echo $pedido['id']; ?><br>
                        <strong>Data da Compra:</strong> <?php echo date('d/m/Y H:i', strtotime($pedido['criado_em'])); ?>
                    </p>
                </div>

            </aside>

        </div>

        <?php else: ?>

            <div class="data-card" style="text-align: center; padding: 60px 20px; margin: 20px auto; max-width: 600px;">
                <h2>Pedido Não Encontrado</h2>
                <?php if (isset($errors['db'])): ?>
                    <p class="general-error"><?php echo $errors['db']; ?></p>
                <?php else: ?>
                    <p>Verifique o ID informado ou entre em contato com o suporte.</p>
                <?php endif; ?>
                <a href="pedidos.php" class="btn btn-primary" style="border-radius: 4px; padding: 12px 30px;">Ver Meus Pedidos</a>
            </div>

        <?php endif; ?>
    </main>

    <?php include 'templates/footer.php'; ?>

    <div class="modal-overlay" id="login-modal">
           <div class="modal-content">
                 <div class="modal-header">
                       <h3 class="modal-title">Identifique-se</h3>
                       <button class="modal-close" data-dismiss="modal">&times;</button>
                 </div>
                 <div class="modal-body">
                       <form action="login.php" method="POST">
                             <div class="form-group">
                                   <label for="modal_login_email_header_detalhes">E-mail ou CPF/CNPJ:</label>
                                   <input type="text" id="modal_login_email_header_detalhes" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                             </div>
                             <div class="form-group">
                                   <label for="modal_login_senha_header_detalhes">Senha:</label>
                                   <input type="password" id="modal_login_senha_header_detalhes" name="modal_login_senha" placeholder="Digite sua senha" required>
                             </div>
                             <div class="modal-footer">
                                   <button type="submit" class="btn btn-primary">Continuar</button>
                             </div>
                       </form>
                 </div>
          </div>
    </div>

    <div class="modal-overlay" id="info-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Atenção</h3>
                <button class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p id="info-modal-message"></p>
            </div>
            <div class="modal-footer" style="text-align: right;">
                <button class="btn btn-primary modal-close" data-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="delete-confirm-modal">
           <div class="modal-content">
             </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        $(document).ready(function(){
            // (Lógica de Modal de Erro de DB ou Sucesso de Avaliação)
            <?php if (!empty($page_alert_message)): ?>
                 const infoModal = document.getElementById('info-modal');
                 const infoModalMessage = document.getElementById('info-modal-message');
                 if (infoModal && infoModalMessage) {
                    infoModalMessage.innerHTML = <?php echo json_encode($page_alert_message); ?>;
                    infoModal.classList.add('modal-open');
                 }
            <?php endif; ?>

            // --- LÓGICA DO FORMULÁRIO DE AVALIAÇÃO (Upload e Paste) ---

            const dropArea = document.getElementById('file-drop-area');
            const fileInput = document.getElementById('foto_arquivo');
            const pasteTextarea = document.getElementById('comentario');
            const hiddenUrlInput = document.getElementById('foto_url_colada');
            const previewImg = document.getElementById('review-image-preview');

            // 1. Abrir seletor de arquivo ao clicar na área
            if (dropArea) {
                dropArea.addEventListener('click', () => {
                    fileInput.click();
                });
            }

            // 2. Lidar com arquivo selecionado
            if (fileInput) {
                fileInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        handleFile(this.files[0]);
                    }
                });
            }

            // 3. Lidar com "Arrastar e Soltar" (Drag and Drop)
            if (dropArea) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, preventDefaults, false);
                });
                ['dragenter', 'dragover'].forEach(eventName => {
                    dropArea.addEventListener(eventName, () => dropArea.classList.add('dragging'), false);
                });
                ['dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, () => dropArea.classList.remove('dragging'), false);
                });

                dropArea.addEventListener('drop', (e) => {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    if (files && files[0]) {
                        handleFile(files[0]);
                        // Sincroniza o arquivo solto com o input[type=file]
                        fileInput.files = files;
                    }
                }, false);
            }

            // 4. Lidar com "Colar" (Paste) no Textarea
            if (pasteTextarea) {
                pasteTextarea.addEventListener('paste', function(e) {
                    const items = (e.clipboardData || e.originalEvent.clipboardData).items;
                    for (const item of items) {
                        if (item.type.indexOf('image') !== -1) {
                            const blob = item.getAsFile();

                            // AVISO: Upload imediato via AJAX (Fetch)
                            // Para o paste funcionar, precisamos de um endpoint (api/upload_review_image.php)
                            // Este script fará o upload e preencherá o campo 'foto_url_colada'
                            uploadPastedImage(blob);

                            e.preventDefault(); // Impede que a imagem seja colada como binário no textarea
                            break;
                        }
                    }
                });
            }

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            // Função para mostrar o preview
            function handleFile(file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewImg.style.display = 'block';
                    dropArea.querySelector('p').textContent = 'Imagem selecionada!';
                    // Limpa o campo de URL colada se um arquivo for selecionado
                    hiddenUrlInput.value = '';
                }
                reader.readAsDataURL(file);
            }

            // Função de Upload (AJAX) para Imagens Coladas (Prints)
            async function uploadPastedImage(blob) {
                const formData = new FormData();
                // Gera um nome de arquivo (ex: print.png)
                formData.append('pasted_image', blob, 'print.png');

                // ATENÇÃO: Você precisa criar este arquivo 'api/upload_review_image.php'
                const apiEndpoint = 'api/upload_review_image.php';

                try {
                    dropArea.querySelector('p').textContent = 'Enviando print...';

                    const response = await fetch(apiEndpoint, {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.status === 'success' && data.url) {
                        hiddenUrlInput.value = data.url; // Salva a URL no campo oculto
                        previewImg.src = data.url; // Mostra o preview
                        previewImg.style.display = 'block';
                        dropArea.querySelector('p').textContent = 'Print colado e enviado!';
                        fileInput.value = ''; // Limpa o seletor de arquivo
                    } else {
                        alert('Erro ao enviar o print: ' + data.message);
                        dropArea.querySelector('p').textContent = 'Erro ao colar. Tente enviar pelo seletor.';
                    }
                } catch (error) {
                    console.error('Erro no upload da imagem colada:', error);
                    alert('Erro de conexão ao enviar o print.');
                    dropArea.querySelector('p').textContent = 'Erro de conexão.';
                }
            }

            // NOVO: JS para destacar o produto selecionado no formulário de avaliação
            $('#produto_id').on('change', function() {
                var selectedProductId = $(this).val();

                // Limpa destaques anteriores
                $('.item-row').css('background-color', 'transparent');

                if (selectedProductId) {
                    // Encontra a linha do item correspondente (assumindo que o link do produto contém o ID)
                    var $itemRow = $('.item-info a.name[href*="produto_detalhe.php?id=' + selectedProductId + '"]').closest('.item-row');
                    if ($itemRow.length) {
                        $itemRow.css('background-color', '#f0f9e6'); // Cor de destaque
                    }
                }
            });

         });
    </script>

</body>
</html>