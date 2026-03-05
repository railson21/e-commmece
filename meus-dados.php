<?php
// meus-dados.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // DEVE ser a primeira linha
}

// --- 1. PROTEÇÃO DA PÁGINA ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: login.php?redirect=meus-dados.php');
    exit;
}

require_once 'config/db.php'; // Garante $pdo para includes

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// --- 2. LÓGICA DE ATUALIZAÇÃO (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_dados'])) {

    // Coleta de dados do form
    $email = trim(strtolower($_POST['email']));
    $tel_fixo = trim($_POST['telefone_fixo']);
    $tel_celular = trim($_POST['telefone_celular']);
    $receber_promocoes = isset($_POST['receber_promocoes']) ? 1 : 0;
    $senha_atual = $_POST['senha_atual'];

    // Campos readonly (pegamos para re-validar e manter)
    $nome = trim($_POST['nome']);
    $data_nascimento = trim($_POST['data_nascimento']);
    $cpf_raw = trim($_POST['cpf']);
    $cpf = preg_replace('/[^0-9]/', '', $cpf_raw);

    // Validações
    if (empty($email)) $errors['email'] = "E-mail é obrigatório.";
    if (empty($senha_atual)) $errors['senha_atual'] = "Você deve digitar sua senha atual para salvar.";

    // Validar unicidade (só se o email mudou)
    if (empty($errors)) {
        $stmt_check = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email AND id != :id");
        $stmt_check->execute(['email' => $email, 'id' => $user_id]);
        if ($stmt_check->fetch()) {
            $errors['email'] = "Este e-mail já está em uso por outra conta.";
        }
    }

    // Validar Senha Atual
    if (empty($errors)) {
        $stmt_pass = $pdo->prepare("SELECT senha FROM usuarios WHERE id = :id");
        $stmt_pass->execute(['id' => $user_id]);
        $current_hash = $stmt_pass->fetchColumn();

        if (!$current_hash || !password_verify($senha_atual, $current_hash)) {
            $errors['senha_atual'] = "Senha atual incorreta.";
        }
    }

    // Se tudo estiver OK, atualiza o banco
    if (empty($errors)) {
        try {
            $sql = "UPDATE usuarios SET
                         email = :email,
                         telefone_fixo = :tel_fixo,
                         telefone_celular = :tel_celular,
                         receber_promocoes = :receber_promocoes
                     WHERE id = :id";

            $stmt_update = $pdo->prepare($sql);
            $stmt_update->execute([
                ':email' => $email,
                ':tel_fixo' => !empty($tel_fixo) ? $tel_fixo : null,
                ':tel_celular' => !empty($tel_celular) ? $tel_celular : null,
                ':receber_promocoes' => $receber_promocoes, // Envia 1 ou 0
                ':id' => $user_id
            ]);

            $_SESSION['user_email'] = $email; // Atualiza o email na sessão
            $success_message = "Dados atualizados com sucesso!";

        } catch (PDOException $e) {
            $errors['db'] = "Erro ao atualizar dados: " . $e->getMessage();
        }
    }
}


// --- 3. BUSCAR DADOS DO USUÁRIO (PARA EXIBIR NO FORM) ---
$user_data = null;
$progress_percent = 0;
$fields_to_check = ['nome', 'email', 'cpf', 'data_nascimento', 'telefone_celular']; // Campos para o progresso

