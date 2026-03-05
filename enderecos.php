<?php
// enderecos.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // DEVE ser a primeira linha
}

// --- 1. PROTEÇÃO DA PÁGINA ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: login.php?redirect=enderecos.php');
    exit;
}

require_once 'config/db.php'; // Garante $pdo para includes

$user_id = $_SESSION['user_id'];
$user_nome = $_SESSION['user_nome']; // Pega o nome do usuário logado
$errors = [];
$success_message = '';
$page_alert_message = ''; // Para o modal de erro

// --- 2. LÓGICA DE AÇÕES (POST e GET) ---

$edit_data = []; // Guarda os dados do formulário (para edição ou repopular em caso de erro)
$is_editing = false;
$show_form = false; // Flag para controlar a visibilidade do formulário

// --- AÇÃO: DELETAR ENDEREÇO (GET) ---
if (isset($_GET['delete'])) {
    $endereco_id_del = (int)$_GET['delete'];
    try {
        $sql_del = "DELETE FROM enderecos WHERE id = :id AND usuario_id = :uid";
        $stmt_del = $pdo->prepare($sql_del);
        $stmt_del->execute(['id' => $endereco_id_del, 'uid' => $user_id]);

        $success_message = "Endereço removido com sucesso!";
        header('Location: enderecos.php?deleted=true');
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() == '23503') {
            $page_alert_message = "Não é possível excluir este endereço, pois ele está associado a um ou mais pedidos existentes.";
        } else {
            $page_alert_message = "Erro ao remover endereço: " . $e->getMessage();
        }
        $errors['db'] = $page_alert_message;
    }
}
if (isset($_GET['deleted']) && empty($message)) { $success_message = "Endereço removido com sucesso!"; }
if (isset($_GET['saved']) && empty($message)) { $success_message = "Endereço salvo com sucesso!"; }


// --- AÇÃO: SALVAR ENDEREÇO (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_endereco'])) {

    $show_form = true;
    $endereco_id_edit = isset($_POST['endereco_id']) ? (int)$_POST['endereco_id'] : null;
    if ($endereco_id_edit) {
        $is_editing = true;
    }

    // Coleta de dados
    $nome_endereco = trim($_POST['nome_endereco']);
    $cep = trim($_POST['cep']);
    $endereco = trim($_POST['endereco']);
    $numero = trim($_POST['numero']);
    $complemento = trim($_POST['complemento']);
    $bairro = trim($_POST['bairro']);
    $cidade = trim($_POST['cidade']);
    $estado = trim($_POST['estado']);
    $destinatario = trim($_POST['destinatario']);
    $is_principal = isset($_POST['is_principal']);
    $senha_atual = $_POST['senha_atual'];

    // Validações
    if (empty($nome_endereco)) $errors['nome_endereco'] = "Dê um nome para o endereço (ex: Casa).";
    if (empty($cep)) $errors['cep'] = "CEP é obrigatório.";
    if (empty($endereco)) $errors['endereco'] = "Endereço é obrigatório.";
    if (empty($numero)) $errors['numero'] = "Número é obrigatório.";
    if (empty($bairro)) $errors['bairro'] = "Bairro é obrigatório.";
    if (empty($cidade)) $errors['cidade'] = "Cidade é obrigatória.";
    if (empty($estado)) $errors['estado'] = "Estado é obrigatório.";
    if (empty($destinatario)) $errors['destinatario'] = "Destinatário é obrigatório.";
    if (empty($senha_atual)) $errors['senha_atual'] = "Você deve digitar sua senha atual para salvar.";

    // Validar Senha Atual
    if (empty($errors)) {
        $stmt_pass = $pdo->prepare("SELECT senha FROM usuarios WHERE id = :id");
        $stmt_pass->execute(['id' => $user_id]);
        $current_hash = $stmt_pass->fetchColumn();

        if (!$current_hash || !password_verify($senha_atual, $current_hash)) {
            $errors['senha_atual'] = "Senha atual incorreta.";
        }
    }

    // Se tudo estiver OK, salva no banco
    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            if ($is_principal) {
                $stmt_unmark = $pdo->prepare("UPDATE enderecos SET is_principal = false WHERE usuario_id = :uid");
                $stmt_unmark->execute(['uid' => $user_id]);
            }

            $params = [
                ':uid' => $user_id,
                ':nome_end' => $nome_endereco,
                ':cep' => $cep,
                ':end' => $endereco,
                ':num' => $numero,
                ':comp' => !empty($complemento) ? $complemento : null,
                ':bairro' => $bairro,
                ':cid' => $cidade,
                ':est' => $estado,
                ':dest' => $destinatario,
                // No PostgreSQL (que você parece estar usando pelo erro 23503), TRUE/FALSE são literais
                ':is_princ' => $is_principal ? 'TRUE' : 'FALSE'
            ];

            if ($endereco_id_edit) {
                // UPDATE (Editar)
                $sql = "UPDATE enderecos SET nome_endereco = :nome_end, cep = :cep, endereco = :end, numero = :num, complemento = :comp, bairro = :bairro, cidade = :cid, estado = :est, destinatario = :dest, is_principal = :is_princ
                        WHERE id = :id AND usuario_id = :uid";
                $params[':id'] = $endereco_id_edit;
                $stmt_save = $pdo->prepare($sql);
            } else {
                // INSERT (Adicionar)
                $sql = "INSERT INTO enderecos (usuario_id, nome_endereco, cep, endereco, numero, complemento, bairro, cidade, estado, destinatario, is_principal)
                        VALUES (:uid, :nome_end, :cep, :end, :num, :comp, :bairro, :cid, :est, :dest, :is_princ)";
                $stmt_save = $pdo->prepare($sql);
            }
            $stmt_save->execute($params);

            $pdo->commit();
            header('Location: enderecos.php?saved=true');
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors['db'] = "Erro ao salvar o endereço: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $edit_data = $_POST;
        if ($endereco_id_edit) {
            $edit_data['id'] = $endereco_id_edit;
        }
    }
}

