<?php
// Geeft een nieuwe rekenvraag met ondertekende token (wordt door de pagina met JavaScript opgehaald).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/spam.php';
try {
    $a = random_int(2, 9); $b = random_int(2, 9);
    echo json_encode(['vraag' => "Wat is $a + $b?", 'token' => maak_token($a + $b)]);
} catch (Throwable $x) {
    http_response_code(500);
    echo json_encode(['fout' => true]);
}
