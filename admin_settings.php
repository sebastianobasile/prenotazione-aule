<?php
require_once 'config.php';
verificaLoggato();

if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    $_SESSION['admin_access_granted'] = true;
}

$mostra_config   = false;
$errore          = "";
$data_cambio_pass = $settings['data_cambio_pass'] ?? 'Data non disponibile';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_pass'])) {
    $pass = $_POST['unlock_pass'];
    $match = password_verify($pass, $password_admin) || $pass === $password_admin;
    if ($match) {
        $_SESSION['admin_access_granted'] = true;
    } else {
        $errore = "❌ Password SuperAdmin errata!";
    }
}

if (isset($_SESSION['admin_access_granted']) && $_SESSION['admin_access_granted'] === true) {
    $mostra_config = true;
}

if ($mostra_config && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione_salva'])) {
    verificaCSRF();

    $nuove_aule = [];
    if (isset($_POST['aula_nome'])) {
        foreach ($_POST['aula_nome'] as $i => $nome) {
            if (!empty($nome)) {
                $nuove_aule[$nome] = [
                    "colore"      => $_POST['aula_colore'][$i],
                    "inizio"      => $_POST['aula_inizio'][$i],
                    "fine"        => $_POST['aula_fine'][$i],
                    "data_inizio" => $_POST['aula_data_inizio'][$i],
                    "data_fine"   => $_POST['aula_data_fine'][$i]
                ];
            }
        }
    }

    // Aggiorna password SOLO se il campo non è vuoto
    $nuova_pass_valida = trim($_POST['password_valida'] ?? '');
    $nuova_pass_admin  = trim($_POST['password_admin']  ?? '');

    if (!empty($nuova_pass_valida)) {
        $pass_valida_hash = (password_verify($nuova_pass_valida, $password_valida) || $nuova_pass_valida === $password_valida)
            ? $password_valida
            : password_hash($nuova_pass_valida, PASSWORD_DEFAULT);
    } else {
        $pass_valida_hash = $password_valida; // campo vuoto → mantieni hash esistente
    }

    if (!empty($nuova_pass_admin)) {
        $pass_admin_hash = (password_verify($nuova_pass_admin, $password_admin) || $nuova_pass_admin === $password_admin)
            ? $password_admin
            : password_hash($nuova_pass_admin, PASSWORD_DEFAULT);
    } else {
        $pass_admin_hash = $password_admin; // campo vuoto → mantieni hash esistente
    }

    $data_aggiornata = ($pass_valida_hash !== $password_valida || $pass_admin_hash !== $password_admin)
        ? date('d/m/Y H:i')
        : $data_cambio_pass;

    $nuove_settings = [
        "password_valida"     => $pass_valida_hash,
        "password_admin"      => $pass_admin_hash,
        "email_responsabili"  => $_POST['email_responsabili'],
        "visibilita_pubblica" => isset($_POST['visibilita_pubblica']),
        "data_cambio_pass"    => $data_aggiornata,
        "orari_aule"          => $nuove_aule
    ];

    salvaImpostazioni($nuove_settings);
    logAzione('ADMIN_SETTINGS', ['operatore' => 'ADMIN']);
    header("Location: admin_settings.php?ok=1");
    exit;
}

