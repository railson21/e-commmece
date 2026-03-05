<?php
// api/upload_review_image.php
header('Content-Type: application/json');

// Definições Básicas
$target_dir = "../uploads/reviews/"; // Relativo à pasta API
$base_url_path = "/uploads/reviews/"; // Caminho que será salvo no DB

// Resposta padrão
$response = [
    'status' => 'error',
    'message' => 'Nenhum arquivo recebido.',
    'url' => ''
];

if (!isset($_FILES['pasted_image'])) {
    echo json_encode($response);
    exit;
}

// Cria o diretório se não existir
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0755, true);
}

$file = $_FILES['pasted_image'];

if ($file['error'] !== 0) {
    $response['message'] = 'Erro no upload do arquivo (Código: ' . $file['error'] . ')';
    echo json_encode($response);
    exit;
}

// Validação (Simples)
$imageFileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($imageFileType != 'png') { // Prints geralmente são PNG
     $imageFileType = 'png'; // Força PNG se a extensão estiver faltando
}

// Renomeia o arquivo
$new_filename = uniqid('paste_', true) . '.' . $imageFileType;
$target_path_server = $target_dir . $new_filename;
$target_path_db = $base_url_path . $new_filename;

if (move_uploaded_file($file["tmp_name"], $target_path_server)) {
    $response['status'] = 'success';
    $response['message'] = 'Imagem colada enviada com sucesso.';
    $response['url'] = $target_path_db;
} else {
    $response['message'] = 'Erro ao salvar o arquivo no servidor.';
}

echo json_encode($response);
exit;
?>