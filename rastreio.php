<?php
// rastreio.php - Página que lista todos os pedidos do usuário logado que estão EM TRÂNSITO ou ENTREGUES.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 1. CONFIGURAÇÃO E SEGURANÇA ---
require_once 'config/db.php';
require_once 'funcoes.php'; // Para carregarConfigApi, formatarDataHoraBR, etc.

// Carrega as constantes globais (NOME_DA_LOJA)
if (isset($pdo)) {
    carregarConfigApi($pdo);
}

// Define o get_status_class que é usado em todo o sistema (se não estiver em funcoes.php)
if (!function_exists('get_status_class_front')) {
    function get_status_class_front($status) {
        $s = strtoupper($status ?? '');
        if (in_array($s, ['ENTREGUE'])) return 'status-success';
        if (in_array($s, ['EM TRANSPORTE'])) return 'status-info';
        return 'status-warning';
    }
}


$page_title = "Rastreios Ativos";
$user_id = $_SESSION['user_id'] ?? null;
$pedidos_em_transporte = [];
$error_message = '';

// ▼▼▼ MODIFICADO: LÓGICA DE AUTENTICAÇÃO ▼▼▼
$auth_error_message = ''; // Nova variável

if (!$user_id) {
    // Em vez de redirecionar, define a mensagem de erro de login
    $auth_error_message = 'Para acessar seus rastreios, você precisa estar logado.';

    // Removemos o header() e exit() daqui
    // $_SESSION['redirect_url'] = basename($_SERVER['REQUEST_URI']);
    // header('Location: login.php');
    // exit;
} else {
// ▲▲▲ FIM DA MODIFICAÇÃO ▲▲▲

    // 2. BUSCA DE TODOS OS PEDIDOS (SÓ EXECUTA SE ESTIVER LOGADO)
    try {
        $sql = "SELECT
                    p.id AS pedido_id,
                    p.status AS pedido_status,
                    p.criado_em,
                    r.transportadora_nome,
                    r.codigo_rastreio,
                    r.link_rastreio,
                    r.data_envio
                FROM pedidos p
                LEFT JOIN rastreio r ON p.id = r.pedido_id
                WHERE p.usuario_id = :user_id
                AND p.status IN ('EM TRANSPORTE', 'ENTREGUE')
                ORDER BY p.criado_em DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id]);
        $pedidos_em_transporte = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pedidos_em_transporte)) {
            $error_message = 'Você não possui pedidos com rastreamento ativo no momento.';
        }

    } catch (PDOException $e) {
        error_log("Erro no DB ao buscar lista de rastreio: " . $e->getMessage());
        $error_message = 'Erro interno ao carregar a lista de pedidos. Tente novamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo defined('NOME_DA_LOJA') ? NOME_DA_LOJA : 'Sua Loja'; ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /*
        ==========================================================
        CSS ESPECÍFICO DESTA PÁGINA (Apenas estrutura e layout)
        ==========================================================
        */

        /* Fallbacks, caso o header.php não carregue as vars */
        :root {
            --green-accent: #9ad700;
            --cor-acento-hover: #8cc600;
            --text-color-dark: #333;
            --text-color-medium: #555;
            --border-color-medium: #ccc;
            --success-color: #28a745;
            --error-color: #dc3545;
            --warning-color: #ffc107;
            --bg-light: #f9f9f9;
        }

        /* Layout Container */
        .rastreio-container {
            max-width: 900px;
            margin: 40px auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .rastreio-container h1 {
            font-size: 1.8em;
            color: var(--text-color-dark);
            border-bottom: 2px solid var(--border-color-medium);
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        /* Lista de Itens de Rastreio */
        .rastreio-list-item {
            border: 1px solid var(--border-color-medium);
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            background-color: #fff;
        }
        .rastreio-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px dashed var(--border-color-medium);
        }
        .rastreio-header h2 {
            margin: 0;
            font-size: 1.2em;
            color: var(--text-color-dark);
        }

        /* Status Tags (CORES DINÂMICAS) */
        .rastreio-status {
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.9em;
            text-transform: uppercase;
        }
        .status-success { background-color: #d4edda; color: var(--success-color); } /* ENTREGUE */
        .status-info { background-color: #e3f2fd; color: var(--primary-color); } /* EM TRANSPORTE */
        .status-warning { background-color: #fff3cd; color: var(--warning-color); } /* Para não cadastrado */


        .rastreio-details p {
            margin: 0 0 8px 0;
            font-size: 0.9em;
            line-height: 1.6;
            color: var(--text-color-medium);
        }
        .rastreio-details strong {
            color: var(--text-color-dark);
            display: inline-block;
            min-width: 120px;
        }

        /* Botão/Link de Rastreio */
        .rastreio-link, .rastreio-details a {
            display: block;
            width: fit-content;
            margin-top: 10px;
            padding: 8px 15px;
            background-color: var(--green-accent); /* Usa a cor de acento */
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.9em;
        }
        .rastreio-link:hover, .rastreio-details a:hover {
             background-color: var(--cor-acento-hover); /* Puxando cor de hover do header */
             color: #fff;
        }
        .rastreio-details .no-info {
            color: var(--warning-color);
            font-style: italic;
        }

        /* Alertas (CORES DINÂMICAS) */
        .alert-error, .alert-info {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .alert-error { background-color: #f8d7da; color: var(--error-color); border: 1px solid #f5c6cb; }
        .alert-info { background-color: #e6f7ff; color: var(--text-color-dark); border: 1px solid #b3e7ff; }

        /* ▼▼▼ NOVO CSS ▼▼▼ */
        .auth-required-box {
            text-align: center;
            padding: 40px 20px;
            background-color: #fcfcfc;
            border: 1px dashed var(--border-color-medium, #ccc);
            border-radius: 8px;
        }
        .auth-required-box p {
            font-size: 1.1em;
            color: var(--text-color-medium, #555);
            margin-bottom: 25px;
        }
        .auth-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        /* Botões puxarão o estilo global .btn do header.php */
        .auth-buttons .btn {
            min-width: 180px;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 4px;
        }
        /* ▲▲▲ FIM DO NOVO CSS ▲▲▲ */


        /* Mobile */
        @media (max-width: 600px) {
            .rastreio-container { margin: 20px 10px; padding: 20px; }
            .rastreio-header { flex-direction: column; align-items: flex-start; }
            .rastreio-header h2 { margin-bottom: 5px; }
            .rastreio-details strong { min-width: 0; display: block; margin-bottom: 5px; }
            .rastreio-details p { display: flex; flex-direction: column; }
        }
    </style>
</head>
<body class="rastreio-page">

    <?php include 'templates/header.php'; ?>

    <main class="main-content container">

        <div class="rastreio-container">

            <h1>Rastreamento de Pedidos Enviados</h1>

            <?php if (!empty($auth_error_message)): ?>
                <div class="auth-required-box">
                    <p><?php echo htmlspecialchars($auth_error_message); ?></p>
                    <div class="auth-buttons">
                        <a href="login.php?redirect=rastreio.php" class="btn btn-primary">Fazer Login</a>
                        <a href="cadastro.php" class="btn btn-secondary">Criar Conta</a>
                    </div>
                </div>

            <?php elseif ($error_message): ?>
                <div class="alert-info">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <a href="pedidos.php" class="rastreio-link" style="background-color: #6c757d;">Ver Todos os Meus Pedidos</a>

            <?php elseif (!empty($pedidos_em_transporte)): ?>
                <div class="alert-info">
                    <p>Abaixo estão todos os seus pedidos em trânsito ou recentemente entregues. Clique no link para o rastreamento detalhado, se disponível.</p>
                </div>

                <?php foreach ($pedidos_em_transporte as $pedido):
                    // Define a classe CSS do status (usa a função local)
                    $status_class = get_status_class_front($pedido['pedido_status']);
                ?>
                <div class="rastreio-list-item">
                    <div class="rastreio-header">
                        <h2>Pedido #<?php echo htmlspecialchars($pedido['pedido_id']); ?></h2>
                        <span class="rastreio-status <?php echo $status_class; ?>">
                            <?php echo htmlspecialchars($pedido['pedido_status']); ?>
                        </span>
                    </div>

                    <div class="rastreio-details">

                        <?php if (empty($pedido['codigo_rastreio'])): ?>
                            <p class="no-info">Ainda não há dados de rastreio cadastrados para este pedido. Contate o suporte se houver atraso.</p>
                        <?php else: ?>

                            <p><strong>Transportadora:</strong> <?php echo htmlspecialchars($pedido['transportadora_nome']); ?></p>
                            <p><strong>Código:</strong> <?php echo htmlspecialchars($pedido['codigo_rastreio']); ?></p>
                            <p><strong>Data de Envio:</strong> <?php echo formatarDataHoraBR($pedido['data_envio'], false); ?></p>

                            <?php if (!empty($pedido['link_rastreio'])): ?>
                                <a href="<?php echo htmlspecialchars($pedido['link_rastreio']); ?>"
                                   target="_blank"
                                   style="max-width: 300px;">Ver Rastreio Detalhado</a>
                            <?php else: ?>
                                <p class="no-info" style="font-size: 0.85em; margin-top: 5px;">Link direto não disponível. Use o código acima no site da transportadora.</p>
                            <?php endif; ?>

                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <a href="pedidos.php" class="rastreio-link" style="background-color: #6c757d;">Ver Todos os Meus Pedidos</a>

            <?php endif; ?>

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
                       <form action="login.php" method="POST">
                             <input type="hidden" name="redirect_url" value="rastreio.php">
                             <div class="form-group">
                                   <label for="modal_login_email_header_rastreio">E-mail ou CPF/CNPJ:</label>
                                   <input type="text" id="modal_login_email_header_rastreio" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                             </div>
                             <div class="form-group">
                                   <label for="modal_login_senha_header_rastreio">Senha:</label>
                                   <input type="password" id="modal_login_senha_header_rastreio" name="modal_login_senha" placeholder="Digite sua senha" required>
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
                  <a href="#" class="cart-continue-btn" data-dismiss="modal">< Continuar Comprando</a>
                  <a href="checkout.php" class="btn btn-primary cart-checkout-btn">COMPRAR AGORA</a>
             </div>
         </div>
     </div>

    <div class="modal-overlay" id="info-modal"></div>
    <div class="modal-overlay" id="delete-confirm-modal"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    </body>
</html>