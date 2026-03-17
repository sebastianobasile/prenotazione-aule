<?php
session_start();

$file_settings = __DIR__ . '/impostazioni.json';
$file_db       = __DIR__ . '/prenotazioni.json';
$file_log      = __DIR__ . '/log_azioni.txt';

// ─────────────────────────────────────────────
// IMPOSTAZIONI
// ─────────────────────────────────────────────

function caricaImpostazioni(): array {
    global $file_settings;
    if (!file_exists($file_settings)) {
        return [
            "password_valida"    => password_hash("Scuola2026", PASSWORD_DEFAULT),
            "password_admin"     => password_hash("Admin2026",  PASSWORD_DEFAULT),
            "email_responsabili" => "",
            "visibilita_pubblica"=> false,
            "orari_aule"         => []
        ];
    }
    return json_decode(file_get_contents($file_settings), true) ?? [];
}

function salvaImpostazioni(array $data): void {
    global $file_settings;
    $fp = fopen($file_settings, 'c');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

$settings            = caricaImpostazioni();
$password_valida     = $settings['password_valida'];
$password_admin      = $settings['password_admin'];
$email_responsabili  = $settings['email_responsabili'];
$orari_aule          = $settings['orari_aule'];

// ─────────────────────────────────────────────
// PRENOTAZIONI
// ─────────────────────────────────────────────

function caricaDati(): array {
    global $file_db;
    if (!file_exists($file_db)) return [];
    return json_decode(file_get_contents($file_db), true) ?: [];
}

function salvaDati(array $lista): void {
    global $file_db;
    $fp = fopen($file_db, 'c');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($lista, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

// ─────────────────────────────────────────────
// AUTENTICAZIONE
// ─────────────────────────────────────────────

function verificaLoggato(): void {
    if (!isset($_SESSION['loggato'])) {
        header("Location: login.php");
        exit;
    }
}

// ─────────────────────────────────────────────
// CSRF
// ─────────────────────────────────────────────

function generaCSRF(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verificaCSRF(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Richiesta non valida (token CSRF mancante o errato).');
    }
}

// ─────────────────────────────────────────────
// LOG AZIONI
// ─────────────────────────────────────────────

function logAzione(string $azione, array $dati = []): void {
    global $file_log;
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'n/d';
    $riga = date('d/m/Y H:i:s') . " | " . $ip . " | " . $azione
          . " | " . json_encode($dati, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($file_log, $riga, FILE_APPEND | LOCK_EX);
}

// ─────────────────────────────────────────────
// UTILITÀ
// ─────────────────────────────────────────────

function dataIta(string $data): string {
    return date("d/m/Y", strtotime($data));
}

function inviaNotificaEmail(array $p, string $azione = "NUOVA"): void {
    $settings     = caricaImpostazioni();
    $destinatari  = $settings['email_responsabili'] ?? '';
    if (empty($destinatari)) return;

    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $mittente = "noreply@{$host}";
    $oggetto  = "[{$azione} PRENOTAZIONE] " . ($p['aula'] ?? '') . " - " . ($p['docente'] ?? '');

    $corpo  = "Dettagli operazione:\n\n";
    $corpo .= "Docente: " . ($p['docente'] ?? '') . "\n";
    $corpo .= "Aula: "    . ($p['aula']    ?? '') . "\n";
    $corpo .= "Data: "    . (isset($p['giorno']) ? date('d/m/Y', strtotime($p['giorno'])) : '') . "\n";
    $corpo .= "Orario: "  . ($p['ora_inizio'] ?? '') . " - " . ($p['ora_fine'] ?? '') . "\n";
    $corpo .= "Note: "    . ($p['note'] ?: 'Nessuna') . "\n\n";
    $corpo .= "Link: https://" . $host . dirname($_SERVER['PHP_SELF'] ?? '/');

    $headers = implode("\r\n", [
        "From: {$mittente}",
        "Reply-To: {$destinatari}",
        "X-Mailer: PHP/" . phpversion(),
        "Content-Type: text/plain; charset=UTF-8"
    ]);

    @mail($destinatari, $oggetto, $corpo, $headers);
}
