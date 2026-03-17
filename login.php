<?php
require_once 'config.php';

$errore   = "";
$settings = caricaImpostazioni();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Protezione brute force ──────────────────────────────
    $_SESSION['login_attempts'] ??= 0;
    $_SESSION['login_last']     ??= 0;

    $max_tentativi = 5;
    $blocco_sec    = 300; // 5 minuti

    if ($_SESSION['login_attempts'] >= $max_tentativi) {
        $attesa = $blocco_sec - (time() - $_SESSION['login_last']);
        if ($attesa > 0) {
            $min = ceil($attesa / 60);
            $errore = "⛔ Troppi tentativi errati. Riprova tra {$min} minuto/i.";
            goto mostra_form;
        }
        // Blocco scaduto: reset contatore
        $_SESSION['login_attempts'] = 0;
    }
    // ────────────────────────────────────────────────────────

    $pass_inserita    = $_POST['password'] ?? '';
    $password_docente = $settings['password_valida'] ?? '';
    $password_admin   = $settings['password_admin']  ?? '';

    // Supporto sia password in chiaro (vecchie) che hashate (nuove)
    $match_admin   = password_verify($pass_inserita, $password_admin)
                     || $pass_inserita === $password_admin;
    $match_docente = password_verify($pass_inserita, $password_docente)
                     || $pass_inserita === $password_docente;

    if ($match_admin && !empty($password_admin)) {
        $_SESSION['loggato']        = true;
        $_SESSION['is_admin']       = true;
        $_SESSION['login_attempts'] = 0;
        header("Location: index.php");
        exit;
    } elseif ($match_docente && !empty($password_docente)) {
        $_SESSION['loggato']        = true;
        $_SESSION['is_admin']       = false;
        $_SESSION['login_attempts'] = 0;
        header("Location: index.php");
        exit;
    } else {
        $_SESSION['login_attempts']++;
        $_SESSION['login_last'] = time();
        $rimasti = $max_tentativi - $_SESSION['login_attempts'];
        $errore  = "❌ Password errata!" . ($rimasti > 0 ? " ({$rimasti} tentativ" . ($rimasti === 1 ? "o" : "i") . " rimanent" . ($rimasti === 1 ? "e" : "i") . ")" : "");
    }
}

mostra_form:
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Login - Prenotazione Aule</title>
    <link rel="stylesheet" href="style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: sans-serif; background: #f4f7f6; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-box { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 350px; text-align: center; }
        .pass-container { position: relative; width: 100%; margin: 15px 0; }
        input[type="password"], input[type="text"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .toggle-pass { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; font-size: 18px; color: #777; }
        button { background: #2c5aa0; color: white; border: none; padding: 12px; width: 100%; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .errore { color: red; margin-bottom: 15px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Accesso Sistema</h2>
        <p>Inserisci la password</p>

        <?php if ($errore): ?>
            <div class="errore"><?= htmlspecialchars($errore) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= generaCSRF() ?>">
            <div class="pass-container">
                <input type="password" name="password" id="password" placeholder="Password" required autofocus>
                <span class="toggle-pass" onclick="toggleVisibility()">👁️</span>
            </div>
            <button type="submit">Accedi</button>
        </form>
    </div>

    <script>
    function toggleVisibility() {
        const passInput = document.getElementById('password');
        const icon = document.querySelector('.toggle-pass');
        if (passInput.type === "password") {
            passInput.type = "text";
            icon.innerText = "🙈";
        } else {
            passInput.type = "password";
            icon.innerText = "👁️";
        }
    }
    </script>
</body>
</html>
