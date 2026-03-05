<?php
// Arquivo: funcoes.php (Funções globais para o E-commerce)

// Define a Timezone Padrão para São Paulo
if (!ini_get('date.timezone')) {
    date_default_timezone_set('America/Sao_Paulo');
}

/**
 * 💡 ATUALIZADO: Carrega TODAS as configurações necessárias (das tabelas
 * config_api E config_site) e define-as como constantes PHP.
 *
 * @param PDO $pdo Objeto de conexão PDO.
 */
function carregarConfigApi(PDO $pdo) {
    // Garante que a função só execute uma vez por request
    static $config_loaded = false;
    if ($config_loaded) {
        return;
    }

    $all_keys = []; // Para fallback

    try {
        // 1. Chaves da config_api (Mailgun e Templates de E-mail)
        $chaves_api = [
            'MAILGUN_API_KEY', 'MAILGUN_DOMAIN', 'MAILGUN_FROM_EMAIL', 'MAILGUN_API_URL',
            'EMAIL_COR_PRINCIPAL', 'EMAIL_COR_FUNDO', 'EMAIL_TEXTO_BEM_VINDO',
            'EMAIL_LINK_TEXTO'
        ];
        $all_keys = array_merge($all_keys, $chaves_api);

        $placeholders_api = implode(',', array_fill(0, count($chaves_api), '?'));

        $stmt_api = $pdo->prepare("SELECT chave, valor FROM config_api WHERE chave IN ($placeholders_api)");
        $stmt_api->execute($chaves_api);
        $config_api = $stmt_api->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($chaves_api as $chave) {
            $valor = $config_api[$chave] ?? '';
            if (!defined($chave)) {
                define($chave, $valor);
            }
        }

        // 2. Chaves da config_site (Informações Gerais da Loja)
        // ▼▼▼ MODIFICADO (Adicionada 'categoria_da_loja') ▼▼▼
        $chaves_site = [
            'nome_da_loja',
            'site_base_url',
            'telefone_contato',
            'email_contato',
            'horario_atendimento',
            'categoria_da_loja' // <--- NOVO
        ];
        // ▲▲▲ FIM DA MODIFICAÇÃO ▲▲▲

        $all_keys = array_merge($all_keys, $chaves_site);

        $placeholders_site = implode(',', array_fill(0, count($chaves_site), '?'));

        $stmt_site = $pdo->prepare("SELECT chave, valor FROM config_site WHERE chave IN ($placeholders_site)");
        $stmt_site->execute($chaves_site);
        $config_site = $stmt_site->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($chaves_site as $chave) {
            // Converte para MAIÚSCULA para virar constante (ex: nome_da_loja -> NOME_DA_LOJA)
            $const_chave = strtoupper($chave);
            $valor = $config_site[$chave] ?? '';
            if (!defined($const_chave)) {
                define($const_chave, $valor);
            }
        }

        $config_loaded = true;

    } catch (PDOException $e) {
        error_log("ERRO AO CARREGAR CONFIG_API/SITE: " . $e->getMessage());
        // Define fallbacks para todas as chaves em caso de falha total
        foreach ($all_keys as $chave) {
            $const_chave = strtoupper($chave);
            if (!defined($const_chave)) {
                define($const_chave, '');
            }
        }
    }
}


/**
 * Envia um e-mail através da API da Mailgun.
 * (Atualizado para usar NOME_DA_LOJA da config_site)
 */
