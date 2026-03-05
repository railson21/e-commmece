<?php
// /sobre-nos.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php'; // Necessário para $pdo (que funcoes.php usa)
require_once 'funcoes.php';   // Para carregarConfigApi e processarConteudoPagina

// Carrega as constantes globais (NOME_DA_LOJA, TELEFONE_CONTATO, etc.)
if (isset($pdo)) {
    carregarConfigApi($pdo);
}

// --- LÓGICA DA PÁGINA (ESTÁTICA) ---

// 1. O título da página agora é estático
$page_title = 'Sobre Nós';

// 2. O conteúdo agora é estático (definido aqui)
// ▼▼▼ MODIFICADO (Placeholder atualizado) ▼▼▼
$page_content_raw = <<<HTML
<p>Bem-vindo à <strong>[NOME_DA_LOJA]</strong>! Nascemos de uma simples ideia: acreditamos que comprar online deve ser uma experiência fácil, segura e prazerosa.</p>

<p>Mais do que apenas uma loja, somos um time de entusiastas apaixonados por [CATEGORIA_DA_LOJA] e dedicados a encontrar e oferecer os melhores produtos para você.</p>

<h3>Nossa Missão</h3>
<p>Nossa missão é simples: entregar produtos de alta qualidade com a maior conveniência e um atendimento que faça você se sentir em casa. Em um mercado tão competitivo, sabemos que o que nos diferencia é o cuidado que temos em cada etapa da sua jornada conosco.</p>

<h3>Nossos Valores</h3>
<ul>
    <li><strong>Qualidade Inegociável:</strong> Selecionamos a dedo cada item em nosso catálogo. Se não é bom o suficiente para nós, não é bom o suficiente para você.</li>
    <li><strong>Atendimento Excepcional:</strong> Você não é apenas mais um número de pedido. Nossa equipe de suporte está sempre pronta para ajudar, seja para tirar uma dúvida sobre um produto ou para resolver qualquer questão após a compra.</li>
    <li><strong>Segurança e Confiança:</strong> Sua tranquilidade é nossa prioridade. Investimos nas melhores plataformas e práticas de segurança para garantir que seus dados e sua compra estejam 100% protegidos.</li>
    <li><strong>Paixão pelo que Fazemos:</strong> Amamos nosso trabalho e isso se reflete na curadoria de nossos produtos e no relacionamento com nossos clientes.</li>
</ul>

<h3>Por que Comprar Conosco?</h3>
<p>Ao escolher a <strong>[NOME_DA_LOJA]</strong>, você não está apenas comprando um produto; você está apoiando um time que se importa de verdade. Garantimos um processo de compra ágil, transparência total no rastreio do seu pedido e um canal de comunicação sempre aberto.</p>

<p>Explore nosso site, descubra nossos produtos e sinta-se à vontade para entrar em contato se precisar de qualquer coisa. Estamos aqui por você!</p>

<hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

<p><strong>[NOME_DA_LOJA]</strong><br>
Telefone e Whatsapp: [TELEFONE_CONTATO]<br>
E-mail: [EMAIL_CONTATO]</p>
HTML;
// ▲▲▲ FIM DA MODIFICAÇÃO ▲▲▲


// 3. Processa o conteúdo bruto para substituir os placeholders
$page_content_final = processarConteudoPagina($page_content_raw);

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
                    // Renderiza o HTML final (com placeholders substituídos)
                    echo $page_content_final;
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