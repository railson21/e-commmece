<?php
// /politica-de-privacidade.php
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
$page_title = 'Política de Privacidade';

// 2. O conteúdo agora é estático (definido aqui)
$page_content_raw = <<<HTML
<p>A sua privacidade é de extrema importância para nós. Esta Política de Privacidade descreve como a <strong>[NOME_DA_LOJA]</strong> coleta, usa, armazena e protege as informações pessoais dos nossos usuários e clientes.</p>

<h3>1. Informações que Coletamos</h3>
<p>Para fornecer nossos serviços, coletamos os seguintes tipos de informações:</p>
<ul>
    <li><strong>Informações Fornecidas por Você:</strong> Nome completo, CPF, endereço de e-mail, número de telefone, endereço de entrega e cobrança, e dados de pagamento ao finalizar uma compra.</li>
    <li><strong>Informações Coletadas Automaticamente:</strong> Endereço IP, tipo de navegador, sistema operacional, informações sobre seu dispositivo, páginas visitadas em nosso site e dados de cookies, essenciais para o funcionamento do carrinho de compras e análise de usabilidade.</li>
    <li><strong>Informações de Terceiros:</strong> Recebemos informações de nossos gateways de pagamento (como confirmação de aprovação ou recusa do pagamento) e de transportadoras (como atualizações de rastreio).</li>
</ul>

<h3>2. Como Usamos Suas Informações</h3>
<p>As informações coletadas são utilizadas para as seguintes finalidades:</p>
<ul>
    <li>Processar, faturar e enviar seus pedidos.</li>
    <li>Gerenciar sua conta de usuário em nosso site.</li>
    <li>Prestar suporte ao cliente e responder às suas solicitações.</li>
    <li>Enviar comunicações transacionais (confirmação de pedido, status de entrega, etc.).</li>
    <li>Enviar comunicações de marketing (newsletters, promoções), caso você tenha optado por recebê-las, podendo se descadastrar a qualquer momento.</li>
    <li>Prevenir fraudes, garantir sua segurança e cumprir obrigações legais.</li>
    <li>Analisar o uso do site para melhorar a experiência do usuário e nossos produtos.</li>
</ul>

<h3>3. Compartilhamento de Informações</h3>
<p>Não compartilhamos suas informações pessoais com terceiros, exceto quando necessário para cumprir obrigações legais ou para processar seu pedido (ex: com a transportadora ou gateway de pagamento).</p>
<p>Seus dados são compartilhados estritamente com:</p>
<ul>
    <li><strong>Gateways de Pagamento:</strong> Para processar a transação financeira.</li>
    <li><strong>Transportadoras e Correios:</strong> Para realizar a entrega do seu pedido.</li>
    <li><strong>Obrigações Legais:</strong> Caso sejamos obrigados por lei ou ordem judicial a fornecer informações.</li>
</ul>
<p>Não vendemos ou alugamos suas informações pessoais para fins de marketing de terceiros em nenhuma circunstância.</p>

<h3>4. Segurança dos Dados</h3>
<p>Empregamos medidas de segurança técnicas e administrativas para proteger suas informações. Nosso site utiliza criptografia SSL (Secure Socket Layer) para garantir que a transmissão de dados sensíveis (como dados de pagamento) seja segura. O acesso às suas informações é restrito apenas aos funcionários que necessitam delas para executar suas funções.</p>
<p>Embora nos esforcemos para proteger seus dados, nenhum método de transmissão pela internet ou armazenamento eletrônico é 100% seguro.</p>

<h3>5. Cookies e Tecnologias de Rastreamento</h3>
<p>Cookies são pequenos arquivos de texto armazenados em seu dispositivo. Utilizamos cookies essenciais para o funcionamento do site (como manter produtos no seu carrinho de compras e sua sessão logada) e cookies de análise (como Google Analytics) para entender como os visitantes usam nosso site, o que nos ajuda a melhorar.</p>
<p>Você pode gerenciar ou desativar os cookies diretamente nas configurações do seu navegador, porém, isso pode afetar a funcionalidade de algumas partes do nosso site.</p>

<h3>6. Seus Direitos (LGPD)</h3>
<p>Em conformidade com a Lei Geral de Proteção de Dados (LGPD - Lei nº 13.709/2018), você tem o direito de:</p>
<ul>
    <li>Confirmar a existência de tratamento de seus dados.</li>
    <li>Acessar seus dados pessoais.</li>
    <li>Corrigir dados incompletos, inexatos ou desatualizados.</li>
    <li>Solicitar a anonimização, bloqueio ou eliminação de dados desnecessários ou tratados em desconformidade com a lei.</li>
    <li>Solicitar a portabilidade dos seus dados a outro fornecedor de serviço.</li>
    <li>Revogar o consentimento, quando aplicável.</li>
</ul>
<p>Para exercer seus direitos, entre em contato conosco através dos nossos canais de atendimento.</p>

<h3>7. Retenção de Dados</h3>
<p>Manteremos suas informações pessoais armazenadas apenas pelo tempo necessário para cumprir as finalidades para as quais foram coletadas, incluindo o cumprimento de obrigações legais, fiscais ou contratuais.</p>

<h3>8. Alterações nesta Política</h3>
<p>A <strong>[NOME_DA_LOJA]</strong> reserva-se o direito de modificar esta Política de Privacidade a qualquer momento. As alterações entrarão em vigor imediatamente após sua publicação no site. Recomendamos que você revise esta página periodicamente para se manter atualizado.</p>

<h3>9. Contato</h3>
<p>Se você tiver qualquer dúvida sobre esta Política de Privacidade ou sobre como tratamos seus dados, entre em contato conosco:</p>

<hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

<p><strong>[NOME_DA_LOJA]</strong><br>
Telefone e Whatsapp: [TELEFONE_CONTATO]<br>
E-mail: [EMAIL_CONTATO]</p>
HTML;


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