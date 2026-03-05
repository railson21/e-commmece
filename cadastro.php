<?php
// cadastro.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // <-- DEVE SER A PRIMEIRA LINHA
}
require_once 'config/db.php'; // Conexão com o banco
require_once 'funcoes.php'; // Inclui as funções de e-mail e utilitárias

// ⭐ CARREGA AS CONFIGURAÇÕES DA API DO DB
if (isset($pdo)) {
    carregarConfigApi($pdo);
}

// --- Funções de Validação de Formato (MANTIDAS) ---

/**
 * Valida se um nome parece real (não contém "teste" e tem pelo menos duas palavras).
 */
function validaNomeReal($nome) {
    // Remove "Sr.", "Sra.", "Dr.", etc. para a contagem
    $nomeLimpo = preg_replace('/^(Sr|Sra|Dr|Dra)\.\s/i', '', $nome);

    if (stripos($nomeLimpo, 'teste') !== false) {
        return false; // Não permite a palavra "teste"
    }
    // Permite nomes compostos (ex: "Ana-Clara"), mas conta como uma palavra
    if (count(explode(' ', $nomeLimpo)) < 2) {
        return false; // Deve ter pelo menos nome e sobrenome
    }
    return true;
}

/**
 * Valida se o número de celular (apenas dígitos) é um celular brasileiro válido.
 * (DDD válido + 9 dígitos começando com 9).
 */
function validaCelular($celular) {
    $celular = preg_replace('/[^0-9]/', '', $celular);
    // Verifica se tem 11 dígitos (DDD + 9xxxx-xxxx)
    if (strlen($celular) !== 11) {
        return false;
    }
    // Verifica se o DDD é válido (códigos de 11 a 99)
    $ddd = (int)substr($celular, 0, 2);
    if ($ddd < 11 || $ddd > 99) {
        return false;
    }
    // Verifica se o número começa com 9
    if (substr($celular, 2, 1) !== '9') {
        return false;
    }
    return true;
}


function validaCPF($cpf) {
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }
    return true;
}

/**
 * CORREÇÃO CRÍTICA: Força o resultado de preg_match a ser um booleano.
 */
function checkPasswordRequirements($senha) {
    return [
        'min_length' => strlen($senha) >= 8,
        'has_uppercase' => (bool)preg_match('/[A-Z]/', $senha),
        'has_lowercase' => (bool)preg_match('/[a-z]/', $senha),
        'has_number' => (bool)preg_match('/\d/', $senha),
        'has_special' => (bool)preg_match('/[^a-zA-Z\d\s]/', $senha),
    ];
}

/**
 * Converte data de DD/MM/AAAA para AAAA-MM-DD (formato do DB)
 */
function convertDateToDBFormat($dateString) {
    // Remove qualquer caractere que não seja número ou barra
    $dateString = trim($dateString);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateString, $matches)) {
        // $matches[1] = DD, $matches[2] = MM, $matches[3] = AAAA
        return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
    }
    return false; // Retorna falso se não estiver no formato D/M/A
}


$errors = []; // Array para guardar erros de validação
$form_data = $_POST; // Guarda os dados submetidos para repopular

