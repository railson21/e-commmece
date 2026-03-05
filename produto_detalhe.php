<?php
// produto_detalhe.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // <-- DEVE SER A PRIMEIRA LINHA
}
require_once 'config/db.php'; // Garante $pdo para includes

// --- 1. PEGAR O ID DO PRODUTO ---
$produto_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($produto_id === 0) {
    die("Produto não especificado."); // Ou redirecionar
}

// --- VERIFICAR LISTA DE DESEJOS ---
$is_on_wishlist = false;
$user_id = $_SESSION['user_id'] ?? null; // Pega o ID do usuário logado

// --- 2. BUSCAR DADOS DO PRODUTO E CONFIGS ---
$produto = null;
$bc_categoria = null;
$bc_parent_categoria = null;
$errors = [];
$page_alert_message = '';
$exibir_calculador_frete = false; // Padrão

try {
    // --- Query para verificar a lista de desejos ---
    if ($user_id) {
        $stmt_wishlist = $pdo->prepare("SELECT COUNT(*) FROM lista_desejos WHERE usuario_id = ? AND produto_id = ?");
        $stmt_wishlist->execute([$user_id, $produto_id]);
        $is_on_wishlist = $stmt_wishlist->fetchColumn() > 0;
    }

    // --- Buscar config do admin para o simulador ---
    $stmt_config = $pdo->prepare("SELECT valor FROM config_site WHERE chave = 'exibir_calculador_frete_produto'");
    $stmt_config->execute();
    $exibir_calculador_frete = filter_var($stmt_config->fetchColumn(), FILTER_VALIDATE_BOOLEAN);

    // --- Buscar Produto (Incluindo a nova coluna 'is_lancamento') ---
    $stmt_prod = $pdo->prepare("
        SELECT p.*, m.nome AS marca_nome
        FROM produtos p
        LEFT JOIN marcas m ON p.marca_id = m.id
        WHERE p.id = :id AND p.ativo = true
    ");
    $stmt_prod->execute(['id' => $produto_id]);
    $produto = $stmt_prod->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
          $errors['db'] = "Produto não encontrado ou indisponível.";
          $page_alert_message = $errors['db'];
    } else {
        // --- LÓGICA DE DISPONIBILIDADE ---
        $is_disponivel = ($produto['ativo'] && $produto['estoque'] > 0);

        // --- AJUSTE: LÓGICA "LANÇAMENTO!" (Controlado por Flag do DB) ---
        $is_lancamento = $produto['is_lancamento'] ?? false;

        // --- BUSCAR BREADCRUMBS (CATEGORIA) ---
        $stmt_bc = $pdo->prepare("
            SELECT c.id, c.nome, c.parent_id
            FROM categorias c
            JOIN produto_categorias pc ON c.id = pc.categoria_id
            WHERE pc.produto_id = :pid
            LIMIT 1
        ");
        $stmt_bc->execute(['pid' => $produto_id]);
        $bc_categoria = $stmt_bc->fetch(PDO::FETCH_ASSOC);

        if ($bc_categoria && $bc_categoria['parent_id']) {
             $stmt_parent = $pdo->prepare("SELECT id, nome FROM categorias WHERE id = :pid");
             $stmt_parent->execute(['pid' => $bc_categoria['parent_id']]);
             $bc_parent_categoria = $stmt_parent->fetch(PDO::FETCH_ASSOC);
        }

        // --- BUSCAR MÍDIA DA GALERIA ---
        $galeria_midia = [];
        $stmt_midia = $pdo->prepare("SELECT tipo, url FROM produto_midia WHERE produto_id = :id ORDER BY ordem ASC");
        $stmt_midia->execute(['id' => $produto_id]);
        $galeria_midia = $stmt_midia->fetchAll(PDO::FETCH_ASSOC);

        $imagem_principal_galeria = $produto['imagem_url']; // Default
        $video_principal_galeria = "";
        $primeira_midia_e_video = false;

        if (!empty($galeria_midia)) {
            if ($galeria_midia[0]['tipo'] == 'imagem') {
                $imagem_principal_galeria = $galeria_midia[0]['url'];
            } else if ($galeria_midia[0]['tipo'] == 'video') {
                $primeira_midia_e_video = true;
                $video_principal_galeria = getVimeoEmbedUrl($galeria_midia[0]['url']);
            }
        }
        else if (!empty($produto['imagem_url'])) {
             $galeria_midia[] = [
                 'tipo' => 'imagem',
                 'url' => $produto['imagem_url']
             ];
        }

        // --- BUSCAR PRODUTOS RELACIONADOS ---
        $produtos_relacionados = [];
        if ($bc_categoria) {
            $categoria_id_para_busca = $bc_categoria['id'];
            $sql_rel = "
                SELECT p.id, p.nome, p.preco, p.imagem_url, p.ativo, p.estoque, p.is_lancamento
                FROM produtos p
                JOIN produto_categorias pc ON p.id = pc.produto_id
                WHERE pc.categoria_id = :cid AND p.id != :pid AND p.ativo = true
                LIMIT 4
            ";
            $stmt_rel = $pdo->prepare($sql_rel);
            $stmt_rel->execute(['cid' => $categoria_id_para_busca, 'pid' => $produto_id]);
            $produtos_relacionados = $stmt_rel->fetchAll(PDO::FETCH_ASSOC);
        }
    }

} catch (PDOException $e) {
    $errors['db'] = "Erro ao buscar dados do produto: " . $e->getMessage();
    $page_alert_message = $errors['db'];
}

// Helper para converter URL Vimeo
function getVimeoEmbedUrl($url) {
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $matches)) {
        $video_id = $matches[1];
        return "https://player.vimeo.com/video/" . $video_id . "?autoplay=0&loop=0&autopause=1";
    }
    return "";
}

