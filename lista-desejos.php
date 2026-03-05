<?php
// lista-desejos.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: login.php?redirect=lista-desejos.php');
    exit;
}
require_once 'config/db.php';

$user_id = $_SESSION['user_id'];
$user_nome = $_SESSION['user_nome'];
$errors = [];
$success_message = '';
$page_alert_message = '';

// --- AÇÃO: DELETAR PRODUTO DA LISTA (GET) ---
if (isset($_GET['delete'])) {
    $produto_id_del = (int)$_GET['delete'];
    try {
        $sql_del = "DELETE FROM lista_desejos WHERE produto_id = :pid AND usuario_id = :uid";
        $stmt_del = $pdo->prepare($sql_del);
        $stmt_del->execute(['pid' => $produto_id_del, 'uid' => $user_id]);
        header('Location: lista-desejos.php?deleted=true');
        exit;
    } catch (PDOException $e) {
        $page_alert_message = "Erro ao remover produto: " . $e->getMessage();
        $errors['db'] = $page_alert_message;
    }
}
if (isset($_GET['deleted']) && empty($errors)) { $success_message = "Produto removido da Lista de Desejos com sucesso!"; }

// --- BUSCAR PRODUTOS NA LISTA DE DESEJOS ---
$lista_desejos = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            ld.produto_id, ld.adicionado_em,
            p.nome AS produto_nome, p.preco, p.imagem_url,
            p.estoque, p.ativo -- Adicionado para verificar disponibilidade
        FROM lista_desejos ld
        JOIN produtos p ON ld.produto_id = p.id
        WHERE ld.usuario_id = :uid
        ORDER BY ld.adicionado_em DESC
    ");
    $stmt->execute(['uid' => $user_id]);
    $lista_desejos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
     $errors['db'] = "Erro ao buscar a Lista de Desejos: " . $e->getMessage();
     if(empty($page_alert_message)) { $page_alert_message = $errors['db']; }
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
    error_log("Erro ao buscar cor do hover no DB em lista-desejos.php: " . $e->getMessage());
    // Mantém o valor padrão em caso de erro no DB
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Desejos - <?php echo htmlspecialchars($user_nome); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS ESPECÍFICO DESTA PÁGINA (Layout do Perfil)
           ========================================================== */
        /* Adicionando Variáveis CSS */
        :root {
            /* Definindo variáveis essenciais */
            --green-accent: #a4d32a;
            --text-color-dark: #333;
            --text-color-medium: #666;
            --text-color-light: #999;
            --border-color-light: #eee;
            --error-color: #ff4444;
            --success-color: #5cb85c;

            /* NOVO: Variável da cor de hover vinda do DB */
            --cor-acento-hover: <?php echo $cor_acento_hover; ?>;
        }

        .profile-title { font-size: 1.8em; font-weight: bold; color: var(--text-color-dark); margin: 15px 0 25px 0; }
        .profile-layout { display: flex; gap: 30px; align-items: flex-start; }
        .profile-sidebar { width: 260px; flex-shrink: 0; background-color: #fff; border: 1px solid var(--border-color-light); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
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
        .data-card { background-color: #fff; border: 1px solid var(--border-color-light); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); padding: 30px; margin-bottom: 30px; position: relative; }
        .data-card h2 { font-size: 1.3em; font-weight: bold; color: var(--text-color-dark); margin-top: 0; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color-light); }

        /* --- Estilos da Lista de Desejos --- */
        .wishlist-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 20px; }
        .wishlist-item { display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-color-light); border-radius: 8px; padding: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .item-info { display: flex; align-items: center; gap: 15px; flex-grow: 1; }
        .item-info img { width: 80px; height: 80px; object-fit: contain; border: 1px solid var(--border-color-light); border-radius: 4px; flex-shrink: 0; }
        .item-details h5 { font-size: 1em; font-weight: bold; margin: 0 0 5px 0; color: var(--text-color-dark); }
        .item-details .price { font-size: 1.1em; font-weight: bold; color: var(--green-accent); margin-top: 5px; }
        .wishlist-actions { display: flex; flex-direction: column; gap: 10px; text-align: right; flex-shrink: 0; }
        .btn-action { font-size: 0.9em; font-weight: bold; text-decoration: underline; transition: color 0.2s; cursor: pointer; }
        .btn-add-to-cart { color: var(--green-accent); }

        /* ALTERADO: Usando a variável do DB */
        .btn-add-to-cart:hover { color: var(--cor-acento-hover); }

        .btn-delete { color: var(--error-color); }
        .btn-delete:hover { color: #b02a37; }
        .btn-action.disabled { color: var(--text-color-light); text-decoration: line-through; cursor: not-allowed; }

        .general-success { background-color: #f0f9eb; border: 1px solid var(--success-color); color: var(--success-color); padding: 15px; border-radius: 4px; margin-bottom: 20px; text-align: center; }
        .general-error { color: var(--error-color); font-weight: bold; text-align: center; margin-bottom: 15px; }

        /* --- Responsividade (Apenas do Perfil/Lista) --- */
        @media (max-width: 992px) {
            .data-card {
                margin-left: 0;
                width: 100%;
            }
            .profile-content{ flex-grow: 1; margin-left: 0; }
            .profile-layout { flex-direction: column; margin-left: 16px; margin-right: 16px; }
            .profile-title{ margin-left: 16px;}
            .profile-sidebar { width: 100%; margin-bottom: 20px; }
        }

        @media (max-width: 768px) {
            .wishlist-item { flex-direction: column; align-items: flex-start; padding: 15px; }
            .item-info { width: 100%; margin-bottom: 15px; border-bottom: 1px solid var(--border-color-light); padding-bottom: 10px; }
            .wishlist-actions { width: 100%; flex-direction: row; justify-content: space-around; text-align: center; gap: 5px; }
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
                        <li><a href="perfil.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg><span>Minha conta</span></a></li>
                        <li><a href="pedidos.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg><span>Meus pedidos</span></a></li>
                        <li><a href="meus-dados.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4z" /></svg><span>Meus dados</span></a></li>
                        <li><a href="enderecos.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg><span>Meus endereços</span></a></li>
                        <li><a href="lista-desejos.php" class="active"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg><span>Lista de desejos</span></a></li>
                        <li>
                            <a href="suporte.php"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                                <span>Suporte</span>
                            </a>
                        </li>
                        <li><a href="alterar-senha.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>Alterar senha</span></a></li>
                        <li><a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg><span>Sair</span></a></li>
                    </ul>
                </nav>
            </aside>

            <section class="profile-content">
                <div class="data-card">
                    <h2>Minha Lista de Desejos</h2>

                    <?php if (!empty($success_message)): ?>
                        <div class="general-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>

                    <?php if (isset($errors['db']) && empty($page_alert_message)): ?>
                        <div class="general-error"><?php echo $errors['db']; ?></div>
                    <?php endif; ?>

                    <?php if (empty($lista_desejos)): ?>
                        <p style="color: var(--text-color-medium); text-align: center;">Sua lista de desejos está vazia. Adicione produtos que você amou!</p>
                    <?php else: ?>
                        <ul class="wishlist-list">
                            <?php foreach ($lista_desejos as $item): ?>
                                <?php $is_item_disponivel = ($item['ativo'] && $item['estoque'] > 0); ?>
                                <li class="wishlist-item">
                                    <div class="item-info">
                                        <img src="<?php echo htmlspecialchars($item['imagem_url'] ?? 'caminho/para/imagem_padrao.jpg'); ?>" alt="<?php echo htmlspecialchars($item['produto_nome']); ?>" class="item-info img">
                                        <div class="item-details">
                                            <h5><?php echo htmlspecialchars($item['produto_nome']); ?></h5>
                                            <p class="price">R$ <?php echo number_format($item['preco'], 2, ',', '.'); ?></p>
                                            <p>Adicionado em: <?php echo date('d/m/Y', strtotime($item['adicionado_em'])); ?></p>
                                        </div>
                                    </div>
                                    <div class="wishlist-actions">

                                        <?php if ($is_item_disponivel): ?>
                                            <button type="button"
                                                     class="btn-action btn-add-to-cart"
                                                     data-id="<?php echo $item['produto_id']; ?>">
                                                Adicionar à Sacola
                                            </button>
                                        <?php else: ?>
                                            <span class="btn-action disabled">Produto Esgotado</span>
                                        <?php endif; ?>

                                        <a href="lista-desejos.php?delete=<?php echo $item['produto_id']; ?>" onclick="return confirm('Tem certeza que deseja remover este produto da lista?');" class="btn-action btn-delete">Remover da Lista</a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
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
                       <button class="modal-close" data-dismiss="modal">&times;</button>
                 </div>
                 <div class="modal-body">
                       <form action="login.php" method="POST">
                             <div class="form-group">
                                   <label for="modal_login_email_header_perfil_lista">E-mail ou CPF/CNPJ:</label>
                                   <input type="text" id="modal_login_email_header_perfil_lista" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                             </div>
                             <div class="form-group">
                                   <label for="modal_login_senha_header_perfil_lista">Senha:</label>
                                   <input type="password" id="modal_login_senha_header_perfil_lista" name="modal_login_senha" placeholder="Digite sua senha" required>
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
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M4 8a.5.5 0 0 1 .5-.5h5.793L8.146 5.354a.5.5 0 1 1 .708-.708l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708-.708L10.293 8.5H4.5A.5.5 0 0 1 4 8z"/></svg>
            </button>
        </div>
        <div class="cart-body" id="cart-body-content">
              <div class="cart-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                <p>Sua sacola está vazia</p>
            </div>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // --- NOVO: Lógica AJAX para Adicionar ao Carrinho ---
            const addButtons = document.querySelectorAll('.btn-add-to-cart');

            addButtons.forEach(button => {
                button.addEventListener('click', async function(e) {
                    e.preventDefault();
                    const produtoId = this.getAttribute('data-id');
                    const originalText = this.textContent;

                    this.textContent = 'Adicionando...';
                    this.disabled = true;

                    try {
                        const addResponse = await fetch('cart_manager.php?action=add', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ produto_id: produtoId, quantidade: 1 }) // Adiciona 1 por padrão
                        });

                        const addData = await addResponse.json();

                        if (addData.status === 'success') {
                            await updateCart(); // Atualiza o contador no header
                            openCart(); // Abre o modal da sacola
                        } else {
                            alert(addData.message || 'Erro ao adicionar produto.');
                        }
                    } catch (error) {
                        console.error('Erro no fetch:', error);
                        alert('Erro ao se conectar. Tente novamente.');
                    } finally {
                        // Restaura o botão
                        this.textContent = originalText;
                        this.disabled = false;
                    }
                });
            });


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