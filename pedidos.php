<?php
// pedidos.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php';

$is_logged_in = isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
$pedidos = [];
$highlight_order_id = (int)($_GET['order_id'] ?? 0); // Para destacar o pedido recém-criado
$page_alert_message = '';
$errors = [];

if ($is_logged_in) {
    $user_id = $_SESSION['user_id'];
    try {
        // Busca todos os pedidos do usuário, mais recente primeiro
        $stmt = $pdo->prepare("
            SELECT id, valor_total, status, criado_em
            FROM pedidos
            WHERE usuario_id = :user_id
            ORDER BY criado_em DESC
        ");
        $stmt->execute(['user_id' => $user_id]);
        $pedidos = $stmt->fetchAll();
    } catch (PDOException $e) {
        $errors['db'] = "Erro ao buscar pedidos: " . $e->getMessage();
        $page_alert_message = $errors['db'];
    }
} else {
    // Se não estiver logado, redireciona
    header('Location: login.php?redirect=pedidos.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Pedidos - <?php echo htmlspecialchars($_SESSION['user_nome']); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS ESPECÍFICO DESTA PÁGINA (Layout Perfil + Tabela Pedidos)
           (Todo o CSS global de header, footer, modais foi REMOVIDO)
           ========================================================== */

        /* --- Layout da Página de Perfil --- */
        .profile-title {
            font-size: 1.8em;
            font-weight: bold;
            color: var(--text-color-dark);
            margin: 15px 0 25px 0;
        }
        .profile-layout {
            display: grid;
            grid-template-columns: 260px 1fr; /* Coluna fixa para sidebar */
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
            top: 150px; /* Ajuste conforme a altura do seu header sticky */
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

        /* --- Estado Vazio (Pedidos) --- */
        .empty-state {
            text-align: center;
            padding: 40px 0;
            color: var(--text-color-medium);
        }
        .empty-state .icon {
            font-size: 3.5em;
            font-weight: bold;
            color: var(--border-color-medium);
            line-height: 1;
            margin-bottom: 15px;
        }
        .empty-state p { margin: 0; font-size: 1.1em; }
        .empty-state a { text-decoration: underline; font-weight: bold; }
        .general-error { color: var(--error-color); font-weight: bold; text-align: center; margin-bottom: 15px; }

        /* --- Tabela de Pedidos --- */
        .pedidos-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }
        .pedidos-table th, .pedidos-table td {
            text-align: left;
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color-light);
        }
        .pedidos-table th {
            background-color: #fcfcfc;
            color: var(--text-color-medium);
            font-weight: bold;
        }
        .pedidos-table tr.highlight {
            background-color: #f0f9eb; /* Verde bem clarinho */
            border: 1px solid var(--success-color);
        }
        .pedidos-table .status {
            font-weight: bold;
        }
        /* Status (Exemplos) */
        .status-PENDENTE { color: #f39c12; }
        .status-APROVADO { color: var(--success-color); }
        .status-ENVIADO { color: var(--text-color-dark); }
        .status-CANCELADO { color: var(--error-color); }

        .btn-ver-detalhes {
            font-size: 0.9em;
            font-weight: bold;
            color: var(--green-accent);
            text-decoration: underline;
        }

        /* --- Responsividade (Apenas do Perfil/Pedidos) --- */
        @media (max-width: 992px) {
            .profile-layout { grid-template-columns: 1fr; }
            .profile-sidebar { position: static; top: auto; width: 100%; margin-bottom: 20px; }
            .data-card { width: 100%; }
        }

        @media (max-width: 768px) {
            /* Pedidos Mobile */
             .pedidos-table {
                display: block;
                width: 100%;
            }
            .pedidos-table thead { display: none; } /* Esconde cabeçalho */
            .pedidos-table tbody { display: block; width: 100%; }
            .pedidos-table tr {
                display: block;
                width: 100%;
                padding: 15px 0;
                border-bottom: 1px solid var(--border-color-light);
            }
             .pedidos-table tr.highlight {
                 border: 2px solid var(--success-color);
                 padding: 14px;
                 border-radius: 5px;
             }
            .pedidos-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 6px 0;
                border: none;
            }
            .pedidos-table td::before {
                content: attr(data-label);
                font-weight: bold;
                color: var(--text-color-medium);
                padding-right: 15px;
            }
            .pedidos-table .btn-ver-detalhes {
                width: 100%;
                text-align: center;
                margin-top: 10px;
                padding: 10px;
                background: var(--green-accent);
                color: #fff;
                border-radius: 4px;
                text-decoration: none;
                font-size: 1em;
            }
            .pedidos-table td:last-child { /* Célula do botão */
                display: block;
            }
        }
    </style>
</head>
<body class="profile-page">

    <?php include 'templates/header.php'; ?>

    <main class="main-content container">

        <h1 class="profile-title">Meus Pedidos</h1>

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
                            <a href="pedidos.php" class="active"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                                <span>Meus pedidos</span>
                            </a>
                        </li>
                        <li>
                            <a href="meus-dados.php"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4z" /></svg>
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
                    <h2>Meus Pedidos</h2>

                    <?php if (isset($errors['db'])): // Mostra erros de DB aqui ?>
                        <div class="general-error"><?php echo $errors['db']; ?></div>
                    <?php endif; ?>

                    <?php if (empty($pedidos) && empty($errors)): ?>

                        <div class="empty-state">
                            <div class="icon">:(</div>
                            <p>Você ainda não fez nenhum pedido.</p>
                            <a href="index.php" class="btn btn-primary" style="margin-top: 15px; border-radius: 4px;">Começar a comprar</a>
                        </div>

                    <?php elseif (!empty($pedidos)): ?>

                        <div style="overflow-x: auto;">
                            <table class="pedidos-table">
                                <thead>
                                    <tr>
                                        <th>Pedido</th>
                                        <th>Data</th>
                                        <th>Valor Total</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pedidos as $pedido): ?>
                                        <tr class="<?php echo $pedido['id'] == $highlight_order_id ? 'highlight' : ''; ?>">
                                            <td data-label="Pedido">#<?php echo $pedido['id']; ?></td>
                                            <td data-label="Data"><?php echo date('d/m/Y', strtotime($pedido['criado_em'])); ?></td>
                                            <td data-label="Valor">R$ <?php echo number_format($pedido['valor_total'], 2, ',', '.'); ?></td>
                                            <td data-label="Status">
                                                <span class="status status-<?php echo htmlspecialchars($pedido['status']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst(strtolower($pedido['status']))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="detalhes_pedidos.php?id=<?php echo $pedido['id']; ?>" class="btn-ver-detalhes">Ver Detalhes</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php endif; ?>
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
                             <label for="modal_login_email_header_ped">E-mail ou CPF/CNPJ:</label>
                             <input type="text" id="modal_login_email_header_ped" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                        </div>
                        <div class="form-group">
                             <label for="modal_login_senha_header_ped">Senha:</label>
                             <input type="password" id="modal_login_senha_header_ped" name="modal_login_senha" placeholder="Digite sua senha" required>
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
            </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        $(document).ready(function(){
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