// ==========================================================
// LÓGICA DE AVALIAÇÕES (AJUSTADA)
// ==========================================================
$avaliacoes = [];
$total_avaliacoes = 0;
$media_classificacao = 0;
try {
    // AJUSTE NA QUERY: COALESCE(ap.nome_avaliador, u.nome, 'Anônimo')
    // Busca o nome manual do admin (nome_avaliador), se não houver, busca o nome do usuário (u.nome), se não houver, usa 'Anônimo'.
    $sql_avaliacoes = "
        SELECT
            ap.classificacao, ap.comentario, ap.foto_url, ap.data_avaliacao,
            COALESCE(ap.nome_avaliador, u.nome, 'Anônimo') AS nome_exibicao
        FROM avaliacoes_produto ap
        LEFT JOIN usuarios u ON ap.usuario_id = u.id
        WHERE ap.produto_id = :pid AND ap.aprovado = TRUE
        ORDER BY ap.data_avaliacao DESC
    ";
    $stmt_avaliacoes = $pdo->prepare($sql_avaliacoes);
    $stmt_avaliacoes->execute(['pid' => $produto_id]);
    $avaliacoes = $stmt_avaliacoes->fetchAll(PDO::FETCH_ASSOC);
    $total_avaliacoes = count($avaliacoes);

    if ($total_avaliacoes > 0) {
        // Calcula a média
        $soma_classificacoes = array_sum(array_column($avaliacoes, 'classificacao'));
        $media_classificacao = round($soma_classificacoes / $total_avaliacoes, 1);
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar avaliações: " . $e->getMessage());
}
// ==========================================================
// FIM DA LÓGICA DE AVALIAÇÕES
// ==========================================================
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($produto['nome'] ?? 'Produto'); ?> - Minha Loja</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS COMPLETO (HEADER, FOOTER, MODALS, ETC.)
           ========================================================== */
        :root {
            /* Estes são fallbacks. O header.php irá sobrescrevê-los com os valores do DB. */
            --green-accent: #9ad700;
            --header-footer-bg: #e9efe8;
            --nav-bg: #fff;
            --text-color-dark: #333;
            --text-color-medium: #555;
            --text-color-light: #777;
            --border-color-light: #eee;
            --border-color-medium: #ccc;
            --error-color: #dc3545;
            --success-color: #28a745;
            --cor-acento-hover: #8cc600;
            --cor-acento-fundo-hover: #f7f7f7;
            /* AJUSTE: Cor da borda da avaliação */
            --review-border-color: #ddd;
        }
        html, body { height: 100%; }
        body { margin: 0; font-family: Arial, sans-serif; background-color: #fff; color: var(--text-color-dark); display: flex; flex-direction: column; }
        .container { width: 100%; max-width: 1140px; margin: 0 auto; padding: 0 15px; box-sizing: border-box; }
        a { text-decoration: none; color: inherit; }
        button { cursor: pointer; border: none; background: none; padding: 0;}
        *, *::before, *::after { box-sizing: border-box; }

        /* --- CSS Básico Header (Mockup) --- */
        .sticky-header-wrapper { background-color: var(--header-footer-bg); }
        header { background-color: var(--header-footer-bg); width: 100%; padding: 0 2%; box-sizing: border-box; flex-shrink: 0; }
        .header-top { display: flex; justify-content: flex-end; padding: 8px 2%; box-sizing: border-box; width: 100%; background-color: var(--header-footer-bg); transition: transform 0.3s ease-in-out; transform: translateY(0); will-change: transform; }
        .header-top a { font-size: 11px; color: var(--text-color-medium); margin-left: 20px; text-transform: uppercase; }
        .header-top-hidden { transform: translateY(-100%); }
        .header-main { display: flex; align-items: center; justify-content: center; height: auto; padding: 0px 0; background-color: var(--header-footer-bg); }
        .header-main > div { padding: 0 10px; }
        .hamburger-menu { display: none; background: none; border: none; padding: 0;}
        .logo { flex-shrink: 0; }
        .search-bar { text-align: center; width: 450px; margin: 0 30px; }
        .search-bar form { position: relative; width: 100%; }
        .search-bar input[type="search"]::placeholder { color: #999; opacity: 1; }
        .search-bar input[type="search"] { width: 100%; height: 40px; border-radius: 20px; border: 1px solid var(--border-color-medium); background-color: #fff; padding: 0 50px 0 20px; box-sizing: border-box; font-size: 14px; }
        .search-bar button { position: absolute; right: 5px; top: 50%; transform: translateY(-50%); width: 35px; height: 35px; border: none; background: none; cursor: pointer; color: var(--text-color-light); display: flex; align-items: center; justify-content: center; padding: 0; }
        .search-bar button svg { width: 20px; height: 20px; color: var(--text-color-dark); }
        .user-actions { flex-shrink: 0; display: flex; justify-content: flex-end; align-items: center; }
        .action-item { display: flex; align-items: center; margin-left: 25px; cursor: pointer; }
        .action-item svg { width: 28px; height: 28px; margin-right: 8px; color: var(--text-color-dark); stroke-width: 1.5; }
        .action-item .text-content { display: flex; flex-direction: column; }
        .action-item .text-content strong { font-size: 14px; color: var(--text-color-dark); line-height: 1.2; }
        .action-item .text-content span { font-size: 12px; color: var(--text-color-dark); line-height: 1.2; }
        .header-nav { background-color: var(--nav-bg); display: flex; justify-content: center; padding: 10px 0; border-top: 1px solid #ddd; flex-shrink: 0; }
        .header-nav ul { list-style: none; margin: 0; padding: 0; display: flex; }
        .header-nav li { position: relative; }
        .header-nav li a { padding: 10px 15px; font-size: 13px; font-weight: bold; color: var(--text-color-dark); text-transform: uppercase; transition: background-color 0.2s ease, color 0.2s ease; display: block; }
        .mobile-nav-header, .header-nav .chevron { display: none; }
        .header-nav .sub-menu { display: none; }
        .header-nav li.visible > a { background-color: var(--green-accent); color: #fff; }
        .header-nav .sub-menu { opacity: 0; visibility: hidden; transition: opacity 0.2s ease, visibility 0s linear 0.2s; position: absolute; left: 0; top: 100%; background-color: #fff; padding: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); border: 2px solid var(--green-accent); border-radius: 8px; margin-top: 5px; width: 220px; min-width: max-content; z-index: 100; list-style: none; margin-left: 0; }
        .header-nav li.visible .sub-menu { opacity: 1; visibility: visible; transition: opacity 0.2s ease; display: block; }
        .header-nav .sub-menu li { border: none; width: 100%; }
        .header-nav .sub-menu li a { padding: 8px 10px; font-size: 14px; font-weight: normal; color: var(--text-color-dark); text-transform: none; display: block; }
        .header-nav .sub-menu li a:hover { color: var(--green-accent); background-color: var(--cor-acento-fundo-hover); }
        #minha-conta { position: relative; cursor: pointer; }
        .text-content .dropdown-chevron { display: inline-block; width: 6px; height: 6px; border-right: 2px solid var(--text-color-light); border-bottom: 2px solid var(--text-color-light); transform: rotate(45deg); margin-left: 5px; transition: transform 0.2s ease; vertical-align: middle; }
        #minha-conta.visible .dropdown-chevron { transform: rotate(225deg); }
        .account-dropdown { opacity: 0; visibility: hidden; transition: opacity 0.2s ease, visibility 0s linear 0.2s; position: absolute; top: calc(100% + 10px); right: -15px; width: 240px; background-color: #4a4a4a; color: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); z-index: 100; }
        .account-dropdown.visible { opacity: 1; visibility: visible; transition: opacity 0.2s ease; }
        .account-dropdown::before { content: ""; position: absolute; bottom: 100%; right: 25px; margin-bottom: -1px; border-left: 10px solid transparent; border-right: 10px solid transparent; border-bottom: 10px solid #4a4a4a; }
        .account-dropdown .btn-entrar { display: block; width: 100%; padding: 10px; background-color: #fff; color: #333; font-size: 15px; font-weight: bold; text-align: center; border: none; border-radius: 25px; cursor: pointer; box-sizing: border-box; transition: transform 0.2s ease; }
        .account-dropdown .btn-entrar:hover { background-color: var(--cor-acento-hover); }
        @media (min-width: 769px) {
            .sticky-header-wrapper { position: sticky; top: 0; z-index: 999; background-color: var(--header-footer-bg); }
             .header-top-hidden + header { box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        }
        .sticky-header-wrapper > header,
        .sticky-header-wrapper > nav.header-nav { margin-bottom: 1px; }
        .sticky-header-wrapper > header { background-color: var(--header-footer-bg); }
        .sticky-header-wrapper > header > .header-main { background-color: var(--header-footer-bg); }
        .sticky-header-wrapper > nav.header-nav { background-color: var(--nav-bg); }
        .main-content { flex-grow: 1; padding: 20px 0; background-color: #fff; }

        /* --- CSS Básico Footer (Mockup) --- */
        .newsletter-section { background-color: var(--header-footer-bg); padding: 40px 0; text-align: center; flex-shrink: 0; }
        .newsletter-section h3 { margin-top: 0; margin-bottom: 10px; font-size: 1.2em; color: var(--text-color-dark); font-weight: bold; text-transform: uppercase; }
        .newsletter-section p { font-size: 0.9em; color: var(--text-color-medium); margin-bottom: 20px; }
        .newsletter-form { max-width: 450px; margin: 0 auto; position: relative; }
        .newsletter-form input[type="email"] { width: 100%; padding: 12px 50px 12px 20px; border: 1px solid var(--border-color-medium); border-radius: 25px; box-sizing: border-box; font-size: 0.9em; }
        .newsletter-form button { position: absolute; right: 5px; top: 50%; transform: translateY(-50%); background-color: var(--green-accent); color: white; border: none; border-radius: 20px; padding: 8px 15px; font-size: 0.9em; font-weight: bold; }
        .main-footer { background-color: var(--header-footer-bg); padding: 50px 0 30px 0; color: var(--text-color-medium); font-size: 0.85em; flex-shrink: 0; border-top: 1px solid var(--border-color-medium); }
        .footer-columns { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 30px; margin-bottom: 40px; }
        .footer-column { flex: 1; min-width: 180px; }
        .footer-column h4 { font-size: 0.9em; color: var(--text-color-dark); margin-top: 0; margin-bottom: 15px; text-transform: uppercase; font-weight: bold; }
        .footer-column ul { list-style: none; padding: 0; margin: 0; }
        .footer-column ul li { margin-bottom: 8px; }
        .footer-column ul li a { color: var(--text-color-medium); font-size: 0.9em; }
        .footer-column ul li a:hover { color: var(--green-accent); }
        .social-icons { text-align: left; padding: 20px 0; }
        .social-icons a { display: inline-flex; align-items: center; justify-content: center; margin-right: 10px; color: #555; width: 32px; height: 32px; background-color: transparent; border-radius: 0; transition: color 0.2s ease; }
        .social-icons a:hover { color: var(--green-accent); }
        .social-icons a svg { width: 100%; height: 100%; fill: currentColor; }
        .footer-credits { border-top: 1px solid var(--border-color-medium); padding-top: 20px; margin-top: 30px; font-size: 0.75em; color: #888; text-align: center; }
        .footer-credits p strong { color: var(--text-color-medium); }

        /* --- CSS Modais (Mockup) --- */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); display: flex; justify-content: center; align-items: center; z-index: 1000; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0s linear 0.3s; }
        .modal-overlay.modal-open { opacity: 1; visibility: visible; transition: opacity 0.3s ease; }
        .modal-content { background-color: #fff; padding: 30px; border-radius: 5px; width: 90%; max-width: 450px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); position: relative; transform: translateY(-20px); transition: transform 0.3s ease-out; }
        .modal-overlay.modal-open .modal-content { transform: translateY(0); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color-light); }
        .modal-title { font-size: 1.3em; color: var(--text-color-dark); margin: 0; font-weight: normal; }
        .modal-close { background: none; border: none; font-size: 1.8em; font-weight: bold; color: #aaa; cursor: pointer; padding: 0 5px; line-height: 1; }
        .modal-close:hover { color: #777; }
        .modal-body .form-group { margin-bottom: 15px; text-align: left; }
        .modal-body .form-group label { display: block; margin-bottom: 5px; font-weight: normal; color: var(--text-color-medium); }
        .modal-body .form-group input { width: 100%; padding: 12px; border: 1px solid var(--border-color-medium); border-radius: 4px; box-sizing: border-box; font-size: 1em; }
        .modal-footer { margin-top: 25px; text-align: right; }
        .modal-footer .btn-primary { min-width: 120px; }
        .btn { display: inline-block; padding: 12px 35px; border-radius: 4px; font-size: 1em; font-weight: normal; text-align: center; text-decoration: none; cursor: pointer; transition: all 0.3s ease; border: 1px solid transparent; min-width: 150px; }
        .btn-primary { background-color: var(--green-accent); color: #fff; border-color: var(--green-accent); }
        .btn-primary:hover { background-color: var(--cor-acento-hover); border-color: var(--cor-acento-hover); }

        /* Grid de Produtos (usado em Relacionados) */
        .product-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 25px; align-items: stretch; }
        .product-item { text-align: center; border: 1px solid var(--border-color-light); border-radius: 8px; padding: 15px; transition: box-shadow 0.2s ease, transform 0.2s ease; display: flex; flex-direction: column; }
        .product-item:hover { box-shadow: 0 6px 12px rgba(0,0,0,0.1); transform: translateY(-3px); }
        .product-item a { text-decoration: none; color: inherit; display: flex; flex-direction: column; flex-grow: 1; }
        .product-image-container { position: relative; overflow: hidden; margin-bottom: 15px; }
        .product-image-container img { max-width: 100%; height: 180px; object-fit: contain; display: block; margin: 0 auto; transition: transform 0.3s ease; }
        .product-item a:hover .product-image-container img { transform: scale(1.05); }
        .product-hover-buttons { position: absolute; bottom: 0; left: 0; width: 100%; background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(2px); padding: 10px; display: flex; justify-content: center; gap: 10px; opacity: 0; visibility: hidden; transform: translateY(100%); transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease; }
        .product-item a:hover .product-hover-buttons { opacity: 1; visibility: visible; transform: translateY(0); background: var(--cor-acento-fundo-hover); }
        .product-hover-buttons.esgotado { background: rgba(255, 255, 255, 0.9); justify-content: center; align-items: center; }
        .btn-esgotado { font-size: 1em; font-weight: bold; color: var(--error-color); text-transform: uppercase; }
        .btn-hover { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 8px 12px; border: none; border-radius: 20px; font-size: 0.8em; font-weight: bold; text-transform: uppercase; cursor: pointer; transition: background-color 0.2s ease, color 0.2s ease; background-color: var(--green-accent); color: #fff; flex-grow: 1; max-width: 48%; }
        .btn-hover:hover { background-color: var(--cor-acento-hover); }
        .btn-hover svg { width: 16px; height: 16px; }
        .product-item h3 { font-size: 0.95em; color: var(--text-color-dark); margin-bottom: 10px; height: 2.4em; line-height: 1.2em; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; min-height: 2.4em; }
        .product-item .price { margin-top: auto; font-size: 1.75rem; color: var(--green-accent); font-weight: 700; line-height: 1; }

        /* ==========================================================
           ESTILOS - PÁGINA DE DETALHE DO PRODUTO (REFINADO)
           ========================================================== */

        /* --- Exibição da Média de Avaliação --- */
        .rating-display { font-size: 1.5em; color: var(--text-color-medium); margin-top: 5px; display: flex; align-items: center; }
        .rating-display .star { color: orange; }
        .review-count { font-size: 0.9em; color: var(--text-color-light); margin-left: 10px; }

        /* --- AJUSTE: Estilos da Lista de Avaliações --- */
        .review-list-wrapper { margin-top: 20px; }

        .avaliacao-item {
            border: 1px solid var(--review-border-color); /* Borda ao redor */
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px; /* Espaçamento entre avaliações */
            background-color: #fcfcfc;
        }

        .avaliacao-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 10px; /* Espaço entre avatar e nome */
            justify-content: space-between; /* Para manter a data à direita */
        }

        .avaliador-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avaliador-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background-color: var(--border-color-light); /* Cor de fundo para o ícone */
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .avaliador-avatar svg {
            width: 20px;
            height: 20px;
            fill: var(--text-color-medium); /* Cor do ícone de pessoa */
        }

        .avaliador-nome-data {
            flex-grow: 1;
            line-height: 1.2;
        }

        .avaliador-nome-data strong {
            font-weight: 700;
            color: var(--text-color-dark);
            display: block;
        }

        .avaliacao-rating { margin-bottom: 10px; }
        .avaliacao-rating .star { color: orange; font-size: 1.1em; }
        .avaliacao-date { font-size: 0.8em; color: var(--text-color-light); }
        .avaliacao-comentario { margin: 10px 0 15px 0; font-size: 0.95em; color: var(--text-color-medium); }

        /* Imagem Maior */
        .avaliacao-foto img {
            max-width: 235px; /* Aumenta o tamanho da imagem */
            max-height: 150px;
            object-fit: cover;
            border-radius: 4px;
            margin-top: 10px;
            border: 1px solid var(--border-color-medium);
            cursor: zoom-in;
        }
        /* --- FIM AJUSTE --- */

        /* --- NOVO: Estilo Lista de Desejos --- */
        .wishlist-button {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            cursor: pointer;
            z-index: 5;
            transition: all 0.2s ease;
        }
        .wishlist-button:hover {
            transform: scale(1.1);
        }
        .wishlist-button svg {
            width: 20px;
            height: 20px;
            fill: none;
            stroke: var(--text-color-medium);
            stroke-width: 2;
            transition: all 0.2s ease;
        }
        .wishlist-button.added svg {
            fill: var(--error-color);
            stroke: var(--error-color);
        }
        /* --- FIM NOVO --- */

        .breadcrumbs {
            font-size: 0.8em;
            color: var(--text-color-medium);
            margin-bottom: 20px;
        }
        .breadcrumbs a { color: var(--text-color-medium); text-transform: uppercase; }
        .breadcrumbs a:hover { color: var(--green-accent); }
        .breadcrumbs span { color: var(--text-color-dark); font-weight: bold; text-transform: uppercase; }

        .product-detail-layout {
            display: grid;
            grid-template-columns: 80px 1fr 1fr;
            gap: 20px;
            margin-bottom: 40px;
            position: relative;
        }

        /* Coluna de Thumbnails */
        .product-thumbnails {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 500px;
            overflow-y: auto;
        }
        .thumb-item {
            display: block;
            border: 2px solid var(--border-color-light);
            border-radius: 4px;
            cursor: pointer;
            overflow: hidden;
            width: 80px;
            height: 80px;
            padding: 5px;
            box-sizing: border-box;
            transition: border-color 0.2s ease;
            position: relative;
        }
        .thumb-item.active,
        .thumb-item:hover {
            border-color: var(--green-accent);
        }
        .thumb-item img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .video-thumb-icon {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(0, 0, 0, 0.3);
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }
        .thumb-item:hover .video-thumb-icon { opacity: 1; }
        .video-thumb-icon svg {
             width: 30px;
             height: 30px;
             fill: #fff;
        }

        /* View Principal (Imagem + Vídeo) */
        .product-main-view {
            position: relative;
            border: 1px solid var(--border-color-light);
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 1 / 1;
            height: auto;
            background-color: #fff;
            transition: aspect-ratio 0.3s ease;
        }

        .product-main-view.video-mode {
            aspect-ratio: 16 / 9;
            background-color: #000;
        }

        .product-image-gallery {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            cursor: crosshair;
        }
        .product-image-gallery img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        /* Lógica da Capa do Vídeo */
        #video-wrapper {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background-color: #000;
            display: none;
        }
        #video-cover {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: contain;
            cursor: pointer;
            display: none;
        }
        #video-play-button {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            cursor: pointer;
            z-index: 5;
            background-color: rgba(0, 0, 0, 0.6);
            border-radius: 50%;
            padding: 15px;
            transition: all 0.2s ease;
            display: none;
        }
        #video-play-button svg { width: 50px; height: 50px; fill: #fff; display: block; margin-left: 5px; }
        #video-play-button:hover { background-color: var(--cor-acento-hover); transform: translate(-50%, -50%) scale(1.1); }
        #video-iframe { display: none; width: 100%; height: 100%; position: absolute; top: 0; left: 0; }

        /* Lupa/Zoom */
        .zoom-lens {
            position: absolute;
            border: 1px solid #ccc;
            background-color: rgba(255,255,255,0.4);
            width: 150px;
            height: 150px;
            display: none;
            z-index: 10;
            pointer-events: none;
        }
        #zoom-result {
            position: absolute;
            top: 0;
            left: 0;
            width: 450px;
            height: 450px;
            border: 1px solid var(--border-color-medium);
            background-repeat: no-repeat;
            z-index: 10;
            background-color: #fff;
            display: none;
            pointer-events: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .product-info {
             position: relative;
        }

        .product-info h1 { font-size: 1.8em; font-weight: bold; color: var(--text-color-dark); margin: 0 0 10px 0; line-height: 1.3; }
        .product-info .tag-lancamento { background-color: var(--green-accent); color: #fff; font-size: 0.8em; font-weight: bold; padding: 4px 10px; border-radius: 4px; display: inline-block; margin-bottom: 15px; }
        .product-info .product-ref { font-size: 0.85em; color: var(--text-color-light); margin-bottom: 15px; }
        .product-info .price { font-size: 2.5rem; font-weight: 700; color: var(--green-accent); line-height: 1; margin: 20px 0; }
        .quantity-selector { margin-top: 20px; }
        .quantity-selector label { display: block; font-size: 0.9em; font-weight: bold; margin-bottom: 8px; }
        .quantity-selector input[type="number"] { width: 70px; padding: 10px; text-align: center; border: 1px solid var(--border-color-medium); border-radius: 4px; font-size: 1.1em; }
        .quantity-selector input[type=number]::-webkit-inner-spin-button,
        .quantity-selector input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        .quantity-selector input[type=number] { -moz-appearance: textfield; }
        .btn-comprar { display: block; width: 100%; padding: 15px 20px; margin-top: 20px; background-color: var(--green-accent); color: #fff; border: none; border-radius: 4px; font-size: 1.1em; font-weight: bold; text-transform: uppercase; cursor: pointer; transition: background-color 0.2s ease; }
        .btn-comprar:hover { background-color: var(--cor-acento-hover); }
        .shipping-calculator { margin-top: 25px; border-top: 1px solid var(--border-color-light); padding-top: 20px; }
        .shipping-calculator label { font-weight: bold; font-size: 0.9em; display: block; margin-bottom: 8px; }
        .shipping-calculator .shipping-form { display: flex; gap: 10px; }
        .shipping-calculator input[type="text"] { flex-grow: 1; padding: 10px 12px; border: 1px solid var(--border-color-medium); border-radius: 4px; }
        .shipping-calculator button { padding: 10px 15px; background-color: #555; color: #fff; border-radius: 4px; font-size: 0.9em; font-weight: bold; }
        .shipping-calculator button:hover { background-color: #333; }

        .product-unavailable-box { border: 1px solid var(--border-color-light); border-radius: 8px; padding: 20px; text-align: center; background-color: #f9f9f9; margin-top: 20px; }
        .product-unavailable-box .unavailable-title { font-size: 1.5em; font-weight: bold; color: var(--text-color-dark); margin: 0 0 15px 0; }
        .product-unavailable-box .unavailable-text { font-size: 0.9em; color: var(--text-color-medium); margin-bottom: 15px; }
        .notify-me-form { display: flex; gap: 10px; margin-top: 10px; }
        .notify-me-form input[type="email"] { flex-grow: 1; padding: 10px 12px; border: 1px solid var(--border-color-medium); border-radius: 4px; font-size: 1em; }
        .notify-me-form button { padding: 10px 20px; background-color: var(--text-color-dark); color: #fff; border-radius: 4px; font-size: 1em; font-weight: bold; transition: background-color 0.2s ease; }
        .notify-me-form button:hover { background-color: #000; }

        .product-details-bottom { border-top: 1px solid var(--border-color-light); margin-top: 40px; padding-top: 20px; }
        .product-tabs { display: flex; border-bottom: 2px solid var(--border-color-light); margin-bottom: 20px; }
        .tab-link { padding: 10px 20px; border: none; background: none; cursor: pointer; font-size: 1.1em; font-weight: bold; color: var(--text-color-medium); position: relative; bottom: -2px; border-bottom: 2px solid transparent; }
        .tab-link.active { color: var(--text-color-dark); border-bottom-color: var(--green-accent); }
        .tab-content { display: none; line-height: 1.6; color: var(--text-color-medium); }
        .tab-content.active { display: block; }
        .tab-content p { margin: 0 0 15px 0; }
        .tab-content ul, .tab-content li { margin-bottom: 10px; }

        .product-related-section { margin-top: 50px; padding-top: 30px; border-top: 1px solid var(--border-color-light); }
         .product-related-section h2 { font-size: 1.5em; color: var(--text-color-dark); font-weight: bold; text-align: center; margin-bottom: 30px; }
         .general-error { color: var(--error-color); font-weight: bold; text-align: center; margin-bottom: 15px; }
         .product-related-section .product-grid { grid-template-columns: repeat(4, 1fr); gap: 25px; align-items: stretch; }
         .product-item { text-align: center; border: 1px solid var(--border-color-light); border-radius: 8px; padding: 15px; transition: box-shadow 0.2s ease, transform 0.2s ease; display: flex; flex-direction: column; }
         .product-item:hover { box-shadow: 0 6px 12px rgba(0,0,0,0.1); transform: translateY(-3px); }
         .product-item a { text-decoration: none; color: inherit; display: flex; flex-direction: column; flex-grow: 1; }
         .product-image-container { position: relative; overflow: hidden; margin-bottom: 15px; }
         .product-image-container img { max-width: 100%; height: 180px; object-fit: contain; display: block; margin: 0 auto; transition: transform 0.3s ease; }
         .product-item a:hover .product-image-container img { transform: scale(1.05); }
         .product-item h3 { font-size: 0.95em; color: var(--text-color-dark); margin-bottom: 10px; height: 2.4em; line-height: 1.2em; overflow: hidden; min-height: 2.4em; }
         .product-item .price { margin-top: auto; font-size: 1.75rem; color: var(--green-accent); font-weight: 700; line-height: 1; }
         .product-hover-buttons { position: absolute; bottom: 0; left: 0; width: 100%; background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(2px); padding: 10px; display: flex; justify-content: center; gap: 10px; opacity: 0; visibility: hidden; transform: translateY(100%); transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease; }
         .product-item a:hover .product-hover-buttons { opacity: 1; visibility: visible; transform: translateY(0); background: var(--cor-acento-fundo-hover); }
         .product-hover-buttons.esgotado { background: rgba(255, 255, 255, 0.9); justify-content: center; align-items: center; }
         .btn-esgotado { font-size: 1em; font-weight: bold; color: var(--error-color); text-transform: uppercase; }
         .btn-hover { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 8px 12px; border: none; border-radius: 20px; font-size: 0.8em; font-weight: bold; text-transform: uppercase; cursor: pointer; transition: background-color 0.2s ease, color 0.2s ease; background-color: var(--green-accent); color: #fff; flex-grow: 1; max-width: 48%; }
         .btn-hover:hover { background-color: var(--cor-acento-hover); }
         .btn-hover svg { width: 16px; height: 16px; }

        /* --- Responsividade (Específica da Página) --- */
        @media (max-width: 992px) {
             .product-detail-layout { grid-template-columns: 1fr; }
             .product-thumbnails { flex-direction: row; overflow-x: auto; max-height: none; margin-top: 10px; padding-bottom: 5px; order: 2; }
             .product-main-view { order: 1; }
             .product-info { order: 3; }
             .thumb-item { flex-shrink: 0; width: 70px; height: 70px; }
             .product-main-view { aspect-ratio: 1/1; }
             .product-main-view.video-mode { aspect-ratio: 16/9; }
             .product-main-view img { max-height: 400px; }
             #zoom-result { display: none !important; }
             .zoom-lens { display: none !important; }
             .product-image-gallery { cursor: default; }
             .product-related-section .product-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .product-detail-layout { grid-template-columns: 1fr; gap: 20px; }
            .product-info h1 { font-size: 1.5em; }
            .product-info .price { font-size: 2.1rem; }
            .btn-comprar { padding: 12px; font-size: 1em; }
            .shipping-calculator .shipping-form { flex-direction: column; }
            .tab-link { font-size: 1em; padding: 10px 15px; }
            .product-related-section h2 { font-size: 1.3em; }
            .product-related-section .product-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; }
            /* AJUSTE: Responsividade da Avaliação */
            .avaliacao-item { padding: 15px; }
            .avaliacao-header { flex-direction: column; align-items: flex-start; gap: 5px; }
            .avaliador-info { width: 100%; }
        }
    </style>
</head>
<body>

    <div class="sticky-header-wrapper">
        <?php include 'templates/header.php'; ?>
    </div>

    <main class="main-content container">

        <?php if (!$produto || isset($errors['db'])): ?>
            <div class="data-card" style="text-align: center; padding: 60px 20px; margin: 20px auto; max-width: 600px;">
                <h2>Produto Não Encontrado</h2>
                <?php if (isset($errors['db'])): ?>
                    <p class="general-error"><?php echo $errors['db']; ?></p>
                <?php else: ?>
                    <p>O produto que você está procurando não existe ou foi removido.</p>
                <?php endif; ?>
                <a href="index.php" class="btn btn-primary" style="border-radius: 4px; padding: 12px 30px;">Voltar para a Home</a>
            </div>

        <?php else: ?>
            <div class="breadcrumbs">
                <a href="index.php">HOME</a> /
                <?php if ($bc_parent_categoria): ?>
                    <a href="produtos.php?id=<?php echo $bc_parent_categoria['id']; ?>"><?php echo htmlspecialchars($bc_parent_categoria['nome']); ?></a> /
                <?php endif; ?>
                <?php if ($bc_categoria): ?>
                    <a href="produtos.php?id=<?php echo $bc_categoria['id']; ?>"><?php echo htmlspecialchars($bc_categoria['nome']); ?></a> /
                <?php endif; ?>
                <span><?php echo strtoupper(htmlspecialchars($produto['nome'])); ?></span>
            </div>

            <div class="product-detail-layout">

                <div class="product-thumbnails">
                    <?php if (!empty($galeria_midia)): ?>
                        <?php foreach ($galeria_midia as $index => $midia): ?>
                            <a href="<?php echo htmlspecialchars($midia['url']); ?>"
                               class="thumb-item <?php echo $index == 0 ? 'active' : ''; ?>"
                               data-type="<?php echo $midia['tipo']; ?>">

                                <?php if ($midia['tipo'] == 'video'): ?>
                                    <img src="<?php echo htmlspecialchars($produto['imagem_url']); ?>" alt="Capa do Vídeo">
                                    <span class="video-thumb-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                    </span>
                                <?php else: ?>
                                    <img src="<?php echo htmlspecialchars($midia['url']); ?>" alt="Miniatura <?php echo $index + 1; ?>">
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="product-main-view <?php echo $primeira_midia_e_video ? 'video-mode' : ''; ?>">

                    <button class="wishlist-button <?php echo $is_on_wishlist ? 'added' : ''; ?>" id="wishlist-btn" data-id="<?php echo $produto_id; ?>" title="Adicionar à Lista de Desejos">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                        </svg>
                    </button>
                    <div class="product-image-gallery" id="main-image-wrapper" style="<?php echo $primeira_midia_e_video ? 'display: none;' : 'display: block;'; ?>">
                        <img id="product-main-image" src="<?php echo htmlspecialchars($imagem_principal_galeria); ?>" alt="<?php echo htmlspecialchars($produto['nome']); ?>">
                    </div>

                    <div id="video-wrapper" style="<?php echo !$primeira_midia_e_video ? 'display: none;' : 'display: block;'; ?>">
                        <img id="video-cover" src="<?php echo htmlspecialchars($produto['imagem_url']); ?>" alt="Capa do vídeo" style="<?php echo $primeira_midia_e_video ? 'display: block;' : 'display: none;'; ?>">
                        <div id="video-play-button" style="<?php echo $primeira_midia_e_video ? 'display: block;' : 'display: none;'; ?>">
                             <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"></path></svg>
                        </div>
                        <iframe id="video-iframe" width="100%" height="100%" src="" data-vimeo-src="<?php echo htmlspecialchars($video_principal_galeria); ?>" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
                    </div>
                </div>

                <div class="product-info">
                    <div id="zoom-result"></div>

                    <?php if ($is_lancamento): ?>
                        <span class="tag-lancamento">Lançamento!</span>
                    <?php endif; ?>

                    <h1><?php echo htmlspecialchars($produto['nome']); ?></h1>
                    <span class="product-ref">Ref: SKU-<?php echo $produto['id']; ?></span>

                    <?php if ($total_avaliacoes > 0): ?>
                    <div class="rating-display">
                        <?php
                            // Renderiza estrelas cheias e vazias
                            $full_stars = floor($media_classificacao);
                            $empty_stars = 5 - $full_stars;
                            for ($i = 0; $i < $full_stars; $i++) { echo '<span class="star">★</span>'; }
                            for ($i = 0; $i < $empty_stars; $i++) { echo '<span class="star" style="opacity: 0.3;">★</span>'; }
                        ?>
                        <span class="review-count">(<?php echo $total_avaliacoes; ?> Avaliações)</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($is_disponivel): ?>
                        <p class="price">R$ <?php echo number_format($produto['preco'], 2, ',', '.'); ?></p>

                        <form id="add-to-cart-form" onsubmit="return false;">
                            <input type="hidden" id="produto-id" value="<?php echo $produto_id; ?>">
                            <div class="quantity-selector">
                                <label for="quantity">Quantidade:</label>
                                <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?php echo $produto['estoque']; ?>">
                            </div>
                            <button type="button" class="btn-comprar" id="btn-comprar">Comprar</button>
                        </form>

                        <?php if ($exibir_calculador_frete): ?>
                            <div class="shipping-calculator">
                                <label for="cep">Simulador de Frete</label>
                                <form class="shipping-form">
                                    <input type="text" id="cep" placeholder="Digite seu CEP">
                                    <button type="submit">Calcular</button>
                                </form>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="product-unavailable-box">
                            <h2 class="unavailable-title">NÃO DISPONÍVEL</h2>
                            <p class="unavailable-text">Avise-me quando estiver disponível</p>
                            <form action="#" method="POST" class="notify-me-form">
                                <input type="hidden" name="produto_id" value="<?php echo $produto_id; ?>">
                                <input type="email" name="email_avise" placeholder="Seu E-mail" required>
                                <button type="submit" name="avise_me">OK</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="product-details-bottom">
                <div class="product-tabs">
                    <button class="tab-link active" data-tab="descricao">Descrição Geral</button>
                    <button class="tab-link" data-tab="avaliacoes">Avaliações (<?php echo $total_avaliacoes; ?>)</button>
                </div>

                <div id="tab-descricao" class="tab-content active">
                    <p><?php echo nl2br(htmlspecialchars($produto['descricao'])); ?></p>
                </div>

                <div id="tab-avaliacoes" class="tab-content">
                    <div class="review-list-wrapper">
                        <?php if ($total_avaliacoes > 0): ?>
                            <?php foreach ($avaliacoes as $avaliacao): ?>
                                <div class="avaliacao-item">
                                    <div class="avaliacao-header">
                                        <div class="avaliador-info">
                                            <div class="avaliador-avatar">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.615-7.812-1.7a.75.75 0 0 1-.437-.695Z" clip-rule="evenodd" /></svg>
                                            </div>
                                            <div class="avaliador-nome-data">
                                                <strong><?php echo htmlspecialchars($avaliacao['nome_exibicao']); ?></strong>
                                                <span class="avaliacao-date">Avaliado em <?php echo (new DateTime($avaliacao['data_avaliacao']))->format('d/m/Y'); ?></span>
                                            </div>
                                        </div>
                                        <span class="avaliacao-date"><?php echo (new DateTime($avaliacao['data_avaliacao']))->format('d/m/Y'); ?></span>
                                    </div>

                                    <div class="avaliacao-rating">
                                        <?php
                                            $classificacao = (int)$avaliacao['classificacao'];
                                            $empty_stars = 5 - $classificacao;
                                            for ($i = 0; $i < $classificacao; $i++) { echo '<span class="star">★</span>'; }
                                            for ($i = 0; $i < $empty_stars; $i++) { echo '<span class="star" style="opacity: 0.3;">★</span>'; }
                                        ?>
                                    </div>

                                    <?php if (!empty($avaliacao['comentario'])): ?>
                                        <p class="avaliacao-comentario"><?php echo nl2br(htmlspecialchars($avaliacao['comentario'])); ?></p>
                                    <?php endif; ?>

                                    <?php if (!empty($avaliacao['foto_url'])): ?>
                                        <div class="avaliacao-foto">
                                            <img src="<?php echo htmlspecialchars($avaliacao['foto_url']); ?>" alt="Foto do Cliente">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                        <?php else: ?>
                            <p>Nenhuma avaliação encontrada para este produto ainda.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($produtos_relacionados)): ?>
            <section class="product-related-section">
                 <h2>Produtos Relacionados</h2>
                 <div class="product-grid">
                     <?php foreach ($produtos_relacionados as $rel_produto): ?>
                         <?php $is_rel_disponivel = ($rel_produto['ativo'] && $rel_produto['estoque'] > 0); ?>
                         <div class="product-item">
                             <a href="produto_detalhe.php?id=<?php echo $rel_produto['id']; ?>">
                                 <div class="product-image-container">
                                     <img src="<?php echo htmlspecialchars($rel_produto['imagem_url'] ?? 'uploads/placeholder.png'); ?>" alt="<?php echo htmlspecialchars($rel_produto['nome']); ?>">

                                     <?php if ($is_rel_disponivel): ?>
                                     <div class="product-hover-buttons">
                                         <button type="button" class="btn-hover btn-spy" data-id="<?php echo $rel_produto['id']; ?>">
                                             <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                             ESPIAR
                                         </button>
                                         <button type="button" class="btn-hover btn-buy-index" data-id="<?php echo $rel_produto['id']; ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bag" viewBox="0 0 16 16"><path d="M8 1a2.5 2.5 0 0 1 2.5 2.5V4h-5v-.5A2.5 2.5 0 0 1 8 1m3.5 3v-.5a3.5 3.5 0 1 0-7 0V4H1v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4zM2 5h12v9a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1z"/></svg>
                                            COMPRAR
                                         </button>
                                     </div>
                                     <?php else: ?>
                                     <div class="product-hover-buttons esgotado">
                                          <span class="btn-esgotado">Esgotado</span>
                                     </div>
                                     <?php endif; ?>
                                 </div>
                                 <h3><?php echo htmlspecialchars($rel_produto['nome']); ?></h3>
                                 <p class="price">R$ <?php echo number_format($rel_produto['preco'], 2, ',', '.'); ?></p>
                             </a>
                         </div>
                     <?php endforeach; ?>
                 </div>
            </section>
            <?php endif; ?>
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
        // --- FUNÇÃO DE ZOOM (LUPA) ---
        function initImageZoom(imgID, resultID) {
            var img, lens, result, cx, cy;
            img = document.getElementById(imgID);
            result = document.getElementById(resultID);
            if (!img || !result) { return; }

            var oldLens = document.querySelector(".zoom-lens");
            if(oldLens) { oldLens.remove(); }

            lens = document.createElement("DIV");
            lens.setAttribute("class", "zoom-lens");
            img.parentElement.insertBefore(lens, img);

            function showLens(e) {
                if (window.innerWidth < 992) return;
                if (img.naturalWidth === 0) return;

                lens.style.display = "block";
                result.style.display = "block";

                cx = result.offsetWidth / lens.offsetWidth;
                cy = result.offsetHeight / lens.offsetHeight;

                result.style.backgroundImage = "url('" + img.src + "')";
                result.style.backgroundSize = (img.width * cx) + "px " + (img.height * cy) + "px";

                moveLens(e);
            }

            function hideLens() {
                lens.style.display = "none";
                result.style.display = "none";
            }

            function moveLens(e) {
                if (window.innerWidth < 992) return;
                var pos, x, y;
                e.preventDefault();
                pos = getCursorPos(e);

                x = pos.x - (lens.offsetWidth / 2);
                y = pos.y - (lens.offsetHeight / 2);

                if (x > img.width - lens.offsetWidth) {x = img.width - lens.offsetWidth;}
                if (x < 0) {x = 0;}
                if (y > img.height - lens.offsetHeight) {y = img.height - lens.offsetHeight;}
                if (y < 0) {y = 0;}

                lens.style.left = x + "px";
                lens.style.top = y + "px";
                result.style.backgroundPosition = "-" + (x * cx) + "px -" + (y * cy) + "px";
            }

            function getCursorPos(e) {
                var a, x = 0, y = 0;
                e = e || window.event;
                a = img.getBoundingClientRect();
                x = e.pageX - (a.left + window.scrollX);
                y = e.pageY - (a.top + window.scrollY);
                return {x : x, y : y};
            }

            img.parentElement.addEventListener("mousemove", moveLens);
            img.parentElement.addEventListener("mouseenter", showLens);
            img.parentElement.addEventListener("mouseleave", hideLens);

            window.addEventListener('resize', hideLens);
        }

        // --- Script Central DOMContentLoaded (Específico da Página) ---
        document.addEventListener('DOMContentLoaded', function() {

            // --- LÓGICA DAS ABAS (Descrição/Avaliação) ---
            const tabLinks = document.querySelectorAll('.tab-link');
            const tabContents = document.querySelectorAll('.tab-content');
            tabLinks.forEach(link => {
                link.addEventListener('click', () => {
                    const tabId = link.getAttribute('data-tab');
                    tabLinks.forEach(item => item.classList.remove('active'));
                    tabContents.forEach(item => item.classList.remove('active'));
                    link.classList.add('active');
                    document.getElementById('tab-' + tabId).classList.add('active');
                });
            });

            // --- INICIALIZA O ZOOM DA IMAGEM ---
            const mainImage = document.getElementById("product-main-image");
            if (mainImage) {
                 if (mainImage.complete) {
                     initImageZoom("product-main-image", "zoom-result");
                 } else {
                     mainImage.addEventListener('load', function() {
                         initImageZoom("product-main-image", "zoom-result");
                     });
                 }
            }

            // --- LÓGICA DA GALERIA (THUMBNAILS E VÍDEO) ---
            const vimeoEmbedBase = "https://player.vimeo.com/video/";
            const thumbItems = document.querySelectorAll('.thumb-item');
            const mainView = document.querySelector('.product-main-view');
            const mainImageWrapper = document.getElementById('main-image-wrapper');
            const mainImageEl = document.getElementById('product-main-image');

            const videoWrapper = document.getElementById('video-wrapper');
            const videoCover = document.getElementById('video-cover');
            const videoPlayButton = document.getElementById('video-play-button');
            const videoIframe = document.getElementById('video-iframe');

            const zoomResult = document.getElementById('zoom-result');

            thumbItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    thumbItems.forEach(thumb => thumb.classList.remove('active'));
                    this.classList.add('active');
                    const type = this.getAttribute('data-type');
                    const url = this.getAttribute('href');

                    if (zoomResult) zoomResult.style.display = 'none';
                    const lens = document.querySelector('.zoom-lens');
                    if (lens) lens.style.display = 'none';
                    if (videoIframe) videoIframe.src = ''; // Para o vídeo anterior

                    if (type === 'imagem') {
                        mainView.classList.remove('video-mode');
                        if (mainImageWrapper) mainImageWrapper.style.display = 'block';
                        if (videoWrapper) videoWrapper.style.display = 'none';

                        // Reseta o vídeo
                        if(videoCover) videoCover.style.display = 'none';
                        if(videoPlayButton) videoPlayButton.style.display = 'none';
                        if(videoIframe) videoIframe.style.display = 'none';

                        if (mainImageEl) {
                            mainImageEl.src = url;
                            if (mainImageEl.complete) { initImageZoom("product-main-image", "zoom-result"); }
                            else { mainImageEl.onload = function() { initImageZoom("product-main-image", "zoom-result"); } }
                        }
                    } else if (type === 'video') {
                        mainView.classList.add('video-mode');
                        if (mainImageWrapper) mainImageWrapper.style.display = 'none';
                        if (videoWrapper) videoWrapper.style.display = 'block';

                        // Reseta para o modo "capa"
                        if(videoCover) videoCover.style.display = 'block';
                        if(videoPlayButton) videoPlayButton.style.display = 'block';
                        if(videoIframe) videoIframe.style.display = 'none';

                        // Define o data-vimeo-src no iframe (se já não estiver)
                        const vimeoId = url.split('/').pop().split('?')[0];
                        const dataSrc = vimeoEmbedBase + vimeoId;
                        if (videoIframe) videoIframe.setAttribute('data-vimeo-src', dataSrc);
                    }
                });
            });

            // --- LÓGICA DO BOTÃO PLAY DO VÍDEO ---
            if (videoPlayButton) {
                videoPlayButton.addEventListener('click', function() {
                    const embedUrl = videoIframe.getAttribute('data-vimeo-src');
                    videoIframe.src = embedUrl + (embedUrl.includes('?') ? '&' : '?') + "autoplay=1";

                    videoIframe.style.display = 'block';
                    videoCover.style.display = 'none';
                    videoPlayButton.style.display = 'none';
                });
            }

            // --- LISTENER DO BOTÃO COMPRAR (Específico desta página) ---
            const btnComprar = document.getElementById('btn-comprar');
            if (btnComprar) {
                btnComprar.addEventListener('click', async () => {
                    const produtoId = document.getElementById('produto-id').value;
                    const quantidade = document.getElementById('quantity').value;
                    btnComprar.textContent = 'Adicionando...';
                    btnComprar.disabled = true;
                    try {
                        const addResponse = await fetch('cart_manager.php?action=add', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ produto_id: produtoId, quantidade: quantidade })
                        });
                        const addData = await addResponse.json();
                        if (addData.status === 'success') {
                            await updateCart();
                            openCart();
                        } else {
                            alert(addData.message || 'Erro ao adicionar produto.');
                        }
                    } catch (error) {
                        console.error('Erro no fetch:', error);
                        alert('Erro ao se conectar. Tente novamente.');
                    } finally {
                        btnComprar.textContent = 'Comprar';
                        btnComprar.disabled = false;
                    }
                });
            }

            // --- NOVO: LISTENER DA LISTA DE DESEJOS ---
            const wishlistBtn = document.getElementById('wishlist-btn');
            if (wishlistBtn) {
                wishlistBtn.addEventListener('click', async function() {
                    const produtoId = this.getAttribute('data-id');

                    try {
                        const formData = new FormData();
                        formData.append('produto_id', produtoId);

                        const response = await fetch('api/toggle_wishlist.php', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();

                        if (data.status === 'success') {
                            if (data.action === 'added') {
                                this.classList.add('added');
                            } else {
                                this.classList.remove('added');
                            }
                        } else {
                            // Se o erro for 'login', redireciona para o login
                            if (data.action === 'login') {
                                window.location.href = 'login.php?redirect=produto_detalhe.php?id=' + produtoId;
                            } else {
                                // Mostra outros erros (ex: ID inválido)
                                alert(data.message);
                            }
                        }
                    } catch (error) {
                        console.error('Erro ao adicionar à lista de desejos:', error);
                        alert('Erro de conexão. Tente novamente.');
                    }
                });
            }

            // --- LÓGICA MODAL DE INFORMAÇÃO (Erro de DB) ---
            <?php if (isset($errors['db']) && !empty($page_alert_message)): ?>
                 const infoModal = document.getElementById('info-modal');
                 const infoModalMessage = document.getElementById('info-modal-message');
                 if (infoModal && infoModalMessage) {
                    infoModalMessage.innerHTML = <?php echo json_encode($page_alert_message); ?>;
                    infoModal.classList.add('modal-open');
                 }
            <?php endif; ?>

        }); // Fim do DOMContentLoaded
    </script>

</body>
</html>