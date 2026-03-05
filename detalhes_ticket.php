<?php
// detalhes_ticket.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 1. PROTEÇÃO DA PÁGINA E OBTENÇÃO DO ID ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    $_SESSION['redirect_url'] = basename($_SERVER['REQUEST_URI']);
    header('Location: login.php');
    exit;
}

require_once 'config/db.php';
require_once 'funcoes.php';

// [Helpers]
if (!function_exists('formatCurrency')) {
    function formatCurrency($value) {
        $numericValue = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($numericValue === false) { $numericValue = 0.00; }
        return 'R$ ' . number_format($numericValue, 2, ',', '.');
    }
}
// Assume que 'carregarConfigApi' e 'formatarDataHoraBR' existem em 'funcoes.php'
if (function_exists('carregarConfigApi')) {
    if (isset($pdo)) { // Garante que $pdo exista
        carregarConfigApi($pdo);
    }
}

// Assume que 'renderStars' existe em 'funcoes.php' ou é definido em outro lugar,
// pois está sendo usado no HTML.

$user_id = $_SESSION['user_id'];
$user_nome = $_SESSION['user_nome'] ?? 'Cliente';
$ticket_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$ticket_id) {
    header('Location: suporte.php?error=not_found');
    exit;
}

// ==========================================================
// LÓGICA DE AÇÕES DO CLIENTE (AJAX E POST)
// ==========================================================

