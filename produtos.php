<?php
// produtos.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // <-- DEVE SER A PRIMEIRA LINHA
}
require_once 'config/db.php'; // Garante $pdo para includes

// --- 1. IDENTIFICAR A CATEGORIA ATUAL ---
$categoria_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$categoria_info = null;

if ($categoria_id === 0) {
    // Vamos redirecionar para a home se nenhuma categoria for especificada
    header('Location: index.php');
    exit;
}

try {
    $stmt_cat = $pdo->prepare("SELECT nome FROM categorias WHERE id = :id");
    $stmt_cat->execute(['id' => $categoria_id]);
    $categoria_info = $stmt_cat->fetch();

    if (!$categoria_info) {
        // Redireciona se a categoria não existir
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    die("Erro ao buscar categoria: ". $e->getMessage());
}


// --- 2. OBTER FILTROS DA URL ---
$marcas_selecionadas = $_GET['marcas'] ?? [];
$preco_selecionado = $_GET['preco'] ?? '';
$classificacao = $_GET['classificar'] ?? 'lancamento';

// --- 3. DEFINIR FAIXAS DE PREÇO ESTÁTICAS ---
$faixas_preco = [
    "0-174.79" => "Até R$ 174,79",
    "174.80-348.79" => "De R$ 174,80 a R$ 348,79",
    "348.80-522.79" => "De R$ 348,80 a R$ 522,79",
    "522.80-696.79" => "De R$ 522,80 a R$ 696,79",
    "696.80-870.79" => "De R$ 696,80 a R$ 870,79",
    "870.80-1047.60" => "De R$ 870,80 a R$ 1.047,60",
    "1047.61-999999" => "Acima de R$ 1.047,60"
];


// --- 4. BUSCAR MARCAS DISPONÍVEIS *NESTA CATEGORIA* (para o filtro) ---
$marcas_disponiveis = [];
try {
    $sql_marcas = "
        SELECT m.id, m.nome, COUNT(p.id) as total_produtos
        FROM marcas m
        JOIN produtos p ON m.id = p.marca_id
        JOIN produto_categorias pc ON p.id = pc.produto_id
        WHERE pc.categoria_id = :cat_id AND p.ativo = true
        GROUP BY m.id, m.nome
        ORDER BY m.nome ASC
    ";
    $stmt_marcas = $pdo->prepare($sql_marcas);
    $stmt_marcas->execute(['cat_id' => $categoria_id]);
    $marcas_disponiveis = $stmt_marcas->fetchAll();

} catch (PDOException $e) {
    error_log("Erro ao buscar filtros de marca: ". $e->getMessage());
}


// --- 5. CONSTRUIR E EXECUTAR A QUERY PRINCIPAL DE PRODUTOS ---
$params = [':cat_id' => $categoria_id];
$sql_produtos = "
    SELECT p.id, p.nome, p.preco, p.imagem_url, p.ativo, p.estoque, p.criado_em
    FROM produtos p
    JOIN produto_categorias pc ON p.id = pc.produto_id
    WHERE pc.categoria_id = :cat_id AND p.ativo = true
";

// Adicionar filtro de MARCA
if (!empty($marcas_selecionadas) && is_array($marcas_selecionadas)) {
    $marca_placeholders = [];
    $i = 0;
    foreach ($marcas_selecionadas as $marca_id) {
        $key = ":marca_id_".$i;
        $marca_placeholders[] = $key;
        $params[$key] = (int)$marca_id;
        $i++;
    }
    $sql_produtos .= " AND p.marca_id IN (" . implode(',', $marca_placeholders) . ")";
}

// Adicionar filtro de PREÇO
if (!empty($preco_selecionado) && isset($faixas_preco[$preco_selecionado])) {
    list($min, $max) = explode('-', $preco_selecionado);
    $sql_produtos .= " AND p.preco BETWEEN :min_preco AND :max_preco";
    $params[':min_preco'] = (float)$min;
    $params[':max_preco'] = (float)$max;
}

// Adicionar CLASSIFICAÇÃO
switch ($classificacao) {
    case 'preco_asc':
        $sql_produtos .= " ORDER BY p.preco ASC";
        break;
    case 'preco_desc':
        $sql_produtos .= " ORDER BY p.preco DESC";
        break;
    case 'nome_asc':
        $sql_produtos .= " ORDER BY p.nome ASC";
        break;
    case 'nome_desc':
        $sql_produtos .= " ORDER BY p.nome DESC";
        break;
    case 'lancamento':
    default:
        $sql_produtos .= " ORDER BY p.criado_em DESC"; // Padrão
        break;
}

$produtos = [];
try {
    $stmt_produtos = $pdo->prepare($sql_produtos);
    $stmt_produtos->execute($params);
    $produtos = $stmt_produtos->fetchAll();
} catch (PDOException $e) {
     error_log("Erro ao buscar produtos: ". $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($categoria_info['nome']); ?> - Minha Loja</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS ESPECÍFICO DA PÁGINA DE PRODUTOS (Filtros e Grid)
           ========================================================== */

        .breadcrumbs {
            font-size: 0.8em;
            color: var(--text-color-medium);
            margin-bottom: 20px;
        }
        .breadcrumbs a { color: var(--text-color-medium); }
        .breadcrumbs a:hover { color: var(--green-accent); }
        .breadcrumbs span { color: var(--text-color-dark); font-weight: bold; }
        .category-header h1 { font-size: 1.8em; font-weight: bold; color: var(--text-color-dark); margin: 0 0 10px 0; text-transform: uppercase; }
        .page-layout { display: flex; gap: 30px; }

        /* --- Barra Lateral de Filtros --- */
        .filter-sidebar { width: 250px; flex-shrink: 0; font-size: 0.9em; }
        .filter-group { margin-bottom: 25px; border-bottom: 1px solid var(--border-color-light); padding-bottom: 25px; }
         .filter-group:last-of-type { border-bottom: none; }
        .filter-group h3 { font-size: 1.1em; color: var(--text-color-dark); margin-top: 0; margin-bottom: 15px; text-transform: uppercase; font-weight: bold; }
        .filter-group ul { list-style: none; padding: 0; margin: 0; max-height: 250px; overflow-y: auto; }
        .filter-group li { margin-bottom: 10px; }
        .filter-group label { display: flex; align-items: center; color: var(--text-color-medium); cursor: pointer; transition: color 0.2s ease; }
        .filter-group label:hover { color: var(--text-color-dark); }
        .filter-group input[type="checkbox"],
        .filter-group input[type="radio"] { margin-right: 10px; accent-color: var(--green-accent); cursor: pointer; }
        .filter-group .count { margin-left: auto; color: var(--text-color-light); }
        .btn-filtrar { width: 100%; padding: 12px; background-color: var(--green-accent); color: #fff; border: none; border-radius: 4px; font-size: 1em; font-weight: bold; cursor: pointer; transition: background-color 0.2s ease; }
        .btn-filtrar:hover { background-color: var(--cor-acento-hover); } /* AJUSTE DE HOVER AQUI */

        /* --- Área de Listagem de Produtos --- */
        .product-listing-area { flex-grow: 1; }
        .sorting-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .sorting-bar label { font-size: 0.9em; color: var(--text-color-medium); margin-right: 10px; }
        .sorting-bar select { padding: 8px 12px; border: 1px solid var(--border-color-medium); border-radius: 4px; font-size: 0.9em; background-color: #fff; }

        /* Estilos de Grid de Produto (copiados da index e ajustados) */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr); /* 3 colunas aqui */
            gap: 20px;
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
             height: 180px; /* Altura da imagem no grid */
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
            left: 0;
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

        /* AJUSTE HOVER DE FUNDO */
        .product-item a:hover .product-hover-buttons {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
            background: var(--cor-acento-fundo-hover); /* Usa o fundo de hover configurável */
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

        /* AJUSTE HOVER DO BOTÃO */
        .btn-hover:hover {
             background-color: var(--cor-acento-hover); /* Usa a cor de destaque de hover configurável */
        }

        .btn-hover svg {
            width: 16px;
            height: 16px;
        }
        .product-item h3 {
            font-size: 0.95em;
            color: var(--text-color-dark);
            margin-bottom: 10px;
            height: 2.4em;
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
        .no-products-found {
            text-align: center;
            color: var(--text-color-medium);
            padding: 50px 20px;
            border: 1px dashed var(--border-color-medium);
            border-radius: 8px;
        }

        /* --- Responsividade da Página de Produtos --- */
        @media (max-width: 992px) {
            .product-grid {
                grid-template-columns: repeat(2, 1fr); /* 2 colunas tablet */
            }
             .product-image-container img { height: 160px; }
             .main-content { flex-grow: 1;padding: 20px 0; background-color: #f9f9f9; }


        }

        @media (max-width: 768px) {
            .page-layout { flex-direction: column; }
            .filter-sidebar { width: 100%; }
            .sorting-bar { flex-direction: column; align-items: flex-start; gap: 10px; }
             .sorting-bar label { margin-right: 0; }
             .sorting-bar select { width: 100%; }
             .product-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; }
             .product-image-container img { height: 140px; }
             .product-item h3 { font-size: 0.9em; height: 2.2em; line-height: 1.1em; min-height: 2.2em; }
             .product-item .price { font-size: 1.5rem; }
             .main-content { flex-grow: 1;padding: 20px 0; background-color: #f9f9f9; }

        }
    </style>
</head>
<body>

    <?php include 'templates/header.php'; ?>

    <main class="main-content container">

        <div class="breadcrumbs">
            <a href="index.php">HOME</a> /
            <span><?php echo strtoupper(htmlspecialchars($categoria_info['nome'])); ?></span>
        </div>

        <div class="page-layout">

            <aside class="filter-sidebar">
                <form action="produtos.php" method="GET" id="filter-form">
                    <input type="hidden" name="id" value="<?php echo $categoria_id; ?>">
                    <input type="hidden" name="classificar" id="hidden-classificar" value="<?php echo htmlspecialchars($classificacao); ?>">

                    <div class="filter-group">
                        <h3>Preço</h3>
                        <ul>
                            <?php foreach ($faixas_preco as $range => $label): ?>
                            <li>
                                <label>
                                    <input type="radio" name="preco" value="<?php echo $range; ?>"
                                        <?php if ($preco_selecionado == $range) echo 'checked'; ?>>
                                    <?php echo $label; ?>
                                </label>
                            </li>
                            <?php endforeach; ?>
                            <?php if (!empty($preco_selecionado)): ?>
                            <li>
                                <label>
                                    <input type="radio" name="preco" value="">
                                    Ver todos os preços
                                </label>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <?php if (!empty($marcas_disponiveis)): ?>
                    <div class="filter-group">
                        <h3>Marcas</h3>
                        <ul>
                            <?php foreach ($marcas_disponiveis as $marca): ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="marcas[]" value="<?php echo $marca['id']; ?>"
                                        <?php if (in_array($marca['id'], $marcas_selecionadas)) echo 'checked'; ?>>
                                    <?php echo htmlspecialchars($marca['nome']); ?>
                                    <span class="count">(<?php echo $marca['total_produtos']; ?>)</span>
                                </label>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-filtrar">Filtrar</button>
                </form>
            </aside>
            <section class="product-listing-area">

                <div class="category-header">
                    <h1><?php echo htmlspecialchars($categoria_info['nome']); ?></h1>
                </div>

                <div class="sorting-bar">
                    <label for="classificar-select">Classificar Por</label>
                    <select name="classificar_select" id="classificar-select">
                        <option value="lancamento" <?php if ($classificacao == 'lancamento') echo 'selected'; ?>>Lançamento</option>
                        <option value="preco_asc" <?php if ($classificacao == 'preco_asc') echo 'selected'; ?>>Menor Preço</option>
                        <option value="preco_desc" <?php if ($classificacao == 'preco_desc') echo 'selected'; ?>>Maior Preço</option>
                        <option value="nome_asc" <?php if ($classificacao == 'nome_asc') echo 'selected'; ?>>Nome (A-Z)</option>
                        <option value="nome_desc" <?php if ($classificacao == 'nome_desc') echo 'selected'; ?>>Nome (Z-A)</option>
                    </select>
                </div>

                <?php if (!empty($produtos)): ?>
                    <div class="product-grid">
                        <?php foreach ($produtos as $produto): ?>
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
                    <div class="no-products-found">
                        <p>Nenhum produto encontrado com os filtros selecionados.</p>
                        <a href="produtos.php?id=<?php echo $categoria_id; ?>" style="text-decoration: underline; color: var(--green-accent);">Limpar filtros</a>
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
                       <button class="modal-close" data-dismiss="modal">&times;</button>
                 </div>
                 <div class="modal-body">
                       <form action="login.php" method="POST">
                             <div class="form-group">
                                   <label for="modal_login_email_header_produtos">E-mail ou CPF/CNPJ:</label>
                                   <input type="text" id="modal_login_email_header_produtos" name="modal_login_email" placeholder="Digite seu e-mail ou CPF/CNPJ" required>
                             </div>
                             <div class="form-group">
                                   <label for="modal_login_senha_header_produtos">Senha:</label>
                                   <input type="password" id="modal_login_senha_header_produtos" name="modal_login_senha" placeholder="Digite sua senha" required>
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
            <button class="cart-close-btn" data-dismiss="modal"> <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
                       <path fill-rule="evenodd" d="M4 8a.5.5 0 0 1 .5-.5h5.793L8.146 5.354a.5.5 0 1 1 .708-.708l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708-.708L10.293 8.5H4.5A.5.5 0 0 1 4 8z"/>
                </svg>
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
                 <a href="#" class="cart-continue-btn" data-dismiss="modal" id="cart-continue-shopping">< Continuar Comprando</a> <a href="checkout.php" class="btn btn-primary cart-checkout-btn">COMPRAR AGORA</a>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="info-modal">
        <div class="modal-content">
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
            // --- Lógica Específica de produtos.php ---

            // 1. Atualiza o formulário de filtro quando o <select> de classificação mudar
            $('#classificar-select').on('change', function() {
                var selectedValue = $(this).val();
                $('#hidden-classificar').val(selectedValue);
                $('#filter-form').submit();
            });
        });
    </script>

</body>
</html>