function enviarEmailMailgun($para, $assunto, $html_body) {
    if (!defined('MAILGUN_API_KEY') || empty(MAILGUN_API_KEY) || empty(MAILGUN_DOMAIN) || empty(MAILGUN_FROM_EMAIL)) {
        error_log("ERRO: Configurações da Mailgun ausentes ou vazias (config_api). Impossível enviar e-mail.");
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log("ERRO: Extensão cURL não está instalada. Impossível usar Mailgun.");
        return false;
    }

    // ⭐ Busca NOME_DA_LOJA (vindo da config_site)
    $nome_loja_from = defined('NOME_DA_LOJA') && NOME_DA_LOJA ? NOME_DA_LOJA : 'Sua Loja E-commerce';

    $from_string = $nome_loja_from . " <" . MAILGUN_FROM_EMAIL . ">";

    $ch = curl_init();
    $postData = [
        'from'    => $from_string,
        'to'      => $para,
        'subject' => $assunto,
        'html'    => $html_body,
    ];
    curl_setopt_array($ch, [
        CURLOPT_URL => MAILGUN_API_URL . "/" . MAILGUN_DOMAIN . "/messages",
        CURLOPT_USERPWD => "api:" . MAILGUN_API_KEY,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SAFE_UPLOAD => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        error_log("ERRO MAILGUN cURL: Falha na comunicação: " . $err);
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        error_log("ERRO MAILGUN HTTP {$httpCode}: Resposta: " . $response);
        return false;
    }
}


/**
 * Envia o e-mail de boas-vindas para o novo usuário.
 * (Usa chaves da config_api e config_site)
 */
function enviarEmailBoasVindas($para, $nome) {
    // 1. Usa as constantes
    $cor_principal = EMAIL_COR_PRINCIPAL ?? '#a4d32a'; // (config_api)
    $cor_fundo = EMAIL_COR_FUNDO ?? '#f5f5f5'; // (config_api)
    $link_texto = EMAIL_LINK_TEXTO ?? 'Acessar Minha Conta Agora'; // (config_api)
    $texto_principal = EMAIL_TEXTO_BEM_VINDO ?? 'Sua conta foi criada com sucesso!'; // (config_api)

    // (config_site)
    $nome_da_loja = NOME_DA_LOJA ?? 'Sua Loja E-commerce';

    // Busca a URL base do site (config_site) e constrói o link de login dinamicamente
    $base_url = defined('SITE_BASE_URL') && SITE_BASE_URL ? SITE_BASE_URL : 'http://seusite.com/';
    $link_url = rtrim($base_url, '/') . '/login.php';

    $assunto = "🎉 Bem-vindo(a) à {$nome_da_loja}!";

    $html_body = "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: 'Poppins', Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 20px auto; background-color: {$cor_fundo}; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); }
                .header { background-color: {$cor_principal}; color: #ffffff; padding: 20px 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 24px; }
                .content { padding: 30px; color: #333; line-height: 1.6; }
                .button {
                    display: inline-block;
                    padding: 12px 25px;
                    background-color: {$cor_principal};
                    color: #ffffff !important;
                    text-decoration: none;
                    font-weight: 700;
                    border-radius: 6px;
                    margin-top: 20px;
                    text-align: center;
                }
                .text-center { text-align: center; }
                .footer { padding: 20px 30px; border-top: 1px solid #ddd; text-align: center; font-size: 12px; color: #999; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0; font-size: 24px;'>Boas-vindas à {$nome_da_loja}!</h1>
                </div>
                <div class='content'>
                    <p style='margin-bottom: 20px;'>Olá, <strong>{$nome}</strong>,</p>
                    <p>{$texto_principal}</p>

                    <div class='text-center'>
                        <a href='{$link_url}' class='button'>{$link_texto}</a>
                    </div>

                    <p style='margin-top: 30px; font-size: 0.9em; color: #666;'>
                        Se tiver qualquer dúvida ou precisar de ajuda, entre em contato conosco.
                    </p>
                </div>
                <div class='footer'>
                    Este é um e-mail automático. Por favor, não responda.
                </div>
            </div>
        </body>
        </html>
    ";

    return enviarEmailMailgun($para, $assunto, $html_body);
}

/**
 * Formata uma string de data e hora para o formato brasileiro (dd/mm/aaaa HH:mm).
 */
function formatarDataHoraBR(?string $timestamp, bool $incluir_hora = true): string {
    if (empty($timestamp)) {
        return 'N/A';
    }

    try {
        $date = new DateTime($timestamp);
        $format = $incluir_hora ? "d/m/Y H:i" : "d/m/Y";
        return $date->format($format);

    } catch (Exception $e) {
        error_log("Erro ao formatar data: " . $e->getMessage());
        return 'Data Inválida';
    }
}


/**
 * Envia e-mail de Pagamento Aprovado.
 * (Usa chaves da config_api e config_site)
 */
function enviarEmailPagamentoAprovado(PDO $pdo, $pedido_id) {

    try {
        // 1. Buscar todas as informações (Pedido e Usuário)
        $sql = "SELECT
                    p.*,
                    u.nome AS usuario_nome,
                    u.email AS usuario_email
                FROM pedidos p
                JOIN usuarios u ON p.usuario_id = u.id
                WHERE p.id = :pedido_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['pedido_id' => $pedido_id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            error_log("Falha ao enviar e-mail (Pedido #{$pedido_id}): Pedido não encontrado.");
            return false;
        }

        // 2. Consulta dos Itens do Pedido
        $stmt_itens = $pdo->prepare("
            SELECT pi.quantidade, pi.preco_unitario, pr.nome AS produto_nome
            FROM pedidos_itens pi
            JOIN produtos pr ON pi.produto_id = pr.id
            WHERE pi.pedido_id = :pedido_id
        ");
        $stmt_itens->execute(['pedido_id' => $pedido_id]);
        $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Erro no DB ao buscar dados para e-mail (Pedido #{$pedido_id}): " . $e->getMessage());
        return false;
    }

    // 3. Carregar constantes de e-mail (com fallbacks)
    $nome_da_loja = defined('NOME_DA_LOJA') && NOME_DA_LOJA ? NOME_DA_LOJA : 'Sua Loja E-commerce';
    $cor_principal = defined('EMAIL_COR_PRINCIPAL') && EMAIL_COR_PRINCIPAL ? EMAIL_COR_PRINCIPAL : '#a4d32a';
    $cor_fundo = defined('EMAIL_COR_FUNDO') && EMAIL_COR_FUNDO ? EMAIL_COR_FUNDO : '#f5f5f5';
    $base_url = defined('SITE_BASE_URL') && SITE_BASE_URL ? SITE_BASE_URL : 'http://seusite.com/';

    $link_pedido = rtrim($base_url, '/') . '/detalhes_pedidos.php?id=' . $pedido_id;
    $assunto = "✅ Pagamento Aprovado! Pedido #{$pedido_id} - {$nome_da_loja}";

    // 4. Montar o HTML do E-mail

    $itens_html = "";
    foreach ($itens as $item) {
        $itens_html .= "<tr style='border-bottom: 1px solid #ddd;'>
                            <td style='padding: 10px; color: #333;'>" . htmlspecialchars($item['produto_nome']) . "</td>
                            <td style='padding: 10px; text-align: center; color: #333;'>{$item['quantidade']}</td>
                            <td style='padding: 10px; text-align: right; color: #333;'>R$ " . number_format($item['preco_unitario'], 2, ',', '.') . "</td>
                            <td style='padding: 10px; text-align: right; color: #333; font-weight: bold;'>R$ " . number_format($item['preco_unitario'] * $item['quantidade'], 2, ',', '.') . "</td>
                       </tr>";
    }

    $endereco_html = htmlspecialchars($pedido['endereco_logradouro']) . ", " . htmlspecialchars($pedido['endereco_numero']);
    if (!empty($pedido['endereco_complemento'])) {
        $endereco_html .= " - " . htmlspecialchars($pedido['endereco_complemento']);
    }
    $endereco_html .= "<br>" . htmlspecialchars($pedido['endereco_bairro']) . " - " . htmlspecialchars($pedido['endereco_cidade']) . "/" . htmlspecialchars($pedido['endereco_estado']);
    $endereco_html .= "<br>CEP: " . htmlspecialchars($pedido['endereco_cep']);
    $endereco_html .= "<br>Destinatário: " . htmlspecialchars($pedido['endereco_destinatario']);

    $observacoes_html = "";
    if (!empty($pedido['observacoes'])) {
        $observacoes_html = "
        <div class='details-box'>
            <h3>Observações do Pedido</h3>
            <p style='color: #555; font-style: italic;'>" . nl2br(htmlspecialchars($pedido['observacoes'])) . "</p>
        </div>
        ";
    }

    $html_body = "
    <!DOCTYPE html>
    <html lang='pt-BR'>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Poppins', Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 20px auto; background-color: {$cor_fundo}; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); border: 1px solid #ddd;}
            .header { background-color: {$cor_principal}; color: #ffffff; padding: 20px 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 24px; }
            .content { padding: 30px; color: #333; line-height: 1.6; }
            .button { display: inline-block; padding: 12px 25px; background-color: {$cor_principal}; color: #ffffff !important; text-decoration: none; font-weight: 700; border-radius: 6px; margin-top: 20px; text-align: center; }
            .text-center { text-align: center; }
            .footer { padding: 20px 30px; border-top: 1px solid #ddd; text-align: center; font-size: 12px; color: #999; }
            .order-summary { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .order-summary th { background-color: #f0f0f0; padding: 10px; text-align: left; color: #555; }
            .order-summary td { padding: 10px; }
            .details-box { background-color: #ffffff; padding: 20px; border-radius: 5px; margin-top: 20px; border: 1px solid #eee; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Pagamento Aprovado!</h1>
            </div>
            <div class='content'>
                <p style='margin-bottom: 20px;'>Olá, <strong>" . htmlspecialchars($pedido['usuario_nome']) . "</strong>,</p>
                <p>Ótima notícia! O pagamento do seu pedido <strong>#{$pedido_id}</strong> foi confirmado com sucesso.</p>
                <p>Seu pedido já está em preparação para ser enviado. Você pode acompanhar o status clicando no botão abaixo:</p>

                <div class='text-center'>
                    <a href='{$link_pedido}' class='button'>Acompanhar Meu Pedido</a>
                </div>

                <div class='details-box'>
                    <h3>Resumo do Pedido</h3>
                    <p style='color: #555;'>
                        <strong>Total: R$ " . number_format($pedido['valor_total'], 2, ',', '.') . "</strong><br>
                        Subtotal: R$ " . number_format($pedido['valor_subtotal'], 2, ',', '.') . "<br>
                        Frete: R$ " . number_format($pedido['valor_frete'], 2, ',', '.') . "
                    </p>

                    <table class='order-summary'>
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th>Qtd.</th>
                                <th style='text-align: right;'>Preço Unit.</th>
                                <th style='text-align: right;'>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$itens_html}
                        </tbody>
                    </table>
                </div>

                <div class='details-box'>
                    <h3>Endereço de Entrega</h3>
                    <p style='color: #555;'>{$endereco_html}</p>
                </div>

                <div class='details-box'>
                    <h3>Detalhes da Transação</h3>
                    <p style='color: #555;'>
                        <strong>Método de Envio:</strong> " . htmlspecialchars($pedido['envio_nome']) . "
                        (Prazo: " . htmlspecialchars($pedido['envio_prazo_dias']) . " dias úteis)<br>
                        <strong>Forma de Pagamento:</strong> " . htmlspecialchars($pedido['pag_nome']) . "
                    </p>
                </div>

                {$observacoes_html}

            </div>
            <div class='footer'>
                Obrigado por comprar na {$nome_da_loja}!
            </div>
        </div>
    </body>
    </html>
    ";

    // 5. Enviar
    return enviarEmailMailgun($pedido['usuario_email'], $assunto, $html_body);
}

/**
 * ⭐ ATUALIZADO: Processa o conteúdo da página institucional, substituindo placeholders.
 */
function processarConteudoPagina($content_raw) {
    // Carrega as constantes (se ainda não foram carregadas)
    global $pdo;
    if (isset($pdo) && !defined('NOME_DA_LOJA')) {
        carregarConfigApi($pdo);
    }

    // ▼▼▼ MODIFICADO (Adicionado [CATEGORIA_DA_LOJA]) ▼▼▼
    // Define os placeholders e seus valores (AGORA TODOS VÊM DAS CONSTANTES)
    $placeholders = [
        '[NOME_DA_LOJA]',
        '[TELEFONE_CONTATO]',
        '[EMAIL_CONTATO]',
        '[HORARIO_ATENDIMENTO]',
        '[CATEGORIA_DA_LOJA]', // <--- NOVO
        '[LINK_SUPORTE]',
        '[LINK_PRAZOS]'
    ];

    $values = [
        defined('NOME_DA_LOJA') ? NOME_DA_LOJA : 'Sua Loja',
        defined('TELEFONE_CONTATO') ? TELEFONE_CONTATO : '',
        defined('EMAIL_CONTATO') ? EMAIL_CONTATO : '',
        defined('HORARIO_ATENDIMENTO') ? HORARIO_ATENDIMENTO : '',
        defined('CATEGORIA_DA_LOJA') ? CATEGORIA_DA_LOJA : 'produtos inovadores', // <--- NOVO (com fallback)
        'suporte.php', // Link para a página de suporte
        'prazos-de-entrega.php' // Link para a página de prazos
    ];
    // ▲▲▲ FIM DA MODIFICAÇÃO ▲▲▲

    // Substitui os placeholders pelo conteúdo das constantes
    return str_replace($placeholders, $values, $content_raw);
}