// --- Processamento do Formulário ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // --- Coleta de Dados ---
    $nome = trim($_POST['nome'] ?? '');

    // Coleta a data no formato de INPUT (DD/MM/AAAA)
    $data_nascimento_input = trim($_POST['data_nascimento'] ?? '');
    // Tenta converter para o formato do DB (AAAA-MM-DD)
    $data_nascimento = convertDateToDBFormat($data_nascimento_input);

    $cpf = trim($_POST['cpf'] ?? '');
    $cpf_numeros = preg_replace('/[^0-9]/', '', $cpf);
    $tel_fixo = trim($_POST['telefone_fixo'] ?? '');
    $tel_celular = trim($_POST['telefone_celular'] ?? '');
    $tel_celular_numeros = preg_replace('/[^0-9]/', '', $tel_celular);
    $email = trim(strtolower($_POST['email'] ?? ''));
    $email_confirm = trim(strtolower($_POST['email_confirm'] ?? ''));
    $senha = $_POST['senha'] ?? '';
    $senha_confirm = $_POST['senha_confirm'] ?? '';
    $receber_promocoes = isset($_POST['receber_promocoes']);
    $aceite_termos = isset($_POST['aceite_termos']); // Coleta aceite dos termos

    // --- 1. Validação Inicial (Campos, Formato, Idade, Senha e Termos) ---
    // ... (Validação de Nome, Data, CPF, Celular, E-mail, Senha) ...

    // 1. Nome
    if (empty($nome)) {
        $errors['nome'] = "Nome Completo é obrigatório.";
    } elseif (!validaNomeReal($nome)) {
        $errors['nome'] = "Por favor, insira um nome real (nome e sobrenome, sem a palavra 'teste').";
    }

    // 2. Data Nasc. e Idade
    if (empty($data_nascimento_input)) {
        $errors['data_nascimento'] = "Data de Nascimento é obrigatória.";
    } elseif ($data_nascimento === false) {
         $errors['data_nascimento'] = "Data de Nascimento inválida. Use o formato DD/MM/AAAA.";
    } elseif (!strtotime($data_nascimento)) {
         $errors['data_nascimento'] = "Data inválida ou impossível (ex: 30/02/2000).";
    } else {
        // Validação de idade mínima (18 anos)
        $data_nascimento_obj = new DateTime($data_nascimento);
        $hoje = new DateTime();
        $idade = $data_nascimento_obj->diff($hoje)->y;

        if ($idade < 18) {
             $errors['data_nascimento'] = "É necessário ter no mínimo 18 anos para se cadastrar.";
        }
    }

    // 3. CPF
    if (empty($cpf)) {
        $errors['cpf'] = "CPF é obrigatório.";
    } elseif (strlen($cpf_numeros) !== 11) {
        $errors['cpf'] = "O CPF deve ter 11 dígitos.";
    } elseif (!validaCPF($cpf_numeros)) {
        $errors['cpf'] = "CPF inválido.";
    }

    // 4. Celular (Obrigatório)
    if (empty($tel_celular)) {
        $errors['telefone_celular'] = "O Telefone Celular é obrigatório.";
    } elseif (!validaCelular($tel_celular_numeros)) {
          $errors['telefone_celular'] = "Telefone Celular inválido. Use o formato (DD) 9XXXX-XXXX.";
    }

    // 5. E-mail
    if (empty($email)) {
        $errors['email'] = "E-mail é obrigatório.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Formato de e-mail inválido.";
    }

    if (empty($email_confirm)) {
        $errors['email_confirm'] = "Confirmação de e-mail é obrigatória.";
    } elseif ($email !== $email_confirm) {
        $errors['email_match'] = "Os e-mails não coincidem.";
    }

    // 6. Senha
    $password_checks = checkPasswordRequirements($senha);
    if (empty($senha)) {
        $errors['senha'] = "Senha é obrigatória.";
    } elseif (in_array(false, $password_checks, true)) {
        $errors['senha'] = "A senha não atende a todos os requisitos de segurança.";
    }
    if (empty($senha_confirm)) {
        $errors['senha_confirm'] = "Confirmação de senha é obrigatória.";
    } elseif ($senha !== $senha_confirm) {
        $errors['senha_match'] = "As senhas não coincidem.";
    }

    // 7. Termos e Condições (Obrigatório)
    if (!$aceite_termos) {
        $errors['aceite_termos'] = "Você deve aceitar os Termos e Condições para criar a conta.";
    }

    // --- 2. Validação de Unicidade no Banco (Só roda se 1 estiver OK) ---
    if (empty($errors)) {

        // Checagem de E-mail
        try {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $errors['email_exists'] = "Este e-mail já está cadastrado.";
            }
        } catch (PDOException $e) { $errors['db'] = "Erro ao verificar e-mail: " . $e->getMessage(); }

        // Checagem de CPF
        if (empty($errors['email_exists']) && empty($errors['db'])) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cpf = :cpf LIMIT 1");
                $stmt->execute(['cpf' => $cpf_numeros]);
                if ($stmt->fetch()) {
                    $errors['cpf_exists'] = "Este CPF já está cadastrado.";
                }
            } catch (PDOException $e) { $errors['db'] = "Erro ao verificar CPF: " . $e->getMessage(); }
        }
    }


    // --- 3. Inserção no banco (Só se 1 e 2 estiverem OK) ---
    if (empty($errors)) {
        $hashed_password = password_hash($senha, PASSWORD_DEFAULT);
        $tipo_usuario = 'cliente';

        try {
            $sql = "INSERT INTO usuarios (nome, email, senha, tipo, data_nascimento, cpf, telefone_fixo, telefone_celular, receber_promocoes, criado_em, is_bloqueado)
                    VALUES (:nome, :email, :senha, :tipo, :data_nascimento, :cpf, :tel_fixo, :tel_celular, :receber_promocoes, NOW(), false)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nome' => $nome,
                ':email' => $email,
                ':senha' => $hashed_password,
                ':tipo' => $tipo_usuario,
                ':data_nascimento' => !empty($data_nascimento) ? $data_nascimento : null, // Usa $data_nascimento (AAAA-MM-DD)
                ':cpf' => $cpf_numeros,
                ':tel_fixo' => !empty($tel_fixo) ? $tel_fixo : null,
                ':tel_celular' => $tel_celular_numeros, // Salva o celular limpo
                ':receber_promocoes' => $receber_promocoes
            ]);

            // ⭐ NOVO: Enviar E-mail de Boas-Vindas
            if (defined('MAILGUN_API_KEY') && !empty(MAILGUN_API_KEY)) {
                $email_enviado = enviarEmailBoasVindas($email, $nome);

                if (!$email_enviado) {
                     error_log("ERRO: Falha ao enviar email de boas-vindas para: $email. Verifique as configurações da Mailgun.");
                }
            } else {
                 error_log("ALERTA: E-mail de boas-vindas não enviado. Configurações da Mailgun não estão completas no config_api.");
            }


            $_SESSION['success_message'] = "Cadastro realizado com sucesso! Faça seu login.";
            header("Location: login.php");
            exit;

        } catch (PDOException $e) {
             // Tratamento de erro de DB
             if ($e->getCode() == '23505') {
                 if (strpos($e->getMessage(), 'usuarios_email_key') !== false) {
                     $errors['email_exists'] = "Este e-mail já está cadastrado (erro DB).";
                 } elseif (strpos($e->getMessage(), 'usuarios_cpf_key') !== false) {
                     $errors['cpf_exists'] = "Este CPF já está cadastrado (erro DB).";
                 } else {
                     $errors['db'] = "Erro ao cadastrar (dado duplicado): Verifique E-mail ou CPF.";
                 }
             } else {
                 $errors['db'] = "Erro ao realizar cadastro. Tente novamente. Detalhe: " . $e->getMessage();
             }
        }
    }
}

