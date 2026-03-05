<?php
// login.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // <-- DEVE SER A PRIMEIRA LINHA
}
require_once 'config/db.php'; // Garante $pdo para includes

$login_error_modal = ''; // Erro para o modal de login (ex: senha errada)
$alert_modal_title = ''; // Título para o modal de Alerta (ex: Conta Bloqueada)
$alert_modal_message = ''; // Mensagem para o modal de Alerta

// Verifica se há uma mensagem de sucesso vinda do cadastro.php
$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Limpa a mensagem da sessão
}

// --- LÓGICA DE LOGIN REAL ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['modal_login_email'], $_POST['modal_login_senha'])) {

    $login_identifier = trim($_POST['modal_login_email']);
    $senha = $_POST['modal_login_senha'];

    // AJUSTADO: Busca as novas colunas
    $sql = "SELECT id, nome, email, senha, is_bloqueado, motivo_bloqueio FROM usuarios WHERE ";
    $params = [];

    $cpf_numeros = preg_replace('/[^0-9]/', '', $login_identifier);

    if (filter_var($login_identifier, FILTER_VALIDATE_EMAIL)) {
        $sql .= "email = :identifier";
        $params[':identifier'] = strtolower($login_identifier);
    } elseif (strlen($cpf_numeros) === 11) {
        $sql .= "cpf = :identifier";
        $params[':identifier'] = $cpf_numeros;
    } else {
        $login_error_modal = "Formato de e-mail ou CPF inválido.";
    }

    $user = null;
    if (empty($login_error_modal)) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch as associative array
        } catch (PDOException $e) {
            $login_error_modal = "Erro no banco de dados. Tente novamente.";
            error_log("Login Error: " . $e->getMessage());
        }
    }

    // --- NOVA LÓGICA DE VERIFICAÇÃO ---
    if ($user) {
        // 1. VERIFICA SE ESTÁ BLOQUEADO
        if ($user['is_bloqueado']) {
            $alert_modal_title = "Conta Bloqueada";
            $alert_modal_message = htmlspecialchars($user['motivo_bloqueio'] ?? 'Sua conta foi suspensa. Entre em contato com o suporte.');

        // 2. VERIFICA A SENHA
        } elseif (password_verify($senha, $user['senha'])) {
            // --- LOGIN BEM SUCEDIDO ---
            session_regenerate_id(true);

            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nome'] = $user['nome'];
            $_SESSION['user_email'] = $user['email'];

            // Redireciona para a URL salva, ou para a index.php
            $redirect_url = $_SESSION['redirect_url'] ?? 'index.php';
            unset($_SESSION['redirect_url']); // Limpa a URL de redirecionamento

            header("Location: " . $redirect_url);
            exit;

        } else {
            // 3. SENHA INCORRETA
            $login_error_modal = "E-mail, CPF ou senha incorretos.";
        }
    } else {
        // 4. USUÁRIO NÃO ENCONTRADO
        if (empty($login_error_modal)) {
            $login_error_modal = "E-mail, CPF ou senha incorretos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login / Cadastro</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS ESPECÍFICO DESTA PÁGINA (Layout do Login)
           ========================================================== */

        body.login-page { background-color: #f9f9f9; }
        .login-content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            width: 100%;
            padding: 50px 0;
            background-color: #fff;
        }
        .login-wrapper {
            display: flex;
            justify-content: center;
            width: 100%;
            max-width: 1000px;
            gap: 0;
            background-color: #fff;
            border: 1px solid var(--border-color-light);
            border-radius: 5px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin: 0 15px;
            box-sizing: border-box;
        }
        .login-section, .register-section {
            padding: 60px 80px;
            text-align: center;
            width: 50%;
            box-sizing: border-box;
        }
        .login-section {
            border-right: 1px solid var(--border-color-light);
        }
        .login-section h2, .register-section h2 {
            font-size: 1.3em;
            margin-top: 0;
            margin-bottom: 10px;
            color: var(--text-color-dark);
            font-weight: normal;
        }
        .login-section p, .register-section p {
            font-size: 0.85em;
            color: var(--text-color-medium);
            margin-bottom: 35px;
            line-height: 1.5;
        }

        /* Mensagem de Sucesso (vinda do cadastro) */
        .success-message-box {
            background-color: #f0f9eb;
            border: 1px solid var(--success-color);
            color: var(--success-color);
            padding: 15px 20px;
            border-radius: 5px;
            margin: 0 auto 30px auto;
            max-width: 90%;
            text-align: center;
            font-weight: bold;
        }

        /* --- Estilo Específico de Modais (para Alertas) --- */
        .alert-modal-body { text-align: center; padding: 30px; }
        .alert-modal-body .icon {
            width: 50px; height: 50px; margin: 0 auto 20px auto; border-radius: 50%;
            background-color: var(--error-color); color: #fff; display: flex;
            align-items: center; justify-content: center; font-size: 2em; font-weight: bold; line-height: 1;
        }
        .alert-modal-body .modal-title { font-size: 1.5em; color: var(--text-color-dark); margin-bottom: 10px; }
        .alert-modal-body p { font-size: 1em; color: var(--text-color-medium); line-height: 1.6; margin-bottom: 25px; }

        /* --- Responsividade --- */
        @media (max-width: 768px) {
             .login-wrapper { flex-direction: column; align-items: center; gap: 0; border: none; box-shadow: none; background: none; width: 100%; margin: 0; }
             .login-section, .register-section { width: 100%; max-width: 450px; padding: 30px; background-color: #fff; border: 1px solid var(--border-color-light); border-radius: 5px; box-shadow: 0 3px 10px rgba(0,0,0,0.08); }
             .login-section { border-right: none; border-bottom: 1px solid var(--border-color-light); margin-bottom: 30px; }
        }
    </style>
</head>
<body class="login-page">

    <div class="sticky-header-wrapper">
        <?php include 'templates/header.php'; ?>
    </div>

    <main class="login-content">

        <?php if (!empty($success_message)): ?>
            <div class="success-message-box">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <div class="login-wrapper">
            <section class="login-section">
                <h2>Já sou cadastrado</h2>
                <p>Para se autenticar, informe seu e-mail ou CPF/CNPJ</p>
                <button type="button" class="btn btn-primary" id="show-login-modal-specific">Entrar</button>
            </section>
            <section class="register-section">
                <h2>Esta é minha primeira compra</h2>
                <p>O cadastro em nossa loja é simples e rápido</p>
                <a href="cadastro.php" class="btn btn-secondary">Cadastre-se</a>
            </section>
        </div>
    </main>

    <?php include 'templates/footer.php'; ?>

    <div class="modal-overlay" id="login-modal">
        <div class="modal-content">
             <div class="modal-header">
                 <h3 class="modal-title">Identifique-se</h3>
                 <button class="modal-close" data-dismiss="modal">&times;</button>
             </div>
             <div class="modal-body">
                 <form action="login.php" method="POST" id="login-form-main">
                     <div class="form-group">
                         <label for="modal_login_email">E-mail ou CPF/CNPJ:</label>
                         <input type="text" id="modal_login_email" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                     </div>

                     <div class="form-group">
                         <label for="modal_login_senha">Senha:</label>
                         <input type="password" id="modal_login_senha" name="modal_login_senha" placeholder="Digite sua senha" required>
                     </div>

                     <?php if (!empty($login_error_modal)): ?>
                         <p class="error-message" style="color: var(--error-color); text-align: left; font-size: 0.9em;"><?php echo $login_error_modal; ?></p>
                     <?php endif; ?>

                     <div class="modal-footer">
                         <button type="submit" class="btn btn-primary">Continuar</button>
                     </div>
                 </form>
             </div>
        </div>
    </div>

    <div class="modal-overlay" id="alert-modal">
        <div class="modal-content">
             <div class="modal-body alert-modal-body">
                 <div class="icon">!</div>
                 <h3 class="modal-title" id="alert-modal-title"></h3>
                 <p id="alert-modal-message"></p>
                 <button class="btn btn-primary" data-dismiss="modal">Entendi</button>
             </div>
        </div>
    </div>

    <div class="cart-overlay" id="cart-overlay"></div>
    <div class="cart-modal" id="cart-modal">
        <div class="cart-header">
            <div class="cart-header-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                <span>Sacola de Compras</span>
            </div>
            <button class="cart-close-btn" data-dismiss="modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
                     <path fill-rule="evenodd" d="M4 8a.5.5 0 0 1 .5-.5h5.793L8.146 5.354a.5.5 0 1 1 .708-.708l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708-.708L10.293 8.5H4.5A.5.5 0 0 1 4 8z"/>
                </svg>
            </button>
        </div>
        <div class="cart-body" id="cart-body-content">
             </div>
        <div class="cart-footer">
            <div class="cart-footer-info">
                <p>* Calcule seu frete na página de finalização.</p>
                <p>* Insira seu cupom de desconto na página de finalização.</p>
            </div>
            <div class="cart-footer-actions">
                 <a href="#" class="cart-continue-btn" data-dismiss="modal">
                    < Continuar Comprando
                 </a>
                 <a href="checkout.php" class="btn btn-primary cart-checkout-btn">COMPRAR AGORA</a>
            </div>
        </div>
    </div>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loginModal = document.getElementById('login-modal');
            const showLoginBtn = document.getElementById('show-login-modal-specific');
            const alertModal = document.getElementById('alert-modal');

            // 1. Lógica para abrir o Modal de Login (botão "Entrar" na página)
            if (showLoginBtn && loginModal) {
                showLoginBtn.addEventListener('click', function() {
                    loginModal.classList.add('modal-open');
                });
            }

            // 2. Lógica para reabrir/preencher o Modal de Login (se houver erro PHP)
            <?php if (!empty($login_error_modal)): ?>
                const emailInput = document.getElementById('modal_login_email');
                const lastEmail = localStorage.getItem('last_login_attempt') || '';

                // Preenche o campo e abre o modal em caso de erro
                if (emailInput) emailInput.value = lastEmail;
                if (loginModal) loginModal.classList.add('modal-open');
            <?php endif; ?>

            // 3. Lógica para exibir o Modal de Alerta (Conta Bloqueada ou Mensagem de Sucesso)
            <?php if (!empty($alert_modal_message)): ?>
                // Preenche os dados do Modal de Alerta
                const alertTitleEl = document.getElementById('alert-modal-title');
                const alertMessageEl = document.getElementById('alert-modal-message');
                if (alertTitleEl && alertMessageEl) {
                    alertTitleEl.textContent = "<?php echo $alert_modal_title; ?>";
                    alertMessageEl.textContent = "<?php echo $alert_modal_message; ?>";
                }
                if(alertModal) alertModal.classList.add('modal-open');
            <?php endif; ?>

            // 4. Lógica para exibir o Modal de Sucesso (após cadastro)
            <?php if (!empty($success_message)): ?>
                // Reutilizamos o alert modal
                const alertTitleEl = document.getElementById('alert-modal-title');
                const alertMessageEl = document.getElementById('alert-modal-message');
                if (alertTitleEl && alertMessageEl) {
                    alertTitleEl.textContent = "Sucesso!";
                    alertMessageEl.innerHTML = "<?php echo htmlspecialchars($success_message); ?>";
                }
                if(alertModal) alertModal.classList.add('modal-open');
            <?php endif; ?>


            // 5. Salva o último input de email/cpf no localStorage antes de submeter
            const mainLoginForm = document.getElementById('login-form-main');
            if (mainLoginForm) {
                mainLoginForm.addEventListener('submit', function() {
                    const identifierInput = document.getElementById('modal_login_email');
                    if (identifierInput) {
                        localStorage.setItem('last_login_attempt', identifierInput.value);
                    }
                });
            }
        });
    </script>
</body>
</html>