$messaggio = isset($_GET['ok']) ? "<div class='alert successo'>✅ Impostazioni salvate correttamente!</div>" : "";
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Configurazione SuperAdmin</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        body { background-color: #fcfcfc; font-family: 'Segoe UI', sans-serif; }
        .admin-card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; padding: 20px; margin-bottom: 25px; }
        .section-title { color: #0056b3; font-weight: 600; margin-bottom: 20px; border-left: 4px solid #0056b3; padding-left: 10px; }
        .row-aula { display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid #eee; }
        .drag-handle { cursor: move; color: #ccc; padding: 10px; }
        .switch-container { display: flex; align-items: center; gap: 15px; background: #eef6ff; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        /* Tooltip recupero password */
        .tooltip-wrap { position: relative; display: inline-block; }
        .tooltip-icon { cursor: pointer; font-size: 1rem; user-select: none; }
        .tooltip-box {
            display: none;
            position: absolute;
            top: 30px; left: 0;
            z-index: 100;
            background: #fff;
            border: 1px solid #ffc107;
            border-left: 4px solid #ffc107;
            border-radius: 8px;
            padding: 15px 18px;
            width: 380px;
            font-size: 13px;
            color: #333;
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            line-height: 1.5;
        }
        .tooltip-box.visible { display: block; }
        .tooltip-box code { font-family: monospace; font-size: 12px; color: #c0392b; }

        .switch { position: relative; display: inline-block; width: 50px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #2196F3; }
        input:checked + .slider:before { transform: translateX(26px); }
    </style>
</head>
<body>
<div class="container-form" style="max-width: 1200px;">
    <?php if (!$mostra_config): ?>
        <div style="max-width:400px; margin:100px auto; text-align:center; background:white; padding:30px; border-radius:10px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">
            <h2>Area Protetta</h2>
            <?php if ($errore) echo "<p style='color:red'>" . htmlspecialchars($errore) . "</p>"; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= generaCSRF() ?>">
                <input type="password" name="unlock_pass" placeholder="Password Admin" required style="width:80%; margin-bottom:15px; padding:10px;"><br>
                <button type="submit" class="btn" style="background:#007bff; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">Sblocca</button>
            </form>
            <p><a href="index.php">Torna al tabellone</a></p>
        </div>
    <?php else: ?>
        <header style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h1>Configurazione</h1>
            <a href="index.php" class="btn">← Torna al Tabellone</a>
        </header>
        <?= $messaggio ?>
        <form method="post" onsubmit="return validaDateAule()">
            <input type="hidden" name="csrf_token" value="<?= generaCSRF() ?>">

            <div class="admin-card">
                <div class="section-title">🌐 Visibilità Pubblica</div>
                <div class="switch-container">
                    <label class="switch">
                        <input type="checkbox" name="visibilita_pubblica" <?= ($settings['visibilita_pubblica'] ?? false) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <span><b><?= ($settings['visibilita_pubblica'] ?? false) ? "Tabellone PUBBLICO" : "Tabellone PRIVATO" ?></b>
                    (Se ON, tutti vedono i dati. Se OFF, serve il login)</span>
                </div>
            </div>

            <div class="admin-card">
                <div class="section-title">
                    🔑 Credenziali
                    <span class="tooltip-wrap" style="margin-left:10px; vertical-align:middle;">
                        <span class="tooltip-icon" onclick="toggleTooltip()">🆘</span>
                        <div class="tooltip-box" id="tooltip-recupero">
                            <strong>🔐 Ho dimenticato la password — come recupero?</strong>
                            <ol style="margin:10px 0 0; padding-left:18px; line-height:1.8;">
                                <li>Accedi al server tramite <b>FTP / cPanel / File Manager</b></li>
                                <li>Apri il file <code>impostazioni.json</code></li>
                                <li>Sostituisci il valore cifrato con una password in chiaro:<br>
                                    <code style="display:block; margin-top:5px; background:#f4f4f4; padding:6px 8px; border-radius:4px;">
                                        "password_admin": "NuovaPassword"
                                    </code>
                                </li>
                                <li>Salva il file e accedi normalmente</li>
                                <li>Torna qui e risalva per ricifrare</li>
                            </ol>
                            <p style="margin-top:10px; font-size:11px; color:#999;">
                                ⚠️ Le password cifrate iniziano con <code>$2y$</code> — sono quelle da sostituire.
                            </p>
                        </div>
                    </span>
                </div>
                <p style="font-size:13px; color:#888; margin-bottom:15px;">
                    ℹ️ Lascia il campo invariato per non cambiare la password. Le password vengono salvate cifrate.
                </p>
                <div style="display:grid; grid-template-columns:1fr 1fr 2fr; gap:20px;">
                    <div>
                        <label>Nuova Password Sito</label><br>
                        <input type="password" name="password_valida" placeholder="(invariata)" class="pass-privacy" onfocus="this.type='text'" onblur="this.type='password'" style="width:100%">
                    </div>
                    <div>
                        <label>Nuova Password Admin</label><br>
                        <input type="password" name="password_admin" placeholder="(invariata)" class="pass-privacy" onfocus="this.type='text'" onblur="this.type='password'" style="width:100%">
                    </div>
                    <div>
                        <label>Email Responsabili</label><br>
                        <input type="text" name="email_responsabili" value="<?= htmlspecialchars($email_responsabili) ?>" style="width:100%">
                    </div>
                </div>
                <p style="font-size:12px; color:#aaa; margin-top:10px;">Ultimo cambio password: <?= htmlspecialchars($data_cambio_pass) ?></p>
            </div>

            <div class="admin-card">
                <div class="section-title">🏫 Gestione Aule</div>
                <div id="lista-aule">
                    <?php foreach ($orari_aule as $nome => $lim): ?>
                    <div class="row-aula">
                        <div class="drag-handle">☰</div>
                        <input type="color"  name="aula_colore[]"      value="<?= htmlspecialchars($lim['colore'] ?? '#007bff') ?>" style="width:40px; height:30px; border:none; cursor:pointer;">
                        <input type="text"   name="aula_nome[]"         value="<?= htmlspecialchars($nome) ?>" style="flex:2">
                        <input type="time"   name="aula_inizio[]"       value="<?= $lim['inizio'] ?>">
                        <input type="time"   name="aula_fine[]"         value="<?= $lim['fine'] ?>">
                        <input type="date"   name="aula_data_inizio[]"  value="<?= $lim['data_inizio'] ?>">
                        <input type="date"   name="aula_data_fine[]"    value="<?= $lim['data_fine'] ?>">
                        <button type="button" onclick="this.parentElement.remove()" style="background:red; color:white; border:none; padding:5px 8px; border-radius:4px;">✖</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="aggiungiAula()" class="btn" style="margin-top:10px;">➕ Aggiungi Aula</button>
            </div>

            <button type="submit" name="azione_salva" style="background:#28a745; color:white; padding:15px; border:none; width:100%; border-radius:8px; font-weight:bold; cursor:pointer;">
                💾 Salva Tutte le Impostazioni
            </button>
        </form>
    <?php endif; ?>
</div>

<script>
const lista = document.getElementById('lista-aule');
if (lista) Sortable.create(lista, { handle: '.drag-handle', animation: 150 });

function aggiungiAula() {
    const oggi = new Date().toISOString().split('T')[0];
    const tra1anno = new Date(Date.now() + 365*24*3600*1000).toISOString().split('T')[0];
    const html = `<div class="row-aula">
        <div class="drag-handle">☰</div>
        <input type="color" name="aula_colore[]" value="#28a745" style="width:40px; height:30px; border:none; cursor:pointer;">
        <input type="text"  name="aula_nome[]"  placeholder="Nuova Aula" style="flex:2">
        <input type="time"  name="aula_inizio[]" value="08:00">
        <input type="time"  name="aula_fine[]"   value="14:00">
        <input type="date"  name="aula_data_inizio[]" value="${oggi}">
        <input type="date"  name="aula_data_fine[]"   value="${tra1anno}">
        <button type="button" onclick="this.parentElement.remove()" style="background:red; color:white; border:none; padding:5px 8px; border-radius:4px;">✖</button>
    </div>`;
    lista.insertAdjacentHTML('beforeend', html);
}

// FIX: funzione validaDateAule() implementata
function toggleTooltip() {
    document.getElementById('tooltip-recupero').classList.toggle('visible');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.tooltip-wrap')) {
        const t = document.getElementById('tooltip-recupero');
        if (t) t.classList.remove('visible');
    }
});

function validaDateAule() {
    const righe = document.querySelectorAll('.row-aula');
    for (const riga of righe) {
        const nome = riga.querySelector('[name="aula_nome[]"]')?.value?.trim();
        const dal  = riga.querySelector('[name="aula_data_inizio[]"]')?.value;
        const al   = riga.querySelector('[name="aula_data_fine[]"]')?.value;
        const ini  = riga.querySelector('[name="aula_inizio[]"]')?.value;
        const fine = riga.querySelector('[name="aula_fine[]"]')?.value;
        if (!nome) { alert('❌ Inserisci un nome per tutte le aule.'); return false; }
        if (dal && al && dal > al) { alert(`❌ "${nome}": la data inizio è successiva alla data fine!`); return false; }
        if (ini && fine && ini >= fine) { alert(`❌ "${nome}": l'orario inizio deve essere precedente all'orario fine!`); return false; }
    }
    return true;
}
</script>
</body>
</html>