// --- AÇÃO: MODO DE EDIÇÃO (GET) ---
if (isset($_GET['edit'])) {
    $endereco_id_edit = (int)$_GET['edit'];
    $is_editing = true;
    $show_form = true;

    $stmt = $pdo->prepare("SELECT * FROM enderecos WHERE id = :id AND usuario_id = :uid");
    $stmt->execute(['id' => $endereco_id_edit, 'uid' => $user_id]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$edit_data) {
        $is_editing = false; $show_form = false;
        $errors['db'] = "Endereço não encontrado.";
    }
}

// --- AÇÃO: MODO DE ADIÇÃO (GET) ---
if (isset($_GET['add_new'])) {
    $show_form = true;
    $is_editing = false;
    if (empty($edit_data)) {
        $edit_data = ['destinatario' => $user_nome];
    }
}

// --- 3. BUSCAR ENDEREÇOS SALVOS (Para a lista) ---
$meus_enderecos = [];
try {
    $stmt_meus_enderecos = $pdo->prepare("SELECT * FROM enderecos WHERE usuario_id = :uid ORDER BY is_principal DESC, nome_endereco ASC");
    $stmt_meus_enderecos->execute(['uid' => $user_id]);
    $meus_enderecos = $stmt_meus_enderecos->fetchAll();
} catch (PDOException $e) {
     $errors['db'] = "Erro ao buscar endereços: " . $e->getMessage();
     if(empty($page_alert_message)) {
       $page_alert_message = $errors['db'];
     }
}

// --- NOVO PASSO: BUSCAR CONFIGURAÇÃO DE CORES DO SITE ---
$cor_acento_hover = '#8cc600'; // Valor padrão de fallback (o que estava no CSS original)