// --- MANIPULADOR AJAX (PARA ENVIAR E RECEBER MENSAGENS) ---
function handleAjaxRequest($pdo, $ticket_id, $user_id) {
    if (isset($_REQUEST['ajax_action'])) {
        header('Content-Type: application/json');
        $action = $_REQUEST['ajax_action'];
        $response = ['success' => false, 'message' => 'Ação inválida.'];

        try { // <-- Início do TRY

            // --- AÇÃO: Cliente envia uma nova resposta (AJAX POST) ---
            if ($action === 'client_reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $nova_mensagem = trim($_POST['nova_mensagem'] ?? '');
                $max_size = 5 * 1024 * 1024; // 5 MB
                $anexo_dados = null; $anexo_nome = null; $anexo_tipo = null;

                if (empty($nova_mensagem) && (!isset($_FILES['anexo']) || $_FILES['anexo']['error'] !== UPLOAD_ERR_OK)) {
                    throw new Exception("A mensagem ou anexo não pode estar vazio.");
                }

                if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['anexo'];
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt'];
                    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                    if ($file['size'] > $max_size) throw new Exception("Arquivo muito grande (Máx. 5MB).");
                    if (!in_array($file_extension, $allowed_extensions)) throw new Exception("Formato de arquivo inválido.");

                    $anexo_dados = file_get_contents($file['tmp_name']);
                    $anexo_nome = $file['name'];
                    $anexo_tipo = $file['type'];
                }

                $stmt_status_id = $pdo->prepare("SELECT id FROM ticket_status WHERE nome = 'ABERTO'");
                $stmt_status_id->execute();
                $status_aberto_id = $stmt_status_id->fetchColumn();

                $pdo->beginTransaction();

                $stmt_msg = $pdo->prepare("
                    INSERT INTO ticket_respostas
                        (ticket_id, usuario_id, mensagem, anexo_nome, anexo_tipo, anexo_dados, data_resposta)
                    VALUES
                        (?, ?, ?, ?, ?, ?, NOW())
                ");

                $stmt_msg->bindParam(1, $ticket_id, PDO::PARAM_INT);
                $stmt_msg->bindParam(2, $user_id, PDO::PARAM_INT);
                $stmt_msg->bindParam(3, $nova_mensagem, $nova_mensagem ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt_msg->bindParam(4, $anexo_nome, $anexo_nome ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt_msg->bindParam(5, $anexo_tipo, $anexo_tipo ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt_msg->bindParam(6, $anexo_dados, $anexo_dados ? PDO::PARAM_LOB : PDO::PARAM_NULL);
                $stmt_msg->execute();
                $new_message_id = $pdo->lastInsertId();

                $sql_update = "UPDATE tickets SET status_id = ?, ultima_atualizacao = NOW(), admin_ultima_visualizacao = NULL
                               WHERE id = ? AND usuario_id = ?";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([$status_aberto_id, $ticket_id, $user_id]);

                $pdo->commit();

                $stmt_new = $pdo->prepare("
                    SELECT m.id, m.mensagem, m.data_resposta, m.anexo_nome, m.anexo_tipo, m.anexo_dados,
                            u.nome AS remetente_nome
                    FROM ticket_respostas m
                    JOIN usuarios u ON m.usuario_id = u.id
                    WHERE m.id = ?
                ");
                $stmt_new->execute([$new_message_id]);
                $new_message_data = $stmt_new->fetch(PDO::FETCH_ASSOC);

                if ($new_message_data) {
                    $blob_data = $new_message_data['anexo_dados'];
                    if (is_resource($blob_data)) $blob_data = stream_get_contents($blob_data);
                    if ($blob_data) $new_message_data['anexo_dados'] = base64_encode($blob_data);

                    $new_message_data['data_resposta_br'] = formatarDataHoraBR($new_message_data['data_resposta']);
                    $new_message_data['tipo'] = 'client';
                }
                $response = ['success' => true, 'message' => $new_message_data, 'last_id' => $new_message_id];

            } // <-- Fechamento da ação client_reply

            // --- AÇÃO: Cliente busca novas mensagens (Polling - GET) ---
            elseif ($action === 'check_new_messages') {
                $last_id = (int)($_GET['last_id'] ?? 0);

                // IMPORTANTE: Busca apenas mensagens do ADMIN (u.tipo = 'admin')
                $stmt_msg = $pdo->prepare("
                    SELECT m.id, m.mensagem, m.data_resposta, m.anexo_nome, m.anexo_tipo, m.anexo_dados,
                            u.nome AS remetente_nome, u.tipo AS tipo_usuario
                    FROM ticket_respostas m
                    JOIN usuarios u ON m.usuario_id = u.id
                    WHERE m.ticket_id = ? AND m.id > ? AND u.tipo = 'admin'
                    ORDER BY m.data_resposta ASC
                ");
                $stmt_msg->execute([$ticket_id, $last_id]);

                $new_messages = [];
                $new_last_id = $last_id;

                while ($row = $stmt_msg->fetch(PDO::FETCH_ASSOC)) {
                    $blob_data = $row['anexo_dados'];
                    // CORREÇÃO: Verifica se não é nulo antes de tentar ler o BLOB/Recurso
                    if (!empty($blob_data)) {
                        if (is_resource($blob_data)) $blob_data = stream_get_contents($blob_data);
                        $row['anexo_dados'] = base64_encode($blob_data);
                    } else {
                        $row['anexo_dados'] = null;
                    }

                    $row['data_resposta_br'] = formatarDataHoraBR($row['data_resposta']);
                    $row['tipo'] = 'admin';
                    $new_messages[] = $row;
                    $new_last_id = $row['id'];
                }

                $response = ['success' => true, 'messages' => $new_messages, 'last_id' => $new_last_id];
            } // <-- Fechamento do elseif

        } catch (Exception $e) { // <-- Captura exceções para retornar JSON de erro
            if ($pdo->inTransaction()) $pdo->rollBack();
            $response['message'] = "Erro AJAX: " . $e->getMessage();
            http_response_code(500);
        }

        echo json_encode($response);
        // CRÍTICO: Impede que o HTML seja enviado com o JSON, resolvendo o erro de Syntax
        exit;
    }
}
// -------------------------------------------------------------------
// FIM DO MANIPULADOR AJAX
// -------------------------------------------------------------------
handleAjaxRequest($pdo, $ticket_id, $user_id); // Passa as variáveis necessárias

// ==========================================================
// LÓGICA POST (NÃO-AJAX: FECHAR E AVALIAR)
// ==========================================================
$errors = [];
$success_message = '';

// --- AÇÃO 2: FECHAR O TICKET (SOLICITADO PELO USUÁRIO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fechar_ticket'])) {
    try {
        $sql_close = "UPDATE tickets SET status_id = 4, ultima_atualizacao = NOW()
                      WHERE id = :tid AND usuario_id = :uid AND status_id != 4";
        $stmt_close = $pdo->prepare($sql_close);
        $stmt_close->execute([':tid' => $ticket_id, ':uid' => $user_id]);

        header('Location: detalhes_ticket.php?id=' . $ticket_id . '&rating=true');
        exit;

    } catch (PDOException $e) {
        $errors['db'] = "Erro ao fechar o ticket: " . $e->getMessage();
    }
}

// --- AÇÃO 3: SALVAR A AVALIAÇÃO DO ATENDIMENTO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_avaliacao'])) {
    $classificacao = filter_input(INPUT_POST, 'classificacao', FILTER_VALIDATE_INT);
    $comentario = trim($_POST['comentario_avaliacao'] ?? '');

    if (empty($classificacao) || $classificacao < 1 || $classificacao > 5) {
        $errors['rating'] = "Por favor, selecione de 1 a 5 estrelas.";
    }

    if (empty($errors)) {
        try {
            $sql_rate = "UPDATE tickets SET
                             atendimento_rating = :rating,
                             atendimento_comentario = :coment,
                             data_avaliacao = NOW()
                           WHERE id = :tid AND usuario_id = :uid";
            $stmt_rate = $pdo->prepare($sql_rate);
            $stmt_rate->execute([
                ':rating' => $classificacao,
                ':coment' => $comentario,
                ':tid' => $ticket_id,
                ':uid' => $user_id
            ]);

            header('Location: detalhes_ticket.php?id=' . $ticket_id . '&status=avaliado');
            exit;

        } catch (PDOException $e) {
            $errors['db'] = "Erro ao salvar sua avaliação: " . $e->getMessage();
        }
    } else {
        $show_rating_modal = true;
    }
}


// --- Lógica de Flash Messages (GET) ---
$show_rating_modal = false; // Inicializa a variável para evitar erro de undefined
if (isset($_GET['status']) && $_GET['status'] == 'enviado') {
    $success_message = "Sua resposta foi enviada com sucesso!";
}
if (isset($_GET['status']) && $_GET['status'] == 'avaliado') {
    $success_message = "Obrigado pela sua avaliação!";
}
if (isset($_GET['rating']) && $_GET['rating'] == 'true') {
    $show_rating_modal = true;
}


// ==========================================================
// LÓGICA GET: BUSCAR TICKET E MENSAGENS (CARGA INICIAL)
// ==========================================================
$ticket = null;
$messages_list = [];
$is_closed = false;
$ja_avaliado = false;
$last_message_id = 0;

try {
    // 1. Busca o ticket principal
    $stmt_ticket = $pdo->prepare("
        SELECT t.*, ts.nome AS status_nome, ts.cor_badge
        FROM tickets t
        JOIN ticket_status ts ON t.status_id = ts.id
        WHERE t.id = :tid AND t.usuario_id = :uid
    ");
    $stmt_ticket->execute(['tid' => $ticket_id, 'uid' => $user_id]);
    $ticket = $stmt_ticket->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        header('Location: suporte.php?error=not_found');
        exit;
    }

    $is_closed = (strtoupper($ticket['status_nome']) === 'FECHADO');
    $ja_avaliado = ($ticket['atendimento_rating'] !== null);

    if ($ja_avaliado) $show_rating_modal = false;

    // 2. Construir o histórico de mensagens
    // 2a. Adicionar a mensagem original do ticket
    $messages_list[] = [
        'id' => 0,
        'nome' => $user_nome,
        'tipo' => 'client',
        'data' => $ticket['data_criacao'],
        'mensagem' => $ticket['mensagem'],
        'anexo_nome' => null, 'anexo_tipo' => null, 'anexo_dados' => null,
    ];
    $last_message_id = 0;

    // 2b. Buscar todas as respostas (incluindo dados BLOB)
    $stmt_respostas = $pdo->prepare("
        SELECT r.id, r.usuario_id, r.mensagem, r.data_resposta, r.anexo_nome, r.anexo_tipo, r.anexo_dados,
               u.nome AS nome_usuario, u.tipo AS tipo_usuario
        FROM ticket_respostas r
        JOIN usuarios u ON r.usuario_id = u.id
        WHERE r.ticket_id = :tid
        ORDER BY r.data_resposta ASC
    ");
    $stmt_respostas->execute(['tid' => $ticket_id]);

    $respostas = [];
    while ($reply = $stmt_respostas->fetch(PDO::FETCH_ASSOC)) {
        // Lógica para ler o BYTEA/BLOB (string)
        if (is_resource($reply['anexo_dados'])) {
            $reply['anexo_dados'] = stream_get_contents($reply['anexo_dados']);
        }
        $respostas[] = $reply;
    }

    // 2c. Mesclar as respostas na lista principal
    foreach ($respostas as $reply) {
        $is_admin = (strtoupper($reply['tipo_usuario']) === 'ADMIN');
        $last_message_id = (int)$reply['id']; // Atualiza o last_id

        $messages_list[] = [
            'id' => $reply['id'],
            'nome' => $reply['nome_usuario'],
            'tipo' => $is_admin ? 'admin' : 'client',
            'data' => $reply['data_resposta'],
            'mensagem' => $reply['mensagem'],
            'anexo_nome' => $reply['anexo_nome'],
            'anexo_tipo' => $reply['anexo_tipo'],
            'anexo_dados' => $reply['anexo_dados']
        ];
    }

} catch (PDOException $e) {
    if (empty($errors['db'])) {
        $errors['db_load'] = "Erro ao carregar detalhes do ticket: " . $e->getMessage();
    }
}

$cor_acento_hover = defined('cor_acento_hover') ? cor_acento_hover : '#8cc600';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $ticket_id; ?> - Suporte</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">


    <style>
        /* ==========================================================
           CSS GERAL DO CLIENTE
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
            /* CORES DO CHAT MODERNO */
            --chat-client-bg: #4a69bd;
            --chat-client-text: #fff;
            --chat-admin-bg: #f5f5f5;
            --chat-admin-text: var(--text-color-dark);
            --chat-meta-color: #999;
        }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f8f8; color: var(--text-color-dark); }
        a { text-decoration: none; color: var(--green-accent); }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 15px; }

        /* --- Layout da Página de Perfil --- */
        .profile-title { font-size: 1.8em; font-weight: bold; color: var(--text-color-dark); margin: 15px 0 25px 0; }
        .profile-layout { display: flex; gap: 30px; align-items: flex-start; }
        .profile-sidebar { width: 260px; flex-shrink: 0; background-color: #fff; border: 1px solid var(--border-color-light); box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: 82px; }
        .profile-sidebar nav ul { list-style: none; padding: 0; margin: 0; }
        .profile-sidebar nav li { border-bottom: 1px solid var(--border-color-light); }
        .profile-sidebar nav li:last-child { border-bottom: none; }
        .profile-sidebar nav a { display: flex; align-items: center; gap: 15px; padding: 18px 20px; font-size: 0.95em; color: var(--text-color-medium); transition: background-color 0.2s ease, color 0.2s ease; }
        .profile-sidebar nav a svg { width: 20px; height: 20px; stroke-width: 2; color: var(--text-color-light); transition: color 0.2s ease; }
        .profile-sidebar nav a:hover { background-color: #fdfdfd; color: var(--green-accent); }
        .profile-sidebar nav a:hover svg { color: var(--green-accent); }
        .profile-sidebar nav a.active { background-color: #f5f5f5; color: var(--text-color-dark); font-weight: bold; }
        .profile-sidebar nav a.active svg { color: var(--text-color-dark); }

        .profile-content { flex-grow: 1; }
        .data-card { background-color: #fff; border: 1px solid var(--border-color-light); box-shadow: 0 2px 5px rgba(0,0,0,0.05); padding: 30px; margin-bottom: 30px; }
        .data-card h2 { font-size: 1.3em; font-weight: bold; color: var(--text-color-dark); margin-top: 0; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color-light); }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin: 15px 0 25px 0; padding-bottom: 10px; }
        .page-header h1 { font-size: 1.8em; color: var(--text-color-dark); margin: 0; }
        .status-badge { font-size: 1em; font-weight: bold; padding: 5px 15px; border-radius: 20px; text-transform: uppercase; color: #fff; background-color: var(--cor-status, #888); }
        .detail-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: flex-start; }
        .detail-card { background-color: #fff; border: 1px solid var(--border-color-medium); border-radius: 5px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .detail-card h2 { font-size: 1.2em; font-weight: bold; color: var(--text-color-dark); margin-top: 0; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color-light); }
        .summary-block p { font-size: 0.9em; color: var(--text-color-medium); line-height: 1.5; margin: 5px 0; }
        .summary-block strong { color: var(--text-color-dark); font-weight: bold;}


        /* --- Chat Timeline (MODERNO) --- */
        .chat-container-wrapper {
            max-height: 500px;
            overflow-y: auto;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color-light);
            border-radius: 8px;
            background: #fdfdfd;
        }

        .chat-timeline {
            display: flex;
            flex-direction: column;
            gap: 15px;
            width: 100%;
        }

        .message-wrapper {
            display: flex;
            max-width: 85%;
            align-items: flex-start;
        }

        /* CLIENTE (USER) - Lado Direito (Nossa cor principal) */
        .message-wrapper.client {
            align-self: flex-end;
            flex-direction: row-reverse;
            margin-left: 15%;
        }

        /* ADMIN - Lado Esquerdo (Cor neutra/secundária) */
        .message-wrapper.admin {
            align-self: flex-start;
            flex-direction: row;
            margin-right: 15%;
        }

        /* Avatar/Ícone */
        .message-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin: 0 10px;
        }
        .message-wrapper.client .message-icon { background-color: var(--header-footer-bg); }
        .message-wrapper.admin .message-icon { background-color: #ddd; }
        .message-icon svg { width: 20px; height: 20px; color: #fff; }
        .message-wrapper.admin .message-icon svg { color: var(--text-color-medium); }


        /* Bolha */
        .message-bubble {
            padding: 10px 15px;
            border-radius: 18px;
            max-width: 100%;
            word-wrap: break-word;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .message-bubble.client {
            background-color: var(--header-footer-bg);
            color: var(--chat-client-text);
            border-bottom-right-radius: 2px;
        }
        .message-bubble.admin {
            background-color: var(--chat-admin-bg);
            color: var(--chat-admin-text);
            border-bottom-left-radius: 2px;
        }

        /* Meta Data (Nome e Data) */
        .message-meta {
            display: block;
            font-size: 0.75em;
            font-weight: 500;
            margin-bottom: 5px;
            line-height: 1.2;
        }
        .message-wrapper.client .message-meta {
             color: rgba(255, 255, 255, 0.8);
             text-align: right;
        }
        .message-wrapper.admin .message-meta {
            color: var(--chat-meta-color);
            text-align: left;
        }
        .message-bubble p {
            margin: 0;
            font-size: 0.9em;
            color: inherit;
            white-space: pre-wrap;
        }
        /* Estilo Anexo */
        .message-bubble .anexo-link {
            display: block;
            margin-top: 5px;
            font-size: 0.8em;
            word-break: break-all;
            color: inherit;
            text-decoration: underline;
        }
        .message-bubble .anexo-image {
            max-width: 100%;
            width: 250px;
            height: auto;
            border-radius: 5px;
            margin-top: 5px;
            cursor: pointer;
        }


        /* --- OUTROS ESTILOS (Formulário, Modal) --- */
        .general-success { background-color: #f0f9eb; border: 1px solid var(--success-color); color: var(--success-color); }
        .general-error { color: var(--error-color); font-weight: bold; background-color: #fff8f8; border: 1px solid var(--error-color);}

        .form-group { margin-bottom: 18px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: bold; font-size: 0.9em; color: var(--text-color-dark); }
        .form-group textarea, .form-group input[type="file"] {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border-color-medium);
            border-radius: 4px; box-sizing: border-box; font-size: 1em; background-color: #fff;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .form-group input[type="file"] { background-color: #f9f9f9; }
        .form-group textarea:focus, .form-group input[type="file"]:focus { border-color: var(--green-accent); box-shadow: 0 0 0 2px rgba(154, 215, 0, 0.2); outline: none; }
        .error-message { color: var(--error-color); font-size: 0.8em; margin-top: 4px; display: block; }
        textarea.error-border { border-color: var(--error-color) !important; }

        .btn-salvar {
            display: inline-block; padding: 10px 25px; border-radius: 4px; font-size: 0.9em;
            font-weight: bold; text-align: center; text-decoration: none; cursor: pointer;
            transition: all 0.3s ease; border: 1px solid var(--green-accent);
            background-color: var(--green-accent); color: #fff;
        }
        .btn-salvar:hover:not(:disabled) {
            background-color: var(--cor-acento-hover);
            border-color: var(--cor-acento-hover);
        }
        .btn-salvar:disabled { background-color: #aaa; border-color: #aaa; cursor: not-allowed; }
        .btn-fechar { background-color: var(--error-color); border-color: var(--error-color); color: #fff; margin-left: 10px; }
        .btn-fechar:hover { background-color: #c82333; border-color: #bd2130; }

        .ticket-closed-notice { text-align: center; padding: 20px; background-color: #f9f9f9; border-radius: 5px; color: var(--text-color-medium); font-weight: bold; }

        /* Modal Base Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-overlay.modal-open { display: flex; }
        .modal-content { background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .modal-header, .modal-body, .modal-footer { padding: 15px 20px; }
        .modal-header { border-bottom: 1px solid var(--border-color-light); }
        .modal-footer { border-top: 1px solid var(--border-color-light); border-bottom: none; }
        .modal-title { font-size: 1.2em; font-weight: 600; margin: 0; }
        .modal-close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: #aaa; float: right; }

        /* --- Estilo das Estrelas (Modal) --- */
        .rating-modal-stars { display: flex; flex-direction: row-reverse; justify-content: center; font-size: 2.5em; margin-bottom: 10px; }
        .rating-modal-stars input[type="radio"] { display: none; }
        .rating-modal-stars label { color: #ddd; cursor: pointer; transition: color 0.2s ease; padding: 0 5px; font-size: 1.2em; }
        .rating-modal-stars:hover label { color: orange; }
        .rating-modal-stars label:hover ~ label { color: orange; }
        .rating-modal-stars input[type="radio"]:checked ~ label { color: orange; }

        /* Responsividade */
        @media (max-width: 992px) {
            .profile-layout { flex-direction: column; }
            .profile-sidebar { width: 100%; margin-top: 20px; }
            .profile-content { width: 100%; }
            .detail-layout { grid-template-columns: 1fr; }
            /* Chat em telas menores */
            .chat-container-wrapper { max-height: 400px; }
            .message-wrapper { max-width: 95%; }
            .message-wrapper.client { margin-left: 5%; }
            .message-wrapper.admin { margin-right: 5%; }
        }
        @media (max-width: 576px) {
            .page-header h1 { font-size: 1.5em; }
            .status-badge { font-size: 0.8em; }
            .detail-card { padding: 15px; }
            .detail-card h2 { font-size: 1.1em; }
            .detail-card form div:nth-last-child(1) {
                flex-direction: column-reverse;
                align-items: stretch;
                gap: 15px;
            }
            .btn-salvar {
                width: 100%;
                margin-left: -1px;
            }
        }
    </style>
</head>
<body class="profile-page">

    <?php include 'templates/header.php'; ?>

    <main class="main-content container">
        <div class="profile-layout">

            <aside class="profile-sidebar">
                <nav>
                    <ul>
                        <li><a href="perfil.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg><span>Minha conta</span></a></li>
                        <li><a href="pedidos.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg><span>Meus pedidos</span></a></li>
                        <li><a href="meus-dados.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4z" /></svg><span>Meus dados</span></a></li>
                        <li><a href="enderecos.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg><span>Meus endereços</span></a></li>
                        <li><a href="lista-desejos.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg><span>Lista de desejos</span></a></li>
                        <li><a href="suporte.php" class="active"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg><span>Suporte</span></a></li>
                        <li><a href="alterar-senha.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 01-2-2v-6a2 2 0 01-2-2H6a2 2 0 01-2 2v6a2 2 0 012 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg><span>Alterar senha</span></a></li>
                        <li><a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg><span>Sair</span></a></li>
                    </ul>
                </nav>
            </aside>

            <section class="profile-content">

                <?php if ($ticket): ?>
                    <div class="page-header">
                        <h1>Ticket #<?php echo $ticket['id']; ?></h1>
                        <span class="status-badge" style="--cor-status: <?php echo htmlspecialchars($ticket['cor_badge']); ?>;">
                            <?php echo htmlspecialchars($ticket['status_nome']); ?>
                        </span>
                    </div>

                    <?php if ($success_message): ?>
                        <div class="general-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($errors)): // Exibe qualquer erro de POST ou DB ?>
                        <div class="general-error">
                            <?php
                                echo htmlspecialchars($errors['db_load'] ?? $errors['db'] ?? $errors['upload'] ?? $errors['resposta'] ?? 'Ocorreu um erro.');
                            ?>
                        </div>
                    <?php endif; ?>

                    <div class="detail-layout">
                        <section>
                            <div class="detail-card">
                                <h2>Histórico da Conversa</h2>
                                <div class="chat-container-wrapper" id="chat-container-wrapper">
                                    <div class="chat-timeline" id="chat-timeline-messages">

                                        <?php foreach ($messages_list as $msg):
                                             $is_admin = $msg['tipo'] === 'admin';
                                             $wrapper_class = $is_admin ? 'admin' : 'client'; // Cliente na direita
                                             $icon_svg = $is_admin ?
                                                 '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"></path></svg>' :
                                                 '<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>';
                                         ?>
                                             <div class="message-wrapper <?php echo $wrapper_class; ?>">
                                                 <div class="message-icon"><?php echo $icon_svg; ?></div>
                                                 <div class="message-bubble <?php echo $wrapper_class; ?>">
                                                     <span class="message-meta">
                                                         <?php echo htmlspecialchars($msg['nome']); ?>
                                                         <span class="date">(<?php echo formatarDataHoraBR($msg['data']); ?>)</span>
                                                     </span>

                                                     <p><?php echo nl2br(htmlspecialchars($msg['mensagem'])); ?></p>

                                                     <?php if (!empty($msg['anexo_dados'])):
                                                         $anexo_data_base64 = base64_encode($msg['anexo_dados']);
                                                         $anexo_tipo = htmlspecialchars($msg['anexo_tipo']);
                                                         $anexo_nome = htmlspecialchars($msg['anexo_nome']);
                                                         $dataUri = "data:$anexo_tipo;base64,$anexo_data_base64";
                                                         $isImage = strpos($anexo_tipo, 'image/') === 0;
                                                     ?>
                                                         <?php if ($isImage): ?>
                                                             <a href="<?php echo $dataUri; ?>" target="_blank"><img src="<?php echo $dataUri; ?>" alt="Anexo" class="anexo-image"></a>
                                                         <?php else: ?>
                                                             <a href="<?php echo $dataUri; ?>" download="<?php echo $anexo_nome; ?>" class="anexo-link">Anexo: <?php echo $anexo_nome; ?></a>
                                                         <?php endif; ?>
                                                     <?php endif; ?>
                                                 </div>
                                             </div>
                                        <?php endforeach; ?>

                                    </div>
                                </div>

                                <?php if (!$is_closed): ?>
                                    <form action="detalhes_ticket.php?id=<?php echo $ticket_id; ?>" method="POST" id="client-reply-form" enctype="multipart/form-data" style="margin-top: 20px; border-top: 1px solid var(--border-color-light); padding-top: 20px;">

                                        <div class="form-group">
                                            <label for="nova_mensagem" style="font-weight: 600; color: var(--text-color-dark);">Sua Resposta:</label>
                                            <textarea id="nova_mensagem" name="nova_mensagem" rows="3" placeholder="Digite sua mensagem..."></textarea>
                                            <?php if(isset($errors['resposta'])): ?><span class="error-message"><?php echo $errors['resposta']; ?></span><?php endif; ?>
                                        </div>

                                        <div class="form-group" style="margin-bottom: 20px;">
                                            <label for="anexo">Anexar Arquivo (Max 5MB: JPG, PNG, PDF, etc.)</label>
                                            <input type="file" name="anexo" id="anexo" accept=".jpg,.jpeg,.png,.gif,.pdf,.txt">
                                            <?php if(isset($errors['upload'])): ?><span class="error-message"><?php echo $errors['upload']; ?></span><?php endif; ?>
                                        </div>

                                        <div style="text-align: right; display: flex; justify-content: flex-end; gap: 10px;">
                                            <button type="button" id="btn-confirm-fechar-modal" class="btn-salvar btn-fechar">Fechar Ticket</button>
                                            <button type="submit" name="responder_ticket" class="btn-salvar" id="send-reply-btn">Enviar Resposta</button>
                                        </div>
                                    </form>
                                <?php endif; ?>

                                <?php if ($is_closed): ?>
                                    <div class="ticket-closed-notice" style="margin-top: 20px;">
                                        Este ticket está fechado.
                                        <?php if (!$ja_avaliado): ?>
                                            <a href="#" id="btn-avaliar-agora" class="btn-salvar" style="margin-top: 15px;">Avaliar Atendimento</a>
                                        <?php else: ?>
                                            Obrigado por sua avaliação!
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                        </section>

                        <aside>
                            <div class="detail-card">
                                <h2>Detalhes do Ticket</h2>
                                <div class="summary-block">
                                    <p><strong>Assunto:</strong><br> <?php echo htmlspecialchars($ticket['assunto']); ?></p>
                                </div>
                                <div class="summary-block">
                                    <p><strong>Motivo:</strong><br> <?php echo htmlspecialchars($ticket['motivo']); ?></p>
                                </div>
                                <?php if ($ticket['pedido_id']): ?>
                                <div class="summary-block">
                                    <p><strong>Pedido Relacionado:</strong><br> <a href="detalhes_pedido.php?id=<?php echo $ticket['pedido_id']; ?>">Pedido #<?php echo $ticket['pedido_id']; ?></a></p>
                                </div>
                                <?php endif; ?>
                                <div class="summary-block">
                                    <p><strong>Criado em:</strong><br> <?php echo formatarDataHoraBR($ticket['data_criacao']); ?></p>
                                </div>
                                <div class="summary-block">
                                    <p><strong>Última Atualização:</strong><br> <?php echo formatarDataHoraBR($ticket['ultima_atualizacao']); ?></p>
                                </div>

                                <?php if ($ja_avaliado): ?>
                                <div class="summary-block" style="border-top: 1px dashed var(--border-color-medium); padding-top: 15px; margin-bottom: 0; padding-bottom: 0;">
                                    <h2>Sua Avaliação</h2>
                                    <p><?php if (function_exists('renderStars')) echo renderStars($ticket['atendimento_rating']); else echo str_repeat('★', $ticket['atendimento_rating']) . str_repeat('☆', 5 - $ticket['atendimento_rating']); ?></p>
                                    <?php if (!empty($ticket['atendimento_comentario'])): ?>
                                        <p style="font-style: italic; color: var(--text-color-dark); margin-top: 5px;">"<?php echo nl2br(htmlspecialchars($ticket['atendimento_comentario'])); ?>"</p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                            </div>
                        </aside>
                    </div>

                <?php else: ?>
                    <div class="data-card" style="text-align: center;">
                        <h2>Ticket Não Encontrado</h2>
                        <p>O ticket que você está tentando acessar não existe ou não pertence à sua conta.</p>
                        <a href="suporte.php" class="btn-salvar" style="margin-top: 15px;">Voltar ao Suporte</a>
                    </div>
                <?php endif; ?>

            </section>
        </div>
    </main>

    <?php include 'templates/footer.php'; ?>

    <div class="modal-overlay" id="confirm-close-modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3 class="modal-title">Confirmar Ação</h3>
                <button class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Tem certeza que deseja fechar este ticket? Você não poderá mais enviar respostas, mas poderá avaliar o atendimento.</p>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn-salvar cancel modal-close" data-dismiss="modal">Cancelar</button>
                <form method="POST" action="detalhes_ticket.php?id=<?php echo $ticket_id; ?>" style="margin:0;">
                    <button type="submit" name="fechar_ticket" class="btn-salvar btn-fechar">Sim, Fechar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="rating-modal">
        <div class="modal-content" style="max-width: 500px;">
            <form method="POST" action="detalhes_ticket.php?id=<?php echo $ticket_id; ?>">
                <div class="modal-header">
                    <h3 class="modal-title">Avalie nosso Atendimento</h3>
                    <button class="modal-close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" style="text-align: center;">
                    <p>O que você achou da solução deste ticket?</p>

                    <?php if(isset($errors['rating'])): ?>
                        <div class="general-error" style="text-align: left;"><?php echo $errors['rating']; ?></div>
                    <?php endif; ?>

                    <div class="form-group">
                        <div class="rating-modal-stars">
                            <input type="radio" id="rate5" name="classificacao" value="5" /><label for="rate5" title="5 estrelas">★</label>
                            <input type="radio" id="rate4" name="classificacao" value="4" /><label for="rate4" title="4 estrelas">★</label>
                            <input type="radio" id="rate3" name="classificacao" value="3" /><label for="rate3" title="3 estrelas">★</label>
                            <input type="radio" id="rate2" name="classificacao" value="2" /><label for="rate2" title="2 estrelas">★</label>
                            <input type="radio" id="rate1" name="classificacao" value="1" required /><label for="rate1" title="1 estrela">★</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="comentario_avaliacao" style="text-align: left;">Deixe um comentário (opcional):</label>
                        <textarea id="comentario_avaliacao" name="comentario_avaliacao" rows="3" placeholder="Nos conte mais..."></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="text-align: right;">
                    <button type="submit" name="enviar_avaliacao" class="btn-salvar">Enviar Avaliação</button>
                </div>
            </form>
        </div>
    </div>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        // ==========================================================
        // JAVASCRIPT DO CHAT DO CLIENTE (AJAX/POLLING)
        // ==========================================================

        // --- Variáveis Globais (Só executam se o ticket existir) ---
        <?php if ($ticket): // CORREÇÃO: Só define variáveis JS se o ticket existir ?>
        const chatTimeline = document.getElementById('chat-timeline-messages');
        const chatWrapper = document.getElementById('chat-container-wrapper');
        const replyForm = document.getElementById('client-reply-form');
        const sendBtn = document.getElementById('send-reply-btn');

        let pollingInterval;
        const POLLING_RATE_MS = 5000; // 5 segundos
        window.lastMessageId = <?php echo $last_message_id; ?>;
        const ticketId = <?php echo $ticket_id; ?>;

        // --- Helpers ---
        const sanitizeHTML = (str) => {
             if (!str) return '';
             // Substitui caracteres HTML especiais para prevenir XSS
             return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
        const formatarDataHoraBR_JS = (dateStr) => {
            // Tenta criar uma data de forma mais robusta
            const date = new Date(dateStr.replace(' ', 'T') + 'Z');
            if (isNaN(date.getTime())) return 'N/A';
            return date.toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'});
        };

        /**
         * Cria o HTML para uma nova bolha de mensagem (Admin ou Cliente)
         */
        const createMessageHTML = (msg) => {
            const is_admin = msg.tipo === 'admin';
            const wrapper_class = is_admin ? 'admin' : 'client';

            let anexoHTML = '';
            if (msg.anexo_dados && msg.anexo_tipo && msg.anexo_nome) {
                const dataUri = `data:${sanitizeHTML(msg.anexo_tipo)};base64,${msg.anexo_dados}`;
                const isImage = msg.anexo_tipo.startsWith('image/');

                if (isImage) {
                    // Note: Sanitização é crucial aqui para evitar injeção no src, mas data URI é geralmente seguro
                    anexoHTML = `<a href="${dataUri}" target="_blank"><img src="${dataUri}" alt="Anexo" class="anexo-image"></a>`;
                } else {
                    anexoHTML = `<a href="${dataUri}" download="${sanitizeHTML(msg.anexo_nome)}" class="anexo-link">Anexo: ${sanitizeHTML(msg.anexo_nome)}</a>`;
                }
            }

            let icon_svg = is_admin ?
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"></path></svg>' :
                '<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>';

            const remetenteNome = msg.remetente_nome || (is_admin ? 'Suporte' : 'Você');

            // Converte quebras de linha em <br> para exibição correta
            const mensagemFormatada = msg.mensagem ? sanitizeHTML(msg.mensagem).replace(/\n/g, '<br>') : '';

            return `
                <div class="message-wrapper ${wrapper_class}">
                    <div class="message-icon">${icon_svg}</div>
                    <div class="message-bubble ${wrapper_class}">
                        <span class="message-meta">
                            ${sanitizeHTML(remetenteNome)}
                            <span class="date">(${msg.data_resposta_br || formatarDataHoraBR_JS(msg.data_resposta)})</span>
                        </span>
                        <p>${mensagemFormatada}</p>
                        ${anexoHTML}
                    </div>
                </div>
            `;
        };

        const scrollToBottom = () => {
             if (chatWrapper) {
                chatWrapper.scrollTop = chatWrapper.scrollHeight;
             }
        };

        /**
         * Função de Polling (Buscar Mensagens do Admin)
         */
        const checkNewMessages = async () => {
            try {
                // CORREÇÃO APLICADA AQUI: Usando detalhes_ticket.php para o polling
                const response = await fetch(`detalhes_ticket.php?ajax_action=check_new_messages&id=${ticketId}&last_id=${window.lastMessageId}`);
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Polling HTTP Error:', errorText);
                    throw new Error(`Polling HTTP Error: ${response.status} ${errorText}`);
                }

                const data = await response.json();

                if (data.success && data.messages.length > 0) {
                    // Verifica se o scroll está próximo do fundo antes de adicionar mensagens
                    let shouldScroll = chatWrapper.scrollHeight - chatWrapper.clientHeight <= chatWrapper.scrollTop + 50;

                    data.messages.forEach(msg => {
                        chatTimeline.insertAdjacentHTML('beforeend', createMessageHTML(msg));
                        window.lastMessageId = msg.id;
                    });

                    if (shouldScroll) scrollToBottom();
                }
            } catch (error) {
                console.error('Erro no Polling:', error);
            }
        };


        /**
         * Lógica de Envio de Resposta (AJAX)
         */
        const handleReplySubmit = async (e) => {
             e.preventDefault();

             const formData = new FormData(replyForm);
             const mensagem = document.getElementById('nova_mensagem').value.trim();
             const anexo = document.getElementById('anexo').files[0];

             if (!mensagem && !anexo) {
                 alert('Por favor, digite uma mensagem ou anexe um arquivo.');
                 return;
             }

             sendBtn.disabled = true;
             sendBtn.textContent = 'Enviando...';

             try {
                 const response = await fetch(`detalhes_ticket.php?ajax_action=client_reply&id=${ticketId}`, {
                      method: 'POST',
                      body: formData
                 });

                 if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Submit HTTP Error:', errorText);
                    // Tenta parsear JSON de erro se possível
                    try {
                        const errorJson = JSON.parse(errorText);
                        throw new Error(errorJson.message || `Submit HTTP Error: ${response.status}`);
                    } catch (e) {
                         throw new Error(`Submit HTTP Error: ${response.status} ${errorText}`);
                    }
                 }

                 const data = await response.json();

                 if (data.success && data.message) {
                     chatTimeline.insertAdjacentHTML('beforeend', createMessageHTML(data.message));
                     window.lastMessageId = data.last_id;
                     replyForm.reset();
                     scrollToBottom();
                 } else {
                     alert('Falha ao enviar: ' + (data.message || 'Erro desconhecido.'));
                 }
             } catch (error) {
                 console.error('Erro de rede/JSON:', error);
                 alert('Erro de conexão ou no servidor. Tente novamente. Detalhes: ' + error.message);
             } finally {
                 sendBtn.disabled = false;
                 sendBtn.textContent = 'Enviar Resposta';
             }
        };


        // --- DOM Ready e Inicialização ---
        document.addEventListener('DOMContentLoaded', function() {

            // 1. Força o scroll inicial
            scrollToBottom();

            // 2. Captura o formulário de resposta e usa AJAX (se existir)
            if (replyForm) {
                replyForm.addEventListener('submit', handleReplySubmit);
            }

            // 3. Inicia o Polling (se o ticket não estiver fechado)
            if ('<?php echo $is_closed ? 'true' : 'false'; ?>' === 'false') {
                 pollingInterval = setInterval(checkNewMessages, POLLING_RATE_MS);
            }

            // 4. Lógica do modal de fechar/avaliar
            const confirmCloseModal = document.getElementById('confirm-close-modal');
            const btnTriggerCloseModal = document.getElementById('btn-confirm-fechar-modal');
            if(btnTriggerCloseModal) btnTriggerCloseModal.addEventListener('click', () => { if (confirmCloseModal) confirmCloseModal.classList.add('modal-open'); });
            if(confirmCloseModal) confirmCloseModal.querySelectorAll('[data-dismiss="modal"]').forEach(btn => btn.addEventListener('click', (e) => { e.preventDefault(); confirmCloseModal.classList.remove('modal-open'); }));

            const ratingModal = document.getElementById('rating-modal');
            const btnAvaliarAgora = document.getElementById('btn-avaliar-agora');
            <?php if ($show_rating_modal): ?> if(ratingModal) ratingModal.classList.add('modal-open'); <?php endif; ?>
            if (btnAvaliarAgora) btnAvaliarAgora.addEventListener('click', (e) => { e.preventDefault(); if(ratingModal) ratingModal.classList.add('modal-open'); });
            if(ratingModal) ratingModal.querySelectorAll('[data-dismiss="modal"]').forEach(btn => btn.addEventListener('click', (e) => { e.preventDefault(); ratingModal.classList.remove('modal-open'); }));

             // Limpa o polling ao fechar/sair da página
             window.addEventListener('beforeunload', () => {
                 if (pollingInterval) clearInterval(pollingInterval);
             });
        });

        <?php endif; // --- Fim do wrapper JS (só executa se o ticket existir) --- ?>
    </script>

</body>
</html>