try {
    $stmt = $pdo->prepare("SELECT nome, email, cpf, data_nascimento, telefone_fixo, telefone_celular, receber_promocoes FROM usuarios WHERE id = :id");
    $stmt->execute(['id' => $user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_data) {
        // Cálculo de Progresso
        $filled_fields = 0;
        foreach ($fields_to_check as $field) {
            if (!empty($user_data[$field])) {
                $filled_fields++;
            }
        }
        $progress_percent = round(($filled_fields / count($fields_to_check)) * 100);
    } else {
        // Segurança: desloga se o usuário não for encontrado
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

} catch (PDOException $e) {
    die("Erro ao buscar dados do usuário: " . $e->getMessage());
}

/**
 * Função helper para formatar CPF (para exibição no input)
 */
function formatCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return $cpf;
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

// --- NOVO PASSO: BUSCAR CONFIGURAÇÃO DE CORES DO SITE ---
$cor_acento_hover = '#8cc600'; // Valor padrão de fallback

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
    error_log("Erro ao buscar cor do hover no DB em meus-dados.php: " . $e->getMessage());
    // Mantém o valor padrão em caso de erro no DB
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Dados - <?php echo htmlspecialchars($_SESSION['user_nome']); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS ESPECÍFICO DESTA PÁGINA (Layout Perfil + Formulário Meus Dados)
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

        /* --- Conteúdo do Perfil --- */
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

        /* --- Estilos do Formulário "Meus Dados" --- */
        .form-group { margin-bottom: 18px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: bold; font-size: 0.9em; color: var(--text-color-dark); }
        .form-group label span { color: var(--error-color); }
        .form-group input[type="text"], .form-group input[type="date"], .form-group input[type="email"], .form-group input[type="password"], .form-group input[type="tel"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color-medium);
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1em;
            background-color: #fff;
        }
        .form-group input:focus { border-color: var(--green-accent); box-shadow: 0 0 0 2px rgba(154, 215, 0, 0.2); outline: none; }
        .form-group input:read-only, .form-group input[readonly] {
            background-color: #f5f5f5;
            color: var(--text-color-medium);
            cursor: not-allowed;
            border-color: var(--border-color-light);
        }
        .form-group-checkbox { display: flex; align-items: center; margin: 25px 0; }
        .form-group-checkbox input[type="checkbox"] { margin-right: 10px; width: auto; height: auto; accent-color: var(--green-accent); }
        .form-group-checkbox label { margin-bottom: 0; font-weight: normal; color: var(--text-color-medium); font-size: 0.9em; }

        .data-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px 30px;
            /* Removido o padding-right, pois o progresso não está aqui */
        }
        .password-section { margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color-light); }
        .password-section label { color: var(--text-color-dark); }
        .password-section p { font-size: 0.9em; color: var(--text-color-medium); margin-top: 5px; }

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
        .tipo-pessoa-tabs { display: flex; justify-content: flex-start; margin-bottom: 25px; border-bottom: 1px solid var(--border-color-medium); }
        .tipo-pessoa-tabs button { padding: 10px 20px; border: none; background: none; color: var(--text-color-medium); cursor: pointer; font-size: 0.9em; margin: 0; border-bottom: 2px solid transparent; position: relative; top: 1px; }
        .tipo-pessoa-tabs button.active { color: var(--text-color-dark); font-weight: bold; border-bottom-color: var(--green-accent); }
        .tipo-pessoa-tabs button:disabled { cursor: not-allowed; opacity: 0.6; }

        .submit-button-container { text-align: right; margin-top: 10px; }
        .btn-salvar {
             display: inline-block;
             padding: 10px 25px;
             border-radius: 4px;
             font-size: 0.9em;
             font-weight: bold;
             text-align: center;
             text-decoration: none;
             cursor: pointer;
             transition: all 0.3s ease;
             border: 1px solid var(--green-accent);
             background-color: var(--green-accent);
             color: #fff;
        }
         /* USANDO VARIÁVEL DO DB */
         .btn-salvar:hover {
            background-color: var(--cor-acento-hover);
            border-color: var(--cor-acento-hover);
         }

        /* --- Responsividade da Página de Perfil/Meus Dados --- */
        @media (max-width: 992px) {
            .data-card {
                margin-left: 0; /* Ajuste para 992px */
                width: 100%;
            }
            .profile-layout { flex-direction: column; margin-left: 16px; margin-right: 16px; }
            .profile-sidebar { width: 100%; margin-bottom: 20px; }
            .profile-title { margin-left: 16px;}
            .profile-content { width: 100%; }
        }

        @media (max-width: 768px) {
            .data-grid { grid-template-columns: 1fr; gap: 15px; }
            .submit-button-container { text-align: center; }
            .btn-salvar { width: 100%; }
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
                            <a href="pedidos.php"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                                <span>Meus pedidos</span>
                            </a>
                        </li>
                        <li>
                            <a href="meus-dados.php" class="active"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4z" /></svg>
                                <span>Meus dados</span>
                            </a>
                        </li>
                        <li>
                            <a href="enderecos.php"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                <span>Meus endereços</span>
                            </a>
                        </li>
                          <li>
                            <a href="lista-desejos.php"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>
                                <span>Lista de desejos</span>
                            </a>
                        </li>
                        <li>
                            <a href="suporte.php"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                                <span>Suporte</span>
                            </a>
                        </li>
                        <li>
                            <a href="alterar-senha.php"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
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
                    <h2>Meus dados</h2>

                    <?php if (!empty($success_message)): ?>
                        <div class="general-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>
                    <?php if (isset($errors['db'])): ?>
                        <div class="general-error"><?php echo $errors['db']; ?></div>
                    <?php endif; ?>

                    <div class="tipo-pessoa-tabs">
                        <button type="button" class="active" id="btn-pf">Pessoa Física</button>
                        <button type="button" id="btn-pj" disabled>Pessoa Jurídica (Em breve)</button>
                    </div>

                    <form action="meus-dados.php" method="POST" id="form-meus-dados">
                        <div class="data-grid">
                            <div class="form-group">
                                <label for="nome"><span>*</span>Nome completo</label>
                                <input type="text" id="nome" name="nome" value="<?php echo htmlspecialchars($user_data['nome']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="data_nascimento">Data de nascimento</label>
                                <input type="date" id="data_nascimento" name="data_nascimento" value="<?php echo htmlspecialchars($user_data['data_nascimento'] ?? ''); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="email"><span>*</span>E-mail</label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required class="<?php echo isset($errors['email']) ? 'error-border' : ''; ?>">
                                <?php if(isset($errors['email'])): ?><span class="error-message"><?php echo $errors['email']; ?></span><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="telefone_fixo">Telefone fixo</label>
                                <input type="tel" id="telefone_fixo" name="telefone_fixo" value="<?php echo htmlspecialchars($user_data['telefone_fixo'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="cpf"><span>*</span>CPF</label>
                                <input type="text" id="cpf" name="cpf" value="<?php echo formatCPF($user_data['cpf']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="telefone_celular">Telefone celular</label>
                                <input type="tel" id="telefone_celular" name="telefone_celular" value="<?php echo htmlspecialchars($user_data['telefone_celular'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group-checkbox">
                            <input type="checkbox" id="receber_promocoes" name="receber_promocoes" value="1" <?php echo !empty($user_data['receber_promocoes']) ? 'checked' : ''; ?>>
                            <label for="receber_promocoes">Desejo receber e-mails promocionais</label>
                        </div>

                        <div class="password-section">
                            <div class="form-group">
                                <label for="senha_atual"><span>*</span>Senha Atual</label>
                                <input type="password" id="senha_atual" name="senha_atual" required class="<?php echo isset($errors['senha_atual']) ? 'error-border' : ''; ?>">
                                <p>Para salvar as alterações, digite sua senha atual.</p>
                                <?php if(isset($errors['senha_atual'])): ?><span class="error-message"><?php echo $errors['senha_atual']; ?></span><?php endif; ?>
                            </div>
                        </div>

                        <div class="submit-button-container">
                            <button type="submit" name="salvar_dados" class="btn-salvar">Salvar Alterações</button>
                        </div>
                    </form>

                </div>

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
                                   <label for="modal_login_email_header_perfil_dados">E-mail ou CPF/CNPJ:</label>
                                   <input type="text" id="modal_login_email_header_perfil_dados" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                             </div>
                             <div class="form-group">
                                   <label for="modal_login_senha_header_perfil_dados">Senha:</label>
                                   <input type="password" id="modal_login_senha_header_perfil_dados" name="modal_login_senha" placeholder="Digite sua senha" required>
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
                <p>Tem certeza que deseja excluir este item?</p>
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

            // --- Lógica Específica de meus-dados.php ---
            $('#cpf').mask('000.000.000-00');
            $('#telefone_fixo').mask('(00) 0000-0000');

            // Máscara de celular dinâmica (8 ou 9 dígitos)
            var maskBehavior = function (val) {
              return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
            },
            options = {
              onKeyPress: function(val, e, field, options) {
                 field.mask(maskBehavior.apply({}, arguments), options);
                }
            };
            $('#telefone_celular').mask(maskBehavior, options);

            // (Lógica de Modal de Erro de DB)
            <?php if (isset($errors['db']) && !empty($page_alert_message)): ?>
                const infoModal = document.getElementById('info-modal');
                const infoModalMessage = document.getElementById('info-modal-message');
                if (infoModal && infoModalMessage) {
                   infoModalMessage.innerHTML = <?php echo json_encode($page_alert_message); ?>;
                   infoModal.classList.add('modal-open');
                }
             <?php endif; ?>
          });
    </script>

</body>
</html>