try {
    // Busca no DB o valor para a chave 'cor_acento_hover'
    $stmt_config = $pdo->prepare("SELECT valor FROM config_site WHERE chave = :chave");
    $stmt_config->execute(['chave' => 'cor_acento_hover']);
    $cor_db = $stmt_config->fetchColumn();

    // Se a cor for encontrada e não for vazia, usamos o valor do DB
    if ($cor_db) {
        $cor_acento_hover = htmlspecialchars($cor_db);
    }

} catch (PDOException $e) {
    error_log("Erro ao buscar cor do hover no DB em enderecos.php: " . $e->getMessage());
    // Mantém o valor padrão em caso de erro no DB
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Endereços - <?php echo htmlspecialchars($_SESSION['user_nome']); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS ESPECÍFICO DESTA PÁGINA (Layout Perfil + Formulário Endereços)
           ========================================================== */

        /* Adicionando Variáveis CSS */
        :root {
            /* Cor de destaque e outras variáveis globais (necessário para o CSS funcionar) */
            --green-accent: #a4d32a;
            --text-color-dark: #333;
            --text-color-medium: #666;
            --text-color-light: #999;
            --border-color-light: #eee;
            --border-color-medium: #ccc;
            --error-color: #ff4444;
            --success-color: #5cb85c;

            /* NOVO: Variável da cor de hover vinda do DB */
            --cor-acento-hover: <?php echo $cor_acento_hover; ?>;
        }


        /* --- Layout da Página de Perfil --- */
        .profile-title {
            font-size: 1.8em;
            font-weight: bold;
            color: var(--text-color-dark);
            margin: 15px 0 25px 0;
        }
        .profile-layout {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }
        /* --- Barra Lateral de Perfil --- */
        .profile-sidebar {
            width: 260px;
            flex-shrink: 0;
            background-color: #fff;
            border: 1px solid var(--border-color-light);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .profile-sidebar nav ul { list-style: none; padding: 0; margin: 0; }
        .profile-sidebar nav li { border-bottom: 1px solid var(--border-color-light); }
        .profile-sidebar nav li:last-child { border-bottom: none; }
        .profile-sidebar nav a { display: flex; align-items: center; gap: 15px; padding: 18px 20px; font-size: 0.95em; color: var(--text-color-medium); transition: background-color 0.2s ease, color 0.2s ease; }
        .profile-sidebar nav a svg { width: 20px; height: 20px; stroke-width: 2; color: var(--text-color-light); transition: color 0.2s ease; }
        .profile-sidebar nav a:hover { background-color: #fdfdfd; color: var(--green-accent); }
        .profile-sidebar nav a:hover svg { color: var(--green-accent); }
        .profile-sidebar nav a.active { background-color: #f5f5f5; color: var(--text-color-dark); font-weight: bold; }
        .profile-sidebar nav a.active svg { color: var(--text-color-dark); }

        /* --- Conteúdo do Perfil (Genérico) --- */
        .profile-content { flex-grow: 1; }
        .data-card {
            background-color: #fff;
            border: 1px solid var(--border-color-light);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
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

        /* --- Formulário e Lista de Endereços --- */
        .form-group { margin-bottom: 18px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: bold; font-size: 0.9em; color: var(--text-color-dark); }
        .form-group label span { color: var(--error-color); }
        .form-group .input-icon {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(25%); /* Ajuste para alinhar com o input que tem margin-top */
            color: var(--text-color-light);
            pointer-events: none;
        }
        .form-group .input-icon svg { width: 18px; height: 18px; }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group input[type="tel"] {
            width: 100%;
            padding: 10px 12px 10px 40px;
            border: 1px solid var(--border-color-medium);
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1em;
            background-color: #fff;
            margin-top: 8px;
        }
        .form-group-cep { display: flex; gap: 10px; align-items: center; }
        .form-group-cep input { flex-grow: 1; }
        .form-group-cep a { font-size: 0.85em; color: var(--green-accent); text-decoration: underline; flex-shrink: 0; }
        .address-grid {
             display: grid;
             grid-template-columns: 1fr 1fr;
             gap: 20px 30px;
        }
        .address-grid .full-width { grid-column: 1 / -1; }
        .form-group input:focus { border-color: var(--green-accent); box-shadow: 0 0 0 2px rgba(154, 215, 0, 0.2); outline: none; }
        .form-group input:read-only, .form-group input[readonly] {
            background-color: #f5f5f5;
            color: var(--text-color-medium);
            cursor: not-allowed;
            border-color: var(--border-color-light);
            margin-top: 9px;
        }
        .form-group-checkbox { display: flex; align-items: center; margin: 25px 0; }
        .form-group-checkbox input[type="checkbox"] { margin-right: 10px; width: auto; height: auto; accent-color: var(--green-accent); }
        .form-group-checkbox label { margin-bottom: 0; font-weight: normal; color: var(--text-color-medium); font-size: 0.9em; }

        .error-message { color: var(--error-color); font-size: 0.8em; margin-top: 4px; display: block; }
        input.error-border { border-color: var(--error-color) !important; }
        .general-error { color: var(--error-color); font-weight: bold; text-align: center; margin-bottom: 15px; }
        .general-success {
            background-color: #f0f9eb;
            border: 1px solid var(--success-color);
            color: var(--success-color);
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }
        .password-section { margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color-light); }
        .password-section p { font-size: 0.9em; color: var(--text-color-medium); margin-top: 5px; }
        .submit-button-container { text-align: right; margin-top: 30px; }
        .btn-salvar { display: inline-block; padding: 10px 25px; border-radius: 4px; font-size: 0.9em; font-weight: bold; text-align: center; text-decoration: none; cursor: pointer; transition: all 0.3s ease; border: 1px solid var(--green-accent); background-color: var(--green-accent); color: #fff; }

        /* USO DA VARIÁVEL DO DB */
        .btn-salvar:hover { background-color: var(--cor-acento-hover); border-color: var(--cor-acento-hover); }

        /* Mantendo o hover do botão de adicionar novo (cinza) */
        .btn-salvar.btn-add-new { background-color: #555; border-color: #555; }
        .btn-salvar.btn-add-new:hover { background-color: #333; border-color: #333; }
        a.cancel-edit { margin-left: 10px; font-size: 0.9em; color: var(--text-color-medium); text-decoration: underline; }

        /* --- Lista de Endereços --- */
        .address-list { list-style: none; padding: 0; margin: 0; }
        .address-card-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px;
            border: 1px solid var(--border-color-light);
            border-radius: 8px;
            margin-bottom: 15px;
            background-color: #fff;
        }
        .address-card-item:last-child { margin-bottom: 0; }
        .address-info h5 { font-size: 1.1em; color: var(--text-color-dark); margin: 0 0 10px 0; display: flex; align-items: center; }
        .address-info .tag-principal {
            font-size: 0.8em;
            font-weight: bold;
            color: var(--green-accent);
            background-color: var(--header-footer-bg);
            padding: 3px 8px;
            border-radius: 4px;
            margin-left: 10px;
        }
        .address-info p { font-size: 0.9em; color: var(--text-color-medium); margin: 4px 0 0 0; line-height: 1.5; }
        .address-actions { display: flex; gap: 15px; flex-shrink: 0; margin-left: 20px; }
        .address-actions a { font-size: 0.9em; font-weight: bold; color: var(--green-accent); text-decoration: underline; }
         .address-actions a.delete { color: var(--error-color); }
         .add-new-container { margin-top: 25px; padding-top: 25px; border-top: 1px solid var(--border-color-light); }


        /* --- Responsividade da Página de Endereços --- */
        @media (max-width: 992px) {
            .data-card {
                margin-left: 0; /* Ajuste para 992px */
                width: 100%;    /* Ajuste para 992px */
            }
            .profile-layout { flex-direction: column; margin-left: 16px; margin-right: 16px;  }
            .profile-sidebar { width: 100%; margin-bottom: 20px; }
            .profile-title{ margin-left: 16px;}
            .profile-content { width: 100%; } /* Garante que o conteúdo ocupe a largura */
        }

        @media (max-width: 768px) {
            /* Perfil/Endereço Mobile */
            .address-grid { grid-template-columns: 1fr; gap: 15px; }
            .address-grid .full-width { grid-column: 1; } /* Reset para 1 coluna */
            .submit-button-container { text-align: center; }
            .btn-salvar { width: 100%; }
            .address-card-item { flex-direction: column; gap: 15px; }
            .address-actions { width: 100%; }
        }
    </style>
</head>
<body class="profile-page">

    <?php include 'templates/header.php'; ?>

    <main class="main-content container">

        <h1 class="profile-title">Minha conta</h1>

        <div class="profile-layout">

            <aside class="profile-sidebar">
                <nav>
                    <ul>
                        <li>
                            <a href="perfil.php">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                <span>Minha conta</span>
                            </a>
                        </li>
                        <li>
                            <a href="pedidos.php">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                                <span>Meus pedidos</span>
                            </a>
                        </li>
                        <li>
                            <a href="meus-dados.php">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4z" /></svg>
                                <span>Meus dados</span>
                            </a>
                        </li>
                        <li>
                            <a href="enderecos.php" class="active"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                <span>Meus endereços</span>
                            </a>
                        </li>
                          <li>
                            <a href="lista-desejos.php">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>
                                <span>Lista de desejos</span>
                            </a>
                        </li>
                        <li>
                            <a href="suporte.php"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                                <span>Suporte</span>
                            </a>
                        </li>
                        <li>
                            <a href="alterar-senha.php">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                <span>Alterar senha</span>
                            </a>
                        </li>
                          <li>
                            <a href="logout.php">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                                <span>Sair</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </aside>
            <section class="profile-content">

                <div class="data-card">
                    <h2>Endereços Salvos</h2>

                    <?php if (!empty($success_message) && !$is_editing && !$show_form): ?>
                        <div class="general-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>

                    <?php if (isset($errors['db']) && !$show_form): // Mostra o modal de erro de exclusão ?>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const infoModal = document.getElementById('info-modal');
                                const infoModalMessage = document.getElementById('info-modal-message');
                                if (infoModal && infoModalMessage) {
                                    infoModalMessage.innerHTML = <?php echo json_encode($errors['db']); ?>;
                                    infoModal.classList.add('modal-open');
                                }
                            });
                        </script>
                    <?php endif; ?>

                    <?php if (empty($meus_enderecos)): ?>
                        <p style="color: var(--text-color-medium);">Você ainda não tem endereços cadastrados.</p>
                    <?php else: ?>
                        <ul class="address-list">
                            <?php foreach ($meus_enderecos as $endereco): ?>
                                <li class="address-card-item">
                                    <div class="address-info">
                                        <h5>
                                            <?php echo htmlspecialchars($endereco['nome_endereco']); ?>
                                            <?php if ($endereco['is_principal']): ?>
                                                <span class="tag-principal">Principal</span>
                                            <?php endif; ?>
                                        </h5>
                                        <p><?php echo htmlspecialchars($endereco['destinatario']); ?></p>
                                        <p><?php echo htmlspecialchars($endereco['endereco']); ?>, <?php echo htmlspecialchars($endereco['numero']); ?> <?php echo $endereco['complemento'] ? ' - '.htmlspecialchars($endereco['complemento']) : ''; ?></p>
                                        <p><?php echo htmlspecialchars($endereco['bairro']); ?>, <?php echo htmlspecialchars($endereco['cidade']); ?> - <?php echo htmlspecialchars($endereco['estado']); ?></p>
                                        <p>CEP: <?php echo htmlspecialchars($endereco['cep']); ?></p>
                                    </div>
                                    <div class="address-actions">
                                        <a href="enderecos.php?edit=<?php echo $endereco['id']; ?>#form-endereco-card">Editar</a>
                                        <a href="enderecos.php?delete=<?php echo $endereco['id']; ?>" class="delete">Excluir</a>
                                        </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!$show_form): ?>
                    <div class="add-new-container">
                        <a href="enderecos.php?add_new=true#form-endereco-card" class="btn-salvar btn-add-new">
                            + Adicionar Novo Endereço
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($show_form): ?>
                <div class="data-card" id="form-endereco-card">
                    <h2><?php echo $is_editing ? 'Editar Endereço' : 'Adicionar Novo Endereço'; ?></h2>

                    <?php if (!empty($errors) && !isset($errors['db'])): ?>
                        <div class="general-error">Por favor, corrija os erros no formulário.</div>
                    <?php elseif (isset($errors['db'])): ?>
                          <div class="general-error"><?php echo $errors['db']; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($success_message) && ($is_editing || $show_form)): ?>
                        <div class="general-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>

                    <form action="enderecos.php<?php echo $is_editing ? '?edit='.$edit_data['id'] : ''; ?>#form-endereco-card" method="POST" id="form-endereco">

                        <?php if ($is_editing): ?>
                            <input type="hidden" name="endereco_id" value="<?php echo htmlspecialchars($edit_data['id']); ?>">
                        <?php endif; ?>

                        <div class="form-group-checkbox">
                           <input type="checkbox" id="is_principal" name="is_principal" value="1" <?php echo ($edit_data['is_principal'] ?? false) ? 'checked' : ''; ?>>
                           <label for="is_principal">Selecionar como endereço de entrega principal</label>
                        </div>

                        <div class="form-group full-width">
                            <span class="input-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                            </span>
                            <label for="nome_endereco"><span>*</span>Nome do endereço (Ex: Casa, Trabalho)</label>
                            <input type="text" id="nome_endereco" name="nome_endereco" value="<?php echo htmlspecialchars($edit_data['nome_endereco'] ?? ''); ?>" required class="<?php echo isset($errors['nome_endereco']) ? 'error-border' : ''; ?>">
                            <?php if(isset($errors['nome_endereco'])): ?><span class="error-message"><?php echo $errors['nome_endereco']; ?></span><?php endif; ?>
                        </div>

                        <div class="address-grid">
                            <div class="form-group">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>                           </span>
                                <label for="cep"><span>*</span>CEP</label>
                                <div class="form-group-cep">
                                    <input type="tel" id="cep" name="cep" value="<?php echo htmlspecialchars($edit_data['cep'] ?? ''); ?>" required class="<?php echo isset($errors['cep']) ? 'error-border' : ''; ?>">
                                    <a href="https://buscacepinter.correios.com.br/app/endereco/index.php" target="_blank">Não sei meu CEP</a>
                                </div>
                                <?php if(isset($errors['cep'])): ?><span class="error-message"><?php echo $errors['cep']; ?></span><?php endif; ?>
                                <span class="error-message" id="cep-error"></span> </div>

                            <div class="form-group">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                </span>
                                <label for="endereco"><span>*</span>Endereço</label>
                                <input type="text" id="endereco" name="endereco" value="<?php echo htmlspecialchars($edit_data['endereco'] ?? ''); ?>" required readonly class="<?php echo isset($errors['endereco']) ? 'error-border' : ''; ?>">
                                <?php if(isset($errors['endereco'])): ?><span class="error-message"><?php echo $errors['endereco']; ?></span><?php endif; ?>
                            </div>

                            <div class="form-group">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>                           </span>
                                <label for="numero"><span>*</span>Número</label>
                                <input type="text" id="numero" name="numero" value="<?php echo htmlspecialchars($edit_data['numero'] ?? ''); ?>" required class="<?php echo isset($errors['numero']) ? 'error-border' : ''; ?>">
                                <?php if(isset($errors['numero'])): ?><span class="error-message"><?php echo $errors['numero']; ?></span><?php endif; ?>
                            </div>

                            <div class="form-group">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.242 5.992h12m-12 6.003H20.24m-12 5.999h12M4.117 7.495v-3.75H2.99m1.125 3.75H2.99m1.125 0H5.24m-1.92 2.577a1.125 1.125 0 1 1 1.591 1.59l-1.83 1.83h2.16M2.99 15.745h1.125a1.125 1.125 0 0 1 0 2.25H3.74m0-.002h.375a1.125 1.125 0 0 1 0 2.25H2.99" /></svg>
                                </span>
                                <label for="complemento">Complemento</label>
                                <input type="text" id="complemento" name="complemento" value="<?php echo htmlspecialchars($edit_data['complemento'] ?? ''); ?>">
                            </div>

                            <div class="form-group full-width">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                                </span>
                                <label for="bairro"><span>*</span>Bairro</label>
                                <input type="text" id="bairro" name="bairro" value="<?php echo htmlspecialchars($edit_data['bairro'] ?? ''); ?>" required readonly class="<?php echo isset($errors['bairro']) ? 'error-border' : ''; ?>">
                                <?php if(isset($errors['bairro'])): ?><span class="error-message"><?php echo $errors['bairro']; ?></span><?php endif; ?>
                            </div>

                            <div class="form-group">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                                </span>
                                <label for="cidade"><span>*</span>Cidade</label>
                                <input type="text" id="cidade" name="cidade" value="<?php echo htmlspecialchars($edit_data['cidade'] ?? ''); ?>" required readonly class="<?php echo isset($errors['cidade']) ? 'error-border' : ''; ?>">
                                <?php if(isset($errors['cidade'])): ?><span class="error-message"><?php echo $errors['cidade']; ?></span><?php endif; ?>
                            </div>

                            <div class="form-group">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                                </span>
                                <label for="estado"><span>*</span>Estado</label>
                                <input type="text" id="estado" name="estado" value="<?php echo htmlspecialchars($edit_data['estado'] ?? ''); ?>" required readonly class="<?php echo isset($errors['estado']) ? 'error-border' : ''; ?>">
                                <?php if(isset($errors['estado'])): ?><span class="error-message"><?php echo $errors['estado']; ?></span><?php endif; ?>
                            </div>

                             <div class="form-group full-width">
                                 <span class="input-icon">
                                     <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                </span>
                                 <label for="destinatario"><span>*</span>Destinatário (Nome de quem vai receber)</label>
                                 <input type="text" id="destinatario" name="destinatario" value="<?php echo htmlspecialchars($edit_data['destinatario'] ?? $user_nome); ?>" required class="<?php echo isset($errors['destinatario']) ? 'error-border' : ''; ?>">
                                 <?php if(isset($errors['destinatario'])): ?><span class="error-message"><?php echo $errors['destinatario']; ?></span><?php endif; ?>
                             </div>
                        </div>

                        <div class="password-section">
                            <div class="form-group">
                                <label for="senha_atual"><span>*</span>Senha Atual</label>
                                <input type="password" id="senha_atual" name="senha_atual" required class="<?php echo isset($errors['senha_atual']) ? 'error-border' : ''; ?>">
                                <p>Para salvar o endereço, digite sua senha atual.</p>
                                <?php if(isset($errors['senha_atual'])): ?><span class="error-message"><?php echo $errors['senha_atual']; ?></span><?php endif; ?>
                            </div>
                        </div>

                        <div class="submit-button-container">
                            <button type="submit" name="salvar_endereco" class="btn-salvar">
                                <?php echo $is_editing ? 'Salvar Alterações' : 'Salvar Endereço'; ?>
                            </button>
                            <a href="enderecos.php" class="cancel-edit">Cancelar</a>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </section>
        </div>
    </main>


    <?php include 'templates/footer.php'; ?>

    <div class="modal-overlay" id="login-modal">
           <div class="modal-content">
                 <div class="modal-header">
                       <h3 class="modal-title">Identifique-se</h3>
                       <button class="modal-close" id="close-login-modal">&times;</button>
                 </div>
                 <div class="modal-body">
                       <form action="login.php" method="POST">
                             <div class="form-group">
                                   <label for="modal_login_email_header_perfil_end">E-mail ou CPF/CNPJ:</label>
                                   <input type="text" id="modal_login_email_header_perfil_end" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                             </div>
                             <div class="form-group">
                                   <label for="modal_login_senha_header_perfil_end">Senha:</label>
                                   <input type="password" id="modal_login_senha_header_perfil_end" name="modal_login_senha" placeholder="Digite sua senha" required>
                             </div>
                             <div class="modal-footer">
                                   <button type="submit" class="btn btn-primary">Continuar</button>
                             </div>
                       </form>
                 </div>
          </div>
    </div>

    <div class="cart-overlay" id="cart-overlay"></div>
    <div class="cart-modal" id="cart-modal">
        <div class="cart-header">
            <div class="cart-header-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                <span>Sacola de Compras</span>
            </div>
            <button class="cart-close-btn" id="cart-close-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M4 8a.5.5 0 0 1 .5-.5h5.793L8.146 5.354a.5.5 0 1 1 .708-.708l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708-.708L10.293 8.5H4.5A.5.5 0 0 1 4 8z"/></svg>
            </button>
        </div>
        <div class="cart-body" id="cart-body-content">
              <div class="cart-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                <p>Sua sacola está vazia</p>
            </div>
        </div>
        <div class="cart-footer">
            <div class="cart-footer-info">
                <p>* Calcule seu frete na página de finalização.</p>
                <p>* Insira seu cupom de desconto na página de finalização.</p>
            </div>
            <div class="cart-footer-actions">
                 <a href="#" class="cart-continue-btn" id="cart-continue-shopping">< Continuar Comprando</a>
                 <a href="checkout.php" class="btn btn-primary cart-checkout-btn">COMPRAR AGORA</a>
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
            <div class="modal-header">
                <h3 class="modal-title">Confirmar Exclusão</h3>
                <button class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Tem certeza que deseja excluir este endereço?</p>
                <p>Esta ação não pode ser desfeita.</p>
            </div>
            <div class="modal-footer" id="delete-modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-light modal-close" data-dismiss="modal">Cancelar</button>
                <button class="btn btn-danger" id="btn-confirm-delete">Sim, Excluir</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        $(document).ready(function(){

            // --- Lógica Específica de enderecos.php ---
            $('#cep').mask('00000-000');

            // API ViaCEP
            function limpa_formulario_cep() {
                $("#endereco").val("");
                $("#bairro").val("");
                $("#cidade").val("");
                $("#estado").val("");
                $("#cep-error").text("");
            }

            $("#cep").on('blur', function() {
                var cep = $(this).val().replace(/\D/g, ''); // Remove máscara
                if (cep.length == 8) { // CEP tem 8 dígitos
                    $("#cep-error").text("Buscando...");

                    // Trava campos
                    $("#endereco, #bairro, #cidade, #estado").prop('readonly', true).css('background-color', '#f5f5f5');

                    $.getJSON("https://viacep.com.br/ws/"+ cep +"/json/", function(dados) {
                        if (!("erro" in dados)) {
                            // Atualiza os campos
                            $("#endereco").val(dados.logradouro);
                            $("#bairro").val(dados.bairro);
                            $("#cidade").val(dados.localidade);
                            $("#estado").val(dados.uf);
                            $("#cep-error").text("");
                            $("#numero").focus(); // Pula para o campo número
                        } else {
                            limpa_formulario_cep();
                            $("#cep-error").text("CEP não encontrado.");
                        }
                    }).fail(function() {
                         limpa_formulario_cep();
                         $("#cep-error").text("Erro ao buscar CEP. Tente novamente.");
                    }).always(function() {
                         // Libera os campos que não foram preenchidos
                         if(!$("#endereco").val()) { $("#endereco").prop('readonly', false).css('background-color', '#fff'); }
                         if(!$("#bairro").val()) { $("#bairro").prop('readonly', false).css('background-color', '#fff'); }
                         if(!$("#cidade").val()) { $("#cidade").prop('readonly', false).css('background-color', '#fff'); }
                         if(!$("#estado").val()) { $("#estado").prop('readonly', false).css('background-color', '#fff'); }
                    });
                } else {
                    limpa_formulario_cep();
                     // Libera todos os campos se o CEP for inválido
                    $("#endereco, #bairro, #cidade, #estado").prop('readonly', false).css('background-color', '#fff');
                    if(cep.length > 0) {
                         $("#cep-error").text("Formato de CEP inválido.");
                    }
                }
            });
            // --- Fim da Lógica Específica ---
           });
    </script>

</body>
</html>