// Verifica o status inicial da senha para repopular os requisitos visuais
$password_checks_initial = checkPasswordRequirements($_POST['senha'] ?? '');

// --- BUSCAR CONFIGURAÇÃO DE CORES DO SITE ---
$cor_acento_hover = '#8cc600'; // Valor padrão de fallback

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
    error_log("Erro ao buscar cor do hover no DB em cadastro.php: " . $e->getMessage());
    // Mantém o valor padrão em caso de erro no DB
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Cliente</title>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">

    <style>
        /* ==========================================================
           CSS ESPECÍFICO DA PÁGINA DE CADASTRO
           ========================================================== */

        /* Variáveis CSS para Cores */
        :root {
            --green-accent: #a4d32a; /* Exemplo de cor principal */
            --text-color-dark: #333;
            --text-color-medium: #666;
            --text-color-light: #999;
            --border-color-light: #eee;
            --border-color-medium: #ccc;
            --error-color: #ff4444;
            --success-color: #5cb85c;

            /* Variável da cor de hover vinda do DB */
            --cor-acento-hover: <?php echo $cor_acento_hover; ?>;
        }

        body.cadastro-page { background-color: #f9f9f9; }
        .cadastro-content { flex-grow: 1; padding: 40px 0; background-color: #fff; }
        .cadastro-wrapper { max-width: 700px; margin: 0 auto; background-color: #fff; padding: 30px 40px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border: 1px solid var(--border-color-light); }
        .tipo-pessoa-tabs { display: flex; justify-content: center; margin-bottom: 30px; }
        .tipo-pessoa-tabs button { padding: 10px 20px; border: 1px solid var(--border-color-medium); background-color: #eee; color: var(--text-color-medium); cursor: pointer; font-size: 1em; margin: 0; border-radius: 20px; transition: background-color 0.2s ease, color 0.2s ease; }
        .tipo-pessoa-tabs button.active { background-color: #555; color: #fff; border-color: #555; }
        .tipo-pessoa-tabs button:not(.active):hover { background-color: #ddd; }
        .tipo-pessoa-tabs button:disabled { cursor: not-allowed; opacity: 0.6; }
        .cadastro-wrapper h2 { font-size: 1.4em; color: var(--text-color-dark); margin-bottom: 10px; text-align: left; font-weight: bold; }
        .obrigatorio-info { font-size: 0.8em; color: var(--text-color-light); margin-bottom: 25px; text-align: left; }
        .obrigatorio-info span { color: var(--error-color); }
        .form-group { margin-bottom: 18px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: bold; font-size: 0.9em; color: var(--text-color-dark); }
        .form-group label span { color: var(--error-color); }
        .form-group input[type="text"], .form-group input[type="date"], .form-group input[type="email"], .form-group input[type="password"], .form-group input[type="tel"] { width: 100%; padding: 10px 12px; border: 1px solid var(--border-color-medium); border-radius: 4px; box-sizing: border-box; font-size: 1em; }
        .form-group input:focus { border-color: var(--green-accent); box-shadow: 0 0 0 2px rgba(154, 215, 0, 0.2); outline: none; }
        .form-group .hint { font-size: 0.8em; color: #888; margin-top: 4px; }

        /* Ajustes para os checkboxes */
        .form-group-checkbox { display: flex; align-items: center; margin: 15px 0 10px 0; }
        .terms-checkbox { margin-top: 5px; margin-bottom: 25px; align-items: flex-start; }
        .terms-checkbox label { font-size: 0.9em; font-weight: normal; margin-bottom: 0; color: var(--text-color-medium); }
        .terms-checkbox label a { color: var(--green-accent); text-decoration: underline; font-weight: bold; }

        .form-group-checkbox input[type="checkbox"] { margin-right: 10px; width: auto; height: auto; accent-color: var(--green-accent); flex-shrink: 0; margin-top: 3px; }
        .form-group-checkbox label { margin-bottom: 0; font-weight: normal; color: var(--text-color-medium); font-size: 0.9em; }
        .form-group-checkbox label span { color: var(--error-color); }

        .submit-button-wrapper { text-align: right; margin-top: 30px; }
        .btn-avancar {
            padding: 12px 30px;
            background-color: var(--green-accent);
            color: #fff;
            border: none;
            border-radius: 25px;
            font-size: 1em;
            font-weight: bold;
            transition: background-color 0.2s ease;
        }

        .btn-avancar:hover { background-color: var(--cor-acento-hover); }

        .error-message { color: var(--error-color); font-size: 0.8em; margin-top: 4px; display: block; }
        input.error-border { border-color: var(--error-color) !important; }
        .general-error { color: var(--error-color); font-weight: bold; text-align: center; margin-bottom: 15px; }


        /* Otimizado para input type="text" */
        .form-group input[type="text"].date-mask,
        .form-group input[type="date"] {
            position: relative;
            background-color: #fff;
            color: var(--text-color-dark);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: none !important;
            padding-right: 12px;
        }


        /* --- Estilo "Mostrar Senha" --- */
        .password-toggle-icon {
            position: absolute;
            top: 38px;
            right: 12px;
            cursor: pointer;
            color: var(--text-color-light);
            width: 20px;
            height: 20px;
            z-index: 2;
        }

        .form-group input[type="password"],
        .form-group input[type="text"].password-field {
            padding-right: 40px;
        }
        /* --- FIM CORREÇÃO --- */


        .password-requirements { list-style: none; padding: 10px; margin: 10px 0 0 0; font-size: 0.85em; text-align: left; background-color: #f9f9f9; border: 1px solid var(--border-color-light); border-radius: 4px; display: none; grid-template-columns: 1fr 1fr; gap: 5px 15px; }
        .password-requirements li { margin-bottom: 5px; display: flex; align-items: center; transition: color 0.2s ease; }
          .password-requirements li:last-child { margin-bottom: 0; }
        .validation-icon { width: 16px; height: 16px; margin-right: 8px; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; color: white; flex-shrink: 0; font-size: 11px; line-height: 16px; }
        .status-invalid { color: var(--error-color); }
        .status-invalid .validation-icon { background-color: var(--error-color); content: '×'; }
        .status-valid { color: var(--success-color); text-decoration: line-through; }
        .status-valid .validation-icon { background-color: var(--success-color); content: '✓'; }
        .status-default { color: #888; }
        .status-default .validation-icon { background-color: #aaa; }

        @media (max-width: 768px) {
            .cadastro-wrapper { padding: 20px; margin-left: 10px; margin-right: 10px; }
            .tipo-pessoa-tabs button { padding: 8px 15px; font-size: 0.9em; }
            .password-requirements { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="cadastro-page">

    <div class="sticky-header-wrapper">
        <?php include 'templates/header.php'; ?>
    </div>

    <main class="cadastro-content">
        <div class="cadastro-wrapper">

             <div class="tipo-pessoa-tabs">
                 <button type="button" class="active" id="btn-pf">Pessoa Física</button>
             </div>

             <form action="cadastro.php" method="POST" id="form-pf" novalidate>
                 <h2>Dados Pessoais</h2>
                 <p class="obrigatorio-info">Campos marcados com <span>*</span> são de preenchimento obrigatório</p>

                 <?php if(isset($errors['db'])): ?><p class="general-error"><?php echo $errors['db']; ?></p><?php endif; ?>

                 <div class="form-group">
                     <label for="nome"><span>*</span>Nome Completo:</label>
                     <input type="text" id="nome" name="nome" value="<?php echo htmlspecialchars($form_data['nome'] ?? ''); ?>" required class="<?php echo isset($errors['nome']) ? 'error-border' : ''; ?>">
                     <?php if(isset($errors['nome'])): ?><span class="error-message"><?php echo $errors['nome']; ?></span><?php endif; ?>
                 </div>

                 <div class="form-group">
                     <label for="data_nascimento"><span>*</span>Data Nascimento:</label>
                     <input type="text" id="data_nascimento" name="data_nascimento" placeholder="DD/MM/AAAA" value="<?php echo htmlspecialchars($form_data['data_nascimento'] ?? ''); ?>" required class="date-mask <?php echo isset($errors['data_nascimento']) ? 'error-border' : ''; ?>">
                     <?php if(isset($errors['data_nascimento'])): ?><span class="error-message"><?php echo $errors['data_nascimento']; ?></span><?php endif; ?>
                 </div>

                 <div class="form-group">
                     <label for="cpf"><span>*</span>CPF:</label>
                     <input type="text" id="cpf" name="cpf" placeholder="000.000.000-00" value="<?php echo htmlspecialchars($form_data['cpf'] ?? ''); ?>" required class="<?php echo (isset($errors['cpf']) || isset($errors['cpf_exists'])) ? 'error-border' : ''; ?>">
                     <?php if(isset($errors['cpf'])): ?><span class="error-message"><?php echo $errors['cpf']; ?></span><?php endif; ?>
                     <?php if(isset($errors['cpf_exists'])): ?><span class="error-message"><?php echo $errors['cpf_exists']; ?></span><?php endif; ?>
                 </div>

                 <p class="telefone-info">Telefones para contato</p>
                 <?php if(isset($errors['telefone_celular'])): ?><span class="error-message" style="display:block; text-align:left; margin-bottom: 10px;"><?php echo $errors['telefone_celular']; ?></span><?php endif; ?>

                 <div class="form-group">
                     <label for="telefone_celular"><span>*</span>Telefone Celular:</label>
                     <input type="tel" id="telefone_celular" name="telefone_celular" placeholder="(##) 9####-####" value="<?php echo htmlspecialchars($form_data['telefone_celular'] ?? ''); ?>" required class="<?php echo isset($errors['telefone_celular']) ? 'error-border' : ''; ?>">
                     <span class="hint">Usado para atualizações do pedido.</span>
                 </div>

                 <div class="form-group">
                     <label for="telefone_fixo">Telefone Fixo ou Comercial: (Opcional)</label>
                     <input type="tel" id="telefone_fixo" name="telefone_fixo" placeholder="(##) ####-####" value="<?php echo htmlspecialchars($form_data['telefone_fixo'] ?? ''); ?>">
                 </div>


                 <div class="form-group">
                     <label for="email"><span>*</span>E-mail:</label>
                     <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required class="<?php echo (isset($errors['email']) || isset($errors['email_exists'])) ? 'error-border' : ''; ?>">
                      <?php if(isset($errors['email'])): ?><span class="error-message"><?php echo $errors['email']; ?></span><?php endif; ?>
                        <?php if(isset($errors['email_exists'])): ?><span class="error-message"><?php echo $errors['email_exists']; ?></span><?php endif; ?>
                 </div>

                 <div class="form-group">
                     <label for="email_confirm"><span>*</span>Digite novamente:</label>
                     <input type="email" id="email_confirm" name="email_confirm" value="<?php echo htmlspecialchars($form_data['email_confirm'] ?? ''); ?>" required class="<?php echo (isset($errors['email_confirm']) || isset($errors['email_match'])) ? 'error-border' : ''; ?>">
                      <?php if(isset($errors['email_confirm'])): ?><span class="error-message"><?php echo $errors['email_confirm']; ?></span><?php endif; ?>
                      <?php if(isset($errors['email_match'])): ?><span class="error-message"><?php echo $errors['email_match']; ?></span><?php endif; ?>
                 </div>

                 <div class="form-group">
                     <label for="senha"><span>*</span>Crie sua Senha de acesso:</label>
                     <input type="password" id="senha" name="senha" required class="password-field <?php echo isset($errors['senha']) ? 'error-border' : ''; ?>">
                     <span class="password-toggle-icon" data-target="senha">
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                             <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                          </svg>
                     </span>
                     <?php if(isset($errors['senha'])): ?><span class="error-message"><?php echo $errors['senha']; ?></span><?php endif; ?>

                     <ul class="password-requirements" id="password-feedback">
                          <li id="req-length" class="<?php echo $password_checks_initial['min_length'] ? 'status-valid' : 'status-invalid'; ?>">
                              <span class="validation-icon"><?php echo $password_checks_initial['min_length'] ? '✓' : '×'; ?></span> Mínimo de 8 caracteres
                          </li>
                          <li id="req-upper" class="<?php echo $password_checks_initial['has_uppercase'] ? 'status-valid' : 'status-invalid'; ?>">
                              <span class="validation-icon"><?php echo $password_checks_initial['has_uppercase'] ? '✓' : '×'; ?></span> Pelo menos 1 letra maiúscula (A-Z)
                          </li>
                          <li id="req-lower" class="<?php echo $password_checks_initial['has_lowercase'] ? 'status-valid' : 'status-invalid'; ?>">
                              <span class="validation-icon"><?php echo $password_checks_initial['has_lowercase'] ? '✓' : '×'; ?></span> Pelo menos 1 letra minúscula (a-z)
                          </li>
                          <li id="req-number" class="<?php echo $password_checks_initial['has_number'] ? 'status-valid' : 'status-invalid'; ?>">
                              <span class="validation-icon"><?php echo $password_checks_initial['has_number'] ? '✓' : '×'; ?></span> Pelo menos 1 número (0-9)
                          </li>
                          <li id="req-special" class="<?php echo $password_checks_initial['has_special'] ? 'status-valid' : 'status-invalid'; ?>">
                              <span class="validation-icon"><?php echo $password_checks_initial['has_special'] ? '✓' : '×'; ?></span> Pelo menos 1 caracter especial
                          </li>
                     </ul>
                 </div>

                 <div class="form-group">
                     <label for="senha_confirm"><span>*</span>Digite novamente:</label>
                     <input type="password" id="senha_confirm" name="senha_confirm" required class="password-field <?php echo (isset($errors['senha_confirm']) || isset($errors['senha_match'])) ? 'error-border' : ''; ?>">
                     <span class="password-toggle-icon" data-target="senha_confirm">
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                             <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                          </svg>
                     </span>
                     <?php if(isset($errors['senha_confirm'])): ?><span class="error-message"><?php echo $errors['senha_confirm']; ?></span><?php endif; ?>
                     <?php if(isset($errors['senha_match'])): ?><span class="error-message"><?php echo $errors['senha_match']; ?></span><?php endif; ?>
                 </div>

                 <div class="form-group-checkbox">
                     <input type="checkbox" id="receber_promocoes" name="receber_promocoes" value="1" <?php echo (isset($form_data['receber_promocoes']) || !$_POST) ? 'checked' : ''; ?>>
                     <label for="receber_promocoes">Desejo receber e-mails promocionais</label>
                 </div>

                 <div class="form-group-checkbox terms-checkbox">
                     <input type="checkbox" id="aceite_termos" name="aceite_termos" value="1"
                            <?php echo (isset($form_data['aceite_termos'])) ? 'checked' : ''; ?>
                            required>
                     <label for="aceite_termos">
                         <span>*</span> Li e aceito os <a href="politica-de-privacidade.php" target="_blank">Termos e Condições e Política de Privacidade</a>
                     </label>
                     <?php if(isset($errors['aceite_termos'])): ?><span class="error-message" style="display:block; margin-top:5px;"><?php echo $errors['aceite_termos']; ?></span><?php endif; ?>
                 </div>


                 <div class="submit-button-wrapper">
                      <button type="submit" class="btn-avancar">CRIAR</button>
                 </div>
             </form>

        </div>
    </main>

    <?php include 'templates/footer.php'; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>

    <?php include 'templates/scripts.php'; ?>

    <script>
        // Função JS para verificar os requisitos da senha
        function checkPasswordRequirementsJS(password) {
            return {
                min_length: password.length >= 8,
                has_uppercase: /[A-Z]/.test(password),
                has_lowercase: /[a-z]/.test(password),
                has_number: /\d/.test(password),
                has_special: /[^a-zA-Z\d\s]/.test(password),
            };
        }

        // Função para ATUALIZAR o feedback visual
        function updatePasswordFeedback() {
            const passwordInput = document.getElementById('senha');
            const password = passwordInput.value;
            const checks = checkPasswordRequirementsJS(password);

            const requirements = [
                { id: 'req-length', check: checks.min_length },
                { id: 'req-upper', check: checks.has_uppercase },
                { id: 'req-lower', check: checks.has_lowercase },
                { id: 'req-number', check: checks.has_number },
                { id: 'req-special', check: checks.has_special }
            ];

            const isTyping = password.length > 0;
            const feedbackList = document.getElementById('password-feedback');

            // Se o PHP indicou um erro de senha, mostra a caixa imediatamente
            <?php if (isset($errors['senha'])): ?>
                if (feedbackList) feedbackList.style.display = 'grid';
            <?php endif; ?>

            requirements.forEach(req => {
                const li = document.getElementById(req.id);
                if (li) {
                    const icon = li.querySelector('.validation-icon');
                    li.classList.remove('status-default', 'status-valid', 'status-invalid');

                    if (!isTyping && !<?php echo isset($errors['senha']) ? 'true' : 'false'; ?>) {
                        li.classList.add('status-default');
                        icon.textContent = '•'; // Ícone padrão neutro
                    } else if (req.check) {
                        li.classList.add('status-valid');
                        icon.textContent = '✓';
                    } else {
                        li.classList.add('status-invalid');
                        icon.textContent = '×';
                    }
                }
            });

            // Esconde a mensagem de erro PHP ao começar a digitar
            const errorMsg = passwordInput.parentNode.querySelector('.error-message');
            if (errorMsg && isTyping) {
                errorMsg.style.display = 'none';
                passwordInput.classList.remove('error-border');
            }
        }


        // --- SCRIPT CENTRAL DOMContentLoaded (Específico da Página) ---
        document.addEventListener('DOMContentLoaded', function() {

            // --- LÓGICA DE VALIDAÇÃO DE SENHA EM TEMPO REAL ---
            const passwordInput = document.getElementById('senha');
            const requisitosDiv = document.getElementById('password-feedback');
            if (passwordInput && requisitosDiv) {
                // Aplica o feedback inicial (se houver dados de POST com erro)
                <?php if (!empty($errors['senha']) || !empty($_POST['senha'])): ?>
                    requisitosDiv.style.display = 'grid';
                    updatePasswordFeedback();
                <?php endif; ?>

                passwordInput.addEventListener('focus', () => {
                    requisitosDiv.style.display = 'grid';
                    updatePasswordFeedback();
                });
                passwordInput.addEventListener('input', updatePasswordFeedback);
                passwordInput.addEventListener('blur', () => {
                    <?php if (empty($errors['senha'])): // Só esconde se não houver erro do PHP ?>
                        if (passwordInput.value.length === 0) {
                            requisitosDiv.style.display = 'none';
                        }
                    <?php endif; ?>
                });
            }

            // --- LÓGICA DO "OLHO" (MOSTRAR/ESCONDER SENHA) ---
            const iconEyeVisible = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>`;

            const iconEyeHidden = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
            </svg>`;

            const passwordToggles = document.querySelectorAll('.password-toggle-icon');
            passwordToggles.forEach(toggle => {
                toggle.addEventListener('click', () => {
                    const targetId = toggle.getAttribute('data-target');
                    const targetInput = document.getElementById(targetId);

                    if (targetInput && targetInput.type === 'password') {
                        targetInput.type = 'text';
                        toggle.innerHTML = iconEyeVisible;
                    } else if (targetInput) {
                        targetInput.type = 'password';
                        toggle.innerHTML = iconEyeHidden;
                    }
                });
            });

        }); // Fim do DOMContentLoaded

        // --- Aplicação das Máscaras (JQuery Mask) ---
        $(document).ready(function(){
            $('#cpf').mask('000.000.000-00');
            $('#telefone_fixo').mask('(00) 0000-0000');
            // Máscara de data
            $('#data_nascimento').mask('00/00/0000');


             var maskBehavior = function (val) {
               return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
             },
             options = {
               onKeyPress: function(val, e, field, options) {
                   field.mask(maskBehavior.apply({}, arguments), options);
                 }
             };
             // Aplica a máscara ao celular
             $('#telefone_celular').mask(maskBehavior, options);
        });
    </script>

</body>
</html>