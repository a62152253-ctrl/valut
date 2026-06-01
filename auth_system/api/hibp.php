<?php
// HIBP k-anonymity proxy — avoids CORS issues calling pwnedpasswords.com from browser
session_start();
include '../includes/vault_auth.php';
vaultRequireAuth();
vaultRateLimit('hibp_' . (int)$_SESSION['user_id'], 30, 60);

$prefix = strtoupper(preg_replace('/[^a-f0-9]/i', '', $_GET['prefix'] ?? ''));
if (strlen($prefix) !== 5) vaultJson(['error' => 'Bad prefix'], 400);

$ctx = stream_context_create(['http' => [
    'timeout' => 5,
    'header'  => "User-Agent: VaultAuth-PHP\r\nAdd-Padding: true\r\n"
]]);
$url  = "https://api.pwnedpasswords.com/range/{$prefix}";
$body = @file_get_contents($url, false, $ctx);
if ($body === false) vaultJson(['error' => 'HIBP unreachable'], 502);

header('Content-Type: text/plain');
echo $body;
exit();
