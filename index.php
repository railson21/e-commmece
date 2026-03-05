<?php
// index.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // <-- DEVE SER A PRIMEIRA LINHA
}
require_once 'config/db.php'; // Garante $pdo para includes

// --- Lógica para buscar Banners do Carrossel (Específico da Index) ---
$banners = [];
try {
    $stmt_banner = $pdo->query("SELECT * FROM banners WHERE ativo = true ORDER BY ordem ASC");
    $banners = $stmt_banner->fetchAll();
} catch (PDOException $e) {
    error_log("Erro ao buscar banners na index.php: " . $e->getMessage());
}

// --- Lógica: Buscar Banners Promocionais da Grid ---
$promo_banners = [
    'top_4_col' => [],
    'mid_2_col' => [],
    'bottom_2_col' => []
];
try {
    $stmt_promo = $pdo->query("SELECT * FROM promo_banners WHERE ativo = true ORDER BY section_key, ordem ASC");
    while ($banner = $stmt_promo->fetch()) {
        if (isset($promo_banners[$banner['section_key']])) {
            $promo_banners[$banner['section_key']][] = $banner;
        }
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar banners promocionais: " . $e->getMessage());
}

// --- LÓGICA: Buscar Banner WhatsApp ---
$whatsapp_banner = ['ativo' => false, 'img_url' => '', 'link_url' => ''];
try {
    $stmt_wpp = $pdo->query("SELECT chave, valor FROM config_site WHERE chave LIKE 'whatsapp_banner_%'");
    $wpp_config = $stmt_wpp->fetchAll(PDO::FETCH_KEY_PAIR);
    $whatsapp_banner['ativo'] = filter_var($wpp_config['whatsapp_banner_ativo'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $whatsapp_banner['img_url'] = $wpp_config['whatsapp_banner_img_url'] ?? '';
    $whatsapp_banner['link_url'] = $wpp_config['whatsapp_banner_link_url'] ?? '#';
} catch (PDOException $e) {
    error_log("Erro ao buscar banner WhatsApp: " . $e->getMessage());
}

// --- LÓGICA: Buscar Produtos em Destaque ---
$produtos_destaque = [];
try {
    $stmt_destaque = $pdo->query("
        SELECT id, nome, preco, imagem_url, ativo, estoque
        FROM produtos
        WHERE destaque = true
        ORDER BY nome ASC
        LIMIT 8
    ");
    $produtos_destaque = $stmt_destaque->fetchAll();
} catch (PDOException $e) {
    error_log("Erro ao buscar produtos em destaque: " . $e->getMessage());
}

// --- LÓGICA: Buscar Produtos Mais Vendidos ---
$produtos_mais_vendidos = [];
try {
    $stmt_mv = $pdo->query("
        SELECT id, nome, preco, imagem_url, ativo, estoque
        FROM produtos
        WHERE mais_vendido = true
        ORDER BY nome ASC
        LIMIT 8
    ");
    $produtos_mais_vendidos = $stmt_mv->fetchAll();
} catch (PDOException $e) {
    error_log("Erro ao buscar produtos mais vendidos: " . $e->getMessage());
}

// --- FUNÇÃO HELPER PARA MONTAR LINKS ---
function getBannerLink($banner) {
    if (empty($banner['link_tipo']) || $banner['link_tipo'] == 'none') {
        return '';
    }
    if ($banner['link_tipo'] == 'url') {
        return htmlspecialchars($banner['link_url']);
    }
    if ($banner['link_tipo'] == 'produto' && !empty($banner['produto_id'])) {
        return "produto_detalhe.php?id=" . $banner['produto_id'];
    }
    if ($banner['link_tipo'] == 'categoria' && !empty($banner['categoria_id'])) {
        return "produtos.php?id=" . $banner['categoria_id'];
    }
    return '#'; // Fallback
}
function getLinkTag($banner) {
    $href = getBannerLink($banner);
    if (empty($href)) {
        return '<span class="non-clickable-banner">';
    }
    return '<a href="' . $href . '" title="' . htmlspecialchars($banner['alt_text']) . '">';
}
function getLinkTagClose($banner) {
    $href = getBannerLink($banner);
    return empty($href) ? '</span>' : '</a>';
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Loja E-commerce - Página Inicial</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">
    <style>
        /* ==========================================================
           CSS ESPECÍFICO DA INDEX.PHP (Grids, Banners, Produtos)
           (CSS de Header, Footer, Modais, etc. foi REMOVIDO)
           ========================================================== */
        .banner-full {
            width: 100%;
            background-color: white;
            flex-shrink: 0;
            line-height: 0; /* Corrige espaçamento da imagem */
        }
        .banner-full .item a, .banner-full .item img {
            display: block;
            width: 100%;
            height: auto;
        }
        .banner-full .item .non-clickable-banner { display: block; }

        /* Estilos do Carrossel */
        .owl-theme .owl-dots .owl-dot.active span,
        .owl-theme .owl-dots .owl-dot:hover span {
            background: var(--green-accent);
        }

        /* --- Grids Promocionais --- */
        .promo-grid-section { padding: 30px 0; background-color: #fff; }
        .promo-grid { display: grid; gap: 20px; margin-top: 20px; }
        .promo-grid:first-child { margin-top: 0; }
        .promo-row-4 { grid-template-columns: repeat(4, 1fr); }
        .promo-row-2 { grid-template-columns: repeat(2, 1fr); }
        .promo-item a, .promo-item span {
            display: block;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .promo-item a:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.12);
        }
        .promo-item img { width: 100%; height: auto; display: block; }

        /* --- Seção de Produtos --- */
        .product-section {
            padding-top: 40px;
            border-top: 1px solid var(--border-color-light);
            margin-top: 40px;
        }
        /* Ajuste do main-content para a index */
        .main-content {
            flex-grow: 1;
            padding: 0; /* Remove padding superior/inferior da index */
            background-color: #fff;
        }

        /* --- Banner WhatsApp --- */
        .whatsapp-banner-section {
            margin-top: 40px;
            padding: 0;
        }
        .whatsapp-banner-section a {
            display: block;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .whatsapp-banner-section a:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.12);
        }
        .whatsapp-banner-section img {
            width: 100%;
            height: auto;
            display: block;
        }

        /* --- Grid de Produtos --- */
        .product-section h1 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 1.5em;
            color: var(--text-color-dark);
            font-weight: bold;
        }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            align-items: stretch;
        }
        .product-item {
            text-align: center;
            border: 1px solid var(--border-color-light);
            border-radius: 8px;
            padding: 15px;
            transition: box-shadow 0.2s ease, transform 0.2s ease;
            display: flex;
            flex-direction: column;
        }
        .product-item:hover {
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
            transform: translateY(-3px);
        }
        .product-item a {
             text-decoration: none;
             color: inherit;
             display: flex;
             flex-direction: column;
             flex-grow: 1;
        }
        .product-image-container {
            position: relative;
            overflow: hidden;
            margin-bottom: 15px;
        }
        .product-image-container img {
             max-width: 100%;
             height: 180px;
             object-fit: contain;
             display: block;
             margin-left: auto;
             margin-right: auto;
             margin-bottom: 0;
             transition: transform 0.3s ease;
        }
        .product-hover-buttons {
            position: absolute;
            bottom: 0;
            left: -5;
            width: 100%;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(2px);
            padding: 10px;
            display: flex;
            justify-content: center;
            gap: 10px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(100%);
            transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease;
        }
        .product-item a:hover .product-image-container img {
            transform: scale(1.05);
        }

        /* NOVO AJUSTE: Aplicando a variável de cor de fundo de hover */
        .product-item a:hover .product-hover-buttons {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
            /* A cor de fundo configurada no admin */
            background: var(--cor-acento-fundo-hover);
            backdrop-filter: blur(2px);
            /* Caso queira usar transparência baseada no valor HEX, você precisaria do valor RGB ou de um fallback como este: */
            /* background: rgba(var(--cor-fundo-hover-claro-rgb, 242, 242, 242), 0.9); */
        }

        .product-hover-buttons.esgotado {
            background: rgba(255, 255, 255, 0.9);
            justify-content: center;
            align-items: center;
        }
        .btn-esgotado {
             font-size: 1em;
             font-weight: bold;
             color: var(--error-color);
             text-transform: uppercase;
        }
        .btn-hover {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 12px;
            border: none;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
            background-color: var(--green-accent);
            color: #fff;
            flex-grow: 1;
            max-width: 48%;
        }

        /* NOVO AJUSTE: Aplicando a variável de cor de acento de hover */
        .btn-hover:hover {
             background-color: var(--cor-acento-hover); /* Usa a cor de destaque configurável */
        }

        .btn-hover svg {
            width: 16px;
            height: 16px;
        }
        .product-item h3 {
            font-size: 0.95em;
            color: var(--text-color-dark);
            margin-bottom: 10px;
            height: 2.4em; /* Limita a 2 linhas */
            line-height: 1.2em;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            min-height: 2.4em;
        }
        .product-item .price {
            margin-top: auto; /* Empurra o preço para baixo */
            font-size: 1.75rem;
            color: var(--green-accent);
            font-weight: 700;
            line-height: 1;
        }

        /* --- Responsividade da Index --- */
        @media (max-width: 992px) {
            .product-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            .product-item img, .product-image-container img { height: 160px; }
        }

        @media (max-width: 768px) {
            /* --- Grid Promocional Mobile --- */
            .promo-row-4 { grid-template-columns: repeat(2, 1fr); }
            .promo-row-2 { grid-template-columns: 1fr; }
            .promo-grid-section { padding: 20px 0; }
            .promo-grid { gap: 15px; }
            .product-section { margin-top: 30px; padding-top: 30px; }

            /* --- Grid de Produtos Mobile --- */
             .product-section h1 { font-size: 1.3em; }
             .product-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
             }
             .product-item img, .product-image-container img { height: 140px; }
             .product-item h3 { font-size: 0.9em; height: 2.2em; line-height: 1.1em; min-height: 2.2em; }
             .product-item .price { font-size: 1.5rem; }
             .btn-hover { padding: 6px 10px; font-size: 0.75em; }
             .btn-hover svg { width: 14px; height: 14px; }
        }

        @media (max-width: 480px) {
             .product-item img, .product-image-container img { height: 120px; }
             .product-item .price { font-size: 1.3rem; }
        }
    </style>
</head>
<body>

    <?php include 'templates/header.php'; ?>

    <?php if (!empty($banners)): ?>
    <div class="banner-full">
        <div class="owl-carousel owl-theme">
            <?php foreach ($banners as $banner): ?>
            <div class="item">
                <?php echo getLinkTag($banner); // GERA <a> ou <span> ?>
                    <img src="<?php echo htmlspecialchars($banner['imagem_url']); ?>" alt="<?php echo htmlspecialchars($banner['alt_text']); ?>">
                <?php echo getLinkTagClose($banner); // Fecha <a> ou <span> ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <main class="main-content">
        <section class="promo-grid-section container">
             <?php if (!empty($promo_banners['top_4_col'])): ?>
            <div class="promo-grid promo-row-4">
                <?php foreach ($promo_banners['top_4_col'] as $promo): ?>
                <div class="promo-item">
                    <?php echo getLinkTag($promo); ?>
                        <img src="<?php echo htmlspecialchars($promo['imagem_url']); ?>" alt="<?php echo htmlspecialchars($promo['alt_text']); ?>">
                    <?php echo getLinkTagClose($promo); ?>
                </div>
                <?php endforeach; ?>
            </div>
             <?php endif; ?>

             <?php if (!empty($promo_banners['mid_2_col'])): ?>
            <div class="promo-grid promo-row-2">
                 <?php foreach ($promo_banners['mid_2_col'] as $promo): ?>
                <div class="promo-item">
                    <?php echo getLinkTag($promo); ?>
                        <img src="<?php echo htmlspecialchars($promo['imagem_url']); ?>" alt="<?php echo htmlspecialchars($promo['alt_text']); ?>">
                    <?php echo getLinkTagClose($promo); ?>
                </div>
                <?php endforeach; ?>
            </div>
             <?php endif; ?>

             <?php if (!empty($promo_banners['bottom_2_col'])): ?>
            <div class="promo-grid promo-row-2">
                 <?php foreach ($promo_banners['bottom_2_col'] as $promo): ?>
                <div class="promo-item">
                    <?php echo getLinkTag($promo); ?>
                        <img src="<?php echo htmlspecialchars($promo['imagem_url']); ?>" alt="<?php echo htmlspecialchars($promo['alt_text']); ?>">
                    <?php echo getLinkTagClose($promo); ?>
                </div>
                <?php endforeach; ?>
            </div>
             <?php endif; ?>
        </section>

        <section class="product-section container">
            <h1>Produtos em Destaque</h1>

            <?php if (!empty($produtos_destaque)): ?>
                <div class="product-grid">
                    <?php foreach ($produtos_destaque as $produto): ?>
                        <?php $is_disponivel = ($produto['ativo'] && $produto['estoque'] > 0); ?>
                        <div class="product-item">
                            <a href="produto_detalhe.php?id=<?php echo $produto['id']; ?>">
                                <div class="product-image-container">
                                    <?php if (!empty($produto['imagem_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($produto['imagem_url']); ?>" alt="<?php echo htmlspecialchars($produto['nome']); ?>">
                                    <?php else: ?>
                                        <img src="uploads/placeholder.png" alt="<?php echo htmlspecialchars($produto['nome']); ?>">
                                    <?php endif; ?>

                                    <?php if ($is_disponivel): ?>
                                        <div class="product-hover-buttons">
                                            <button type="button" class="btn-hover btn-spy" data-id="<?php echo $produto['id']; ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                                ESPIAR
                                            </button>
                                            <button type="button" class="btn-hover btn-buy-index" data-id="<?php echo $produto['id']; ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                                                COMPRAR
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="product-hover-buttons esgotado">
                                            <span class="btn-esgotado">Esgotado</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h3><?php echo htmlspecialchars($produto['nome']); ?></h3>
                                <p class="price">R$ <?php echo number_format($produto['preco'], 2, ',', '.'); ?></p>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-color-medium); margin-top: 20px;">Nenhum produto em destaque no momento.</p>
            <?php endif; ?>
        </section>

        <?php if ($whatsapp_banner['ativo'] && !empty($whatsapp_banner['img_url'])): ?>
        <section class="whatsapp-banner-section container">
            <a href="<?php echo htmlspecialchars($whatsapp_banner['link_url']); ?>" target="_blank" rel="noopener">
                <img src="<?php echo htmlspecialchars($whatsapp_banner['img_url']); ?>" alt="Atendimento via WhatsApp">
            </a>
        </section>
        <?php endif; ?>

        <section class="product-section container">
            <h1>Mais Vendidos</h1>
            <?php if (!empty($produtos_mais_vendidos)): ?>
                <div class="product-grid">
                    <?php foreach ($produtos_mais_vendidos as $produto): ?>
                        <?php $is_disponivel = ($produto['ativo'] && $produto['estoque'] > 0); ?>
                        <div class="product-item">
                            <a href="produto_detalhe.php?id=<?php echo $produto['id']; ?>">
                                <div class="product-image-container">
                                    <?php if (!empty($produto['imagem_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($produto['imagem_url']); ?>" alt="<?php echo htmlspecialchars($produto['nome']); ?>">
                                    <?php else: ?>
                                        <img src="uploads/placeholder.png" alt="<?php echo htmlspecialchars($produto['nome']); ?>">
                                    <?php endif; ?>
                                    <?php if ($is_disponivel): ?>
                                        <div class="product-hover-buttons">
                                            <button type="button" class="btn-hover btn-spy" data-id="<?php echo $produto['id']; ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                                ESPIAR
                                            </button>
                                            <button type="button" class="btn-hover btn-buy-index" data-id="<?php echo $produto['id']; ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                                                COMPRAR
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="product-hover-buttons esgotado">
                                            <span class="btn-esgotado">Esgotado</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h3><?php echo htmlspecialchars($produto['nome']); ?></h3>
                                <p class="price">R$ <?php echo number_format($produto['preco'], 2, ',', '.'); ?></p>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-color-medium); margin-top: 20px;">Nenhum produto em 'Mais Vendidos' no momento.</p>
            <?php endif; ?>
        </section>

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
                                   <label for="modal_login_email_header_index">E-mail ou CPF/CNPJ:</label>
                                   <input type="text" id="modal_login_email_header_index" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                             </div>
                             <div class="form-group">
                                   <label for="modal_login_senha_header_index">Senha:</label>
                                   <input type="password" id="modal_login_senha_header_index" name="modal_login_senha" placeholder="Digite sua senha" required>
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
            <button class="cart-close-btn" id="cart-close-btn">
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
                 <a href="#" class="cart-continue-btn" id="cart-continue-shopping">< Continuar Comprando</a>
                 <a href="checkout.php" class="btn btn-primary cart-checkout-btn">COMPRAR AGORA</a>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="info-modal"></div>
    <div class="modal-overlay" id="delete-confirm-modal"></div>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        $(document).ready(function(){
            // --- Script de Inicialização do Owl Carousel (Específico da Index) ---
            const mainCarousel = $(".banner-full .owl-carousel");
            if (mainCarousel.length && mainCarousel.find('.item').length > 0) {
                if (mainCarousel.find('.item').length > 1) {
                    // Ativa o carrossel completo
                    mainCarousel.owlCarousel({ items: 1, loop: true, autoplay: true, autoplayTimeout: 5000, autoplayHoverPause: true, dots: true, nav: false });
                } else {
                    // Desativa o loop e a navegação se houver apenas 1 item
                    mainCarousel.owlCarousel({ items: 1, loop: false, autoplay: false, dots: false, nav: false });
                }
            }

            // --- Lógica de Compra Rápida (Específico da Index) ---
            // (Esta lógica foi mantida em 'scripts.php' para ser global)

            /* // O código de exemplo abaixo foi comentado no original e não será incluído.
             $(document).on('click', '.btn-buy-index', async function(e) {
                 e.preventDefault();
                 const produtoId = $(this).data('id');
                 $(this).text('...');

                 try {
                     const response = await fetch('cart_manager.php?action=add', {
                         method: 'POST',
                         headers: { 'Content-Type': 'application/json' },
                         body: JSON.stringify({ produto_id: produtoId, quantidade: 1 })
                     });
                     const data = await response.json();
                     if (data.status === 'success') {
                         await updateCart();
                         openCart();
                     } else {
                         alert(data.message || 'Erro ao adicionar');
                     }
                 } catch (error) {
                     console.error('Erro:', error);
                 } finally {
                     $(this).html('<svg...></svg> COMPRAR');
                 }
             });
            */

        });
    </script>

</body>
</html>