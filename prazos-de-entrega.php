<?php
// /prazos-de-entrega.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php'; // Necessário para $pdo
require_once 'funcoes.php';   // Para carregarConfigApi e processarConteudoPagina

// Carrega as constantes globais (NOME_DA_LOJA, TELEFONE_CONTATO, etc.)
if (isset($pdo)) {
    carregarConfigApi($pdo);
}

// --- LÓGICA DA PÁGINA (DINÂMICA) ---

// 1. O título da página é estático
$page_title = 'Prazos de Entrega';

// 2. Buscar as formas de envio ativas do banco
$formas_de_envio = [];
try {
    // Busca nome e descrição. Ordena pelo ID ou pode ser por 'custo_base' se preferir.
    $stmt = $pdo->query("SELECT nome, descricao
                         FROM formas_envio
                         WHERE ativo = true
                         ORDER BY id ASC");
    $formas_de_envio = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erro ao buscar formas de envio: " . $e->getMessage());
    // $formas_de_envio continuará sendo um array vazio, e o HTML exibirá uma mensagem.
}

// 3. Definir os textos estáticos (gerais) da página
//    Usaremos placeholders para o contato
$texto_geral_intro = "<p>O prazo de entrega varia de acordo com a localidade.</p>";

$texto_geral_aviso = <<<HTML
<p><strong>Atenção:</strong> Nas compras via cartão de crédito, a operadora de cartão pode demorar de 1 a 24 horas para aprovar o pedido, o que pode atrasar a entrega.</p>
<p>Caso houver a indisponibilidade de estoque de algum produto, entraremos em contato para negociarmos a devolução do dinheiro ou a troca do produto por outro similar.</p>
HTML;

$texto_contato = <<<HTML
<hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
<p><strong>[NOME_DA_LOJA]</strong><br>
Telefone e Whatsapp: [TELEFONE_CONTATO]<br>
E-mail: [EMAIL_CONTATO]</p>
HTML;

// 4. Processar apenas o texto de contato (que tem placeholders)
$texto_contato_final = processarConteudoPagina($texto_contato);

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
        :root {
            /* Cores base - ajuste conforme seu style.css */
            --green-accent: #a4d32a;
            --text-color-dark: #333;
            --text-color-medium: #666;
            --border-color-light: #eee;
            --page-bg-color: #f9f9f9;
        }

        /* Estilos do container da página */
        .institutional-page .main-content {
             /* Define um fundo para a página, caso o body não tenha */
            background-color: var(--page-bg-color, #f9f9f9);
            padding-top: 30px;
            padding-bottom: 30px;
        }

        .institutional-content-wrapper {
            background-color: #fff;
            border: 1px solid var(--border-color-light);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            padding: 30px 40px;
            margin-bottom: 30px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
            border-radius: 8px; /* Borda arredondada suave */
        }

        /* Estilos do conteúdo */
        .institutional-content h1 {
            font-size: 1.8em;
            font-weight: bold;
            color: var(--text-color-dark);
            margin: 0 0 25px 0;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color-light);
        }
        /* Título de cada forma de envio */
        .institutional-content h3 {
            font-size: 1.3em;
            font-weight: bold;
            color: var(--text-color-dark);
            margin-top: 30px;
            margin-bottom: 15px;
        }
        .institutional-content p {
            font-size: 1em;
            color: var(--text-color-medium);
            line-height: 1.7;
            margin-bottom: 1.2em;
        }
        .institutional-content strong {
            color: var(--text-color-dark);
            font-weight: bold;
        }
        .institutional-content ul {
            list-style-type: disc;
            margin-left: 20px;
            margin-bottom: 1.2em;
            color: var(--text-color-medium);
            padding-left: 15px; /* Espaçamento interno */
        }
         .institutional-content li {
             margin-bottom: 8px;
             line-height: 1.6;
         }

        /* --- CSS Responsivo --- */
        @media (max-width: 768px) {
            .institutional-content-wrapper {
                padding: 20px 25px;
                margin-bottom: 20px;
            }
            .institutional-content h1 {
                font-size: 1.5em;
                margin-bottom: 20px;
                padding-bottom: 10px;
            }
            .institutional-content h3 {
                font-size: 1.2em;
            }
            .institutional-content p {
                font-size: 0.95em;
            }
        }
         @media (max-width: 576px) {
            .institutional-content-wrapper {
                padding: 15px;
                margin-left: 10px;  /* Garante margens mínimas */
                margin-right: 10px; /* Garante margens mínimas */
            }
             .institutional-content h1 {
                font-size: 1.3em;
            }
        }

    </style>
</head>
<body class="institutional-page">

    <?php include 'templates/header.php'; ?>

    <main class="main-content container">

        <div class="institutional-content-wrapper">

            <h1><?php echo $page_title; ?></h1>

            <div class="institutional-content">
                <?php
                    // 1. Renderiza o parágrafo de introdução estático
                    echo $texto_geral_intro;

                    // 2. Renderiza o loop dinâmico das formas de envio
                    if (empty($formas_de_envio)) {
                        echo "<p>Nenhuma forma de envio ativa encontrada no momento. Por favor, entre em contato para mais informações.</p>";
                    } else {
                        foreach ($formas_de_envio as $forma) {
                            // Exibe o Nome (ex: "Sedex")
                            echo "<h3>" . htmlspecialchars($forma['nome']) . "</h3>";

                            // Exibe a Descrição (ex: "Nas capitais, o prazo normal é 1 a 2 dias...")
                            // Usamos nl2br para converter quebras de linha do banco em <br>
                            $descricao = !empty($forma['descricao']) ? $forma['descricao'] : "Informações de prazo não disponíveis.";
                            echo "<p>" . nl2br(htmlspecialchars($descricao)) . "</p>";
                        }
                    }

                    // 3. Renderiza os avisos estáticos
                    echo $texto_geral_aviso;

                    // 4. Renderiza o bloco de contato (já processado)
                    echo $texto_contato_final;
                ?>
            </div>

        </div>

    </main>

    <?php include 'templates/footer.php'; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

</body>
</html>