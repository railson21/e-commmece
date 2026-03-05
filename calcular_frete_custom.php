<?php
// api/calcular_frete_custom.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
require_once '../config/db.php'; // Garante $pdo

$response = ['success' => false, 'opcoes' => [], 'message' => 'Erro desconhecido.'];

try {
    if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
        throw new Exception("Usuário não autenticado.");
    }

    $input_cep = $_POST['cep'] ?? '';
    if (empty($input_cep)) {
        throw new Exception("CEP não fornecido.");
    }

    $cep = preg_replace('/[^0-9]/', '', $input_cep);
    if (strlen($cep) !== 8) {
        throw new Exception("CEP inválido. Deve conter 8 dígitos.");
    }

    // --- 1. CONSULTAR API EXTERNA (ViaCEP) PARA ACHAR LOCALIDADE ---
    // Usamos 'cURL' para mais robustez e detecção de erros
    $ch = curl_init("https://viacep.com.br/ws/{$cep}/json/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout de 5 segundos
    $api_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $api_response === false) {
        throw new Exception("Não foi possível consultar o CEP na API externa (ViaCEP).");
    }

    $localidade = json_decode($api_response, true);

    if (isset($localidade['erro']) && $localidade['erro'] === true) {
        throw new Exception("CEP não encontrado ou inexistente.");
    }

    $uf = $localidade['uf'] ?? null;
    $cidade_ibge_id = $localidade['ibge'] ?? null; // ID IBGE da cidade

    if (empty($uf) || empty($cidade_ibge_id)) {
        throw new Exception("Não foi possível identificar a UF ou o Município do CEP.");
    }

    // --- 2. CONSULTAR REGRAS DE FRETE NO NOSSO BANCO DE DADOS ---
    /* * Esta query busca por regras que se aplicam em ordem de especificidade:
     * 1. Regra exata para a Cidade (UF e ID IBGE batem)
     * 2. Regra para o Estado Inteiro (UF bate, mas cidade_ibge_id é NULL)
     * 3. Regra Nacional (UF e cidade_ibge_id são NULL)
     */
    $sql = "SELECT id, nome, descricao, custo_base, prazo_estimado_dias
            FROM formas_envio
            WHERE ativo = true
            AND (
                -- 1. Regra específica para o Município
                (uf = :uf AND cidade_ibge_id = :ibge_id)
                OR
                -- 2. Regra para o Estado inteiro
                (uf = :uf AND cidade_ibge_id IS NULL)
                OR
                -- 3. Regra Nacional (Global)
                (uf IS NULL AND cidade_ibge_id IS NULL)
            )
            ORDER BY custo_base ASC"; // Sempre mostra o mais barato primeiro

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uf' => $uf,
        ':ibge_id' => $cidade_ibge_id
    ]);

    $opcoes_frete = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($opcoes_frete)) {
        throw new Exception("Nenhuma forma de envio disponível para o seu CEP. Entre em contato conosco.");
    }

    $response['success'] = true;
    $response['opcoes'] = $opcoes_frete; // Retorna as opções válidas
    $response['message'] = count($opcoes_frete) . " opções encontradas.";

} catch (PDOException $e) {
    $response['message'] = "Erro de banco de dados: " . $e->getMessage();
    error_log("Erro PDO em calcular_frete_custom: " . $e->getMessage());
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>