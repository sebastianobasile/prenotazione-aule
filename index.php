<?php
require_once 'config.php';

$settings  = caricaImpostazioni();
$pubblico  = $settings['visibilita_pubblica'] ?? false;
$loggato   = isset($_SESSION['loggato']) && $_SESSION['loggato'] === true;

if (!$pubblico && !$loggato) {
    header("Location: login.php");
    exit;
}

$prenotazioni      = caricaDati();
$orari_aule_config = $settings['orari_aule'] ?? [];
$is_admin          = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$filtro_aula       = $_GET['filtro_aula'] ?? '';

// ── Caricamento dati per modifica ──────────────────────────────────────────
$edit_id = $_GET['edit'] ?? '';
$val = ['docente'=>'','aula'=>'','giorno'=>date('Y-m-d'),'ora_inizio'=>'','ora_fine'=>'','note'=>''];

if ($edit_id && isset($prenotazioni[$edit_id])) {
    $p_edit    = $prenotazioni[$edit_id];
    $diff_edit = time() - ($p_edit['timestamp'] ?? 0);
    if ($is_admin || ($p_edit['session_id'] === session_id() && $diff_edit < 600)) {
        $val = $p_edit;
    } else {
        header("Location: index.php?msg=errore_permessi");
        exit;
    }
}

// ── Salvataggio ────────────────────────────────────────────────────────────
if ($loggato && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['docente'])) {
    verificaCSRF();

    $aula_sel   = $_POST['aula'];
    $giorno_sel = $_POST['giorno'];
    $inizio     = $_POST['ora_inizio'];
    $fine       = $_POST['ora_fine'];
    $id_attuale = $_POST['id_univoco'] ?: '';
    $conf       = $orari_aule_config[$aula_sel] ?? [];

    if ($giorno_sel < ($conf['data_inizio'] ?? '1970-01-01') || $giorno_sel > ($conf['data_fine'] ?? '2099-12-31')) {
        header("Location: index.php?msg=errore_periodo&dal=" . date('d/m/Y', strtotime($conf['data_inizio'])) . "&al=" . date('d/m/Y', strtotime($conf['data_fine'])));
        exit;
    }

    foreach ($prenotazioni as $p) {
        if ($id_attuale && ($p['id'] ?? '') === $id_attuale) continue;
        if (trim($p['aula'] ?? '') === trim($aula_sel) && date('Y-m-d', strtotime($p['giorno'] ?? '')) === date('Y-m-d', strtotime($giorno_sel))) {
            if ($inizio < $p['ora_fine'] && $fine > $p['ora_inizio']) {
                header("Location: index.php?msg=errore_occupato&conflitto=" . urlencode($p['docente']) . "&ora_conf=" . urlencode($p['ora_inizio'] . " - " . $p['ora_fine']));
                exit;
            }
        }
    }

    $id_salva  = $id_attuale ?: uniqid();
    $timestamp = $id_attuale ? ($prenotazioni[$id_salva]['timestamp'] ?? time()) : time();
    $sessione  = $id_attuale ? ($prenotazioni[$id_salva]['session_id'] ?? session_id()) : session_id();

    $prenotazioni[$id_salva] = [
        'id'         => $id_salva,
        'aula'       => $aula_sel,
        'giorno'     => $giorno_sel,
        'ora_inizio' => $inizio,
        'ora_fine'   => $fine,
        'docente'    => htmlspecialchars(strip_tags($_POST['docente'])),
        'note'       => htmlspecialchars(strip_tags($_POST['note'])),
        'timestamp'  => $timestamp,
        'session_id' => $sessione
    ];
    salvaDati($prenotazioni);
    inviaNotificaEmail($prenotazioni[$id_salva], $id_attuale ? "MODIFICA" : "NUOVA");
    echo "<script>window.location.href='index.php?msg=ok';</script>";
    exit;
}

// ── Ordinamento ────────────────────────────────────────────────────────────
$nomi_aule_ordine = array_keys($orari_aule_config);
uasort($prenotazioni, function($a, $b) use ($nomi_aule_ordine) {
    $posA = array_search($a['aula'] ?? '', $nomi_aule_ordine);
    $posB = array_search($b['aula'] ?? '', $nomi_aule_ordine);
    if ($posA !== $posB) return $posA <=> $posB;
    $g = strcmp($a['giorno'] ?? '', $b['giorno'] ?? '');
    return $g !== 0 ? $g : strcmp($a['ora_inizio'] ?? '', $b['ora_inizio'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Tabellone Prenotazioni</title>
<link rel="stylesheet" href="style.css">
<style>
    /* ── Base ── */
    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: #f0f4f8;
        font-size: 14px;
        color: #1a1a2e;
    }

    /* ── Contenitore ── */
    .container {
        border-radius: 12px;
        box-shadow: 0 2px 16px rgba(0,0,0,.09);
        padding: 28px 32px;
    }

    /* ── Header ── */
    header { border-bottom: 2px solid #e8edf2 !important; padding-bottom: 16px !important; margin-bottom: 22px !important; }
    header h1 { font-size: 1.55rem !important; font-weight: 800; color: #0056b3; letter-spacing: -.3px; margin-bottom: 4px !important; }
    .scuola-print { font-size: .9rem !important; color: #6b7280 !important; font-weight: 500; margin-bottom: 0 !important; }

    /* ── Pulsanti header ── */
    .btn { border-radius: 6px !important; font-size: .82rem !important; padding: 8px 16px !important; letter-spacing: .2px; }

    /* ── Form nuova prenotazione ── */
    .area-prenota {
        background: #f0f7ff;
        border: 1.5px solid #c8dff7;
        border-radius: 10px;
        padding: 18px 20px;
        margin-bottom: 18px;
    }
    .area-prenota h3 { font-size: .92rem; font-weight: 700; color: #1e3a6e; margin-bottom: 14px; }
    .area-prenota label { font-size: .72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
    .area-prenota input, .area-prenota select {
        border: 1.5px solid #d1dce8 !important;
        border-radius: 6px !important;
        padding: 8px 10px !important;
        font-size: .88rem !important;
        transition: border-color .15s, box-shadow .15s;
    }
    .area-prenota input:focus, .area-prenota select:focus {
        outline: none;
        border-color: #0056b3 !important;
        box-shadow: 0 0 0 3px rgba(0,86,179,.10);
    }
    .info-limiti {
        background: #fffbeb; color: #92400e; padding: 10px 14px;
        border-radius: 6px; margin-bottom: 14px; font-size: .82rem;
        border: 1px solid #fde68a; display: none;
    }

    /* ── Filtro ── */
    .filtro-stampa {
        background: #f8fafc;
        border: 1px solid #e2e8f0 !important;
        border-radius: 8px;
        padding: 12px 16px !important;
        margin-bottom: 12px;
        font-size: .85rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .filtro-stampa select {
        padding: 6px 10px !important;
        border: 1.5px solid #d1dce8;
        border-radius: 6px;
        font-size: .85rem;
        background: white;
    }

    /* ── Tabella ── */
    table {
        border-collapse: collapse;
        width: 100%;
        background: white;
        table-layout: fixed;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 1px 8px rgba(0,0,0,.07);
    }
    th {
        background: #0056b3 !important;
        color: white !important;
        text-transform: uppercase;
        font-size: .72rem !important;
        font-weight: 700;
        letter-spacing: .5px;
        padding: 12px 10px !important;
        border: none !important;
    }
    td {
        border: none !important;
        border-bottom: 1px solid #f1f5f9 !important;
        padding: 11px 10px !important;
        font-size: .88rem;
        vertical-align: middle;
    }
    tbody tr:last-child td { border-bottom: none !important; }
    tbody tr:hover { background: #f8fbff !important; }
    tbody tr:nth-child(even) { background: #fafcff; }

    th:nth-child(1), td:nth-child(1) { width:20%; }
    th:nth-child(2), td:nth-child(2) { width:10%; }
    th:nth-child(3), td:nth-child(3) { width:12%; }
    th:nth-child(4), td:nth-child(4) { width:23%; }
    th:nth-child(5), td:nth-child(5) { width:27%; }
    th:nth-child(6), td:nth-child(6) { width:8%; }

    /* ── Gestione cella ── */
    .gestione-cella { display:flex; flex-direction:column; align-items:center; gap:2px; min-width:60px; }
    .gestione-cella a, .gestione-cella button {
        text-decoration:none !important; font-size:1rem; display:inline-block;
        margin:0 2px; line-height:1; background:none; border:none;
        cursor:pointer; padding:3px; border-radius:4px; transition: background .12s;
    }
    .gestione-cella a:hover, .gestione-cella button:hover { background:#f1f5f9; }
    .admin-label { display:block; font-size:7px; color:#aaa; font-weight:700; text-transform:uppercase; margin-top:3px !important; letter-spacing:.4px; }
    .timer-badge { font-size:.72rem; color:#d35400; font-weight:700; font-family:monospace; }

    /* ── Tooltip icona "i" ── */
    .tip-wrap { position:relative; }
    .tip-box {
        display: none;
        position: absolute;
        bottom: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%);
        background: #1e3a6e;
        color: white;
        font-size: .78rem;
        font-weight: 500;
        padding: 6px 11px;
        border-radius: 6px;
        white-space: nowrap;
        box-shadow: 0 3px 10px rgba(0,0,0,.2);
        pointer-events: none;
        z-index: 100;
    }
    .tip-box::after {
        content: '';
        position: absolute;
        top: 100%; left: 50%;
        transform: translateX(-50%);
        border: 5px solid transparent;
        border-top-color: #1e3a6e;
    }
    .tip-wrap:hover .tip-box { display: block; }

    /* ── Responsive mobile ── */
    @media (max-width: 768px) {
        form[onsubmit] { grid-template-columns: 1fr 1fr !important; }
        form[onsubmit] button { grid-column: 1 / -1; }
    }
    @media (max-width: 480px) {
        form[onsubmit] { grid-template-columns: 1fr !important; }
    }

    /* ── Stampa ── */
    @media print {
        .no-print, .area-prenota, .nav-actions, .alert, #box-limiti,
        .filtro-stampa, th:last-child, td:last-child { display:none !important; }
        header { display:block !important; text-align:center !important; margin-bottom:20px; }
        header h1 { font-size:18pt !important; margin-bottom:8px; color:#000 !important; }
        .scuola-print { display:block !important; font-size:13pt; color:black !important; text-align:center; }
        body { background:white !important; padding:0; }
        .container { box-shadow:none !important; padding:0 !important; border-radius:0 !important; }
        table { border:1px solid #999 !important; box-shadow:none; border-radius:0 !important; overflow:visible !important; }
        th { background:#ddd !important; color:#000 !important; border:1px solid #999 !important; font-size:9pt !important; }
        td { border:1px solid #ccc !important; font-size:9pt !important; }
        td span { background:transparent !important; color:#000 !important; padding:0 !important; font-weight:bold !important; border:none !important; }
        tr { page-break-inside:avoid; }
    }
</style>
</head>
<body>
<div class="container">
    <header style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h1>Prenotazione Aule e Spazi Scolastici</h1>
            <p class="scuola-print">3° I.C. Capuana-De Amicis – Avola (SR)</p>
        </div>
        <div class="nav-actions no-print">
            <button onclick="window.print();" class="btn" style="background:#0056b3; color:white;">🖨️ Stampa PDF</button>
            <?php if ($loggato): ?>
                <?php if ($is_admin): ?>
                    <a href="admin_settings.php" class="btn" style="background:#6c757d; color:white;">⚙️ Configurazione</a>
                <?php endif; ?>
                <a href="logout.php" style="color:#dc3545; font-size:.82rem; font-weight:600; text-decoration:none; margin-left:10px; padding:8px 4px;">Esci</a>
            <?php else: ?>
                <a href="login.php" class="btn" style="background:#007bff; color:white;">➕ Nuova Prenotazione</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($loggato): ?>
    <section class="area-prenota no-print">
        <h3 style="font-size:.88rem; font-weight:700; color:#1e3a6e; margin-bottom:10px;"><?= $edit_id ? "📝 Modifica" : "+ Nuova Prenotazione" ?></h3>
        <div id="box-limiti" class="info-limiti"></div>
        <form method="post" onsubmit="return validaOrario()">
            <input type="hidden" name="csrf_token" value="<?= generaCSRF() ?>">
            <input type="hidden" name="id_univoco" value="<?= htmlspecialchars($edit_id) ?>">

            <!-- Riga 1: campi principali -->
            <div style="display:flex; gap:10px; margin-bottom:8px; flex-wrap:nowrap;">
                <input type="text"   name="docente"    value="<?= htmlspecialchars($val['docente']) ?>" required placeholder="Docente" style="flex:2; min-width:0;">
                <select name="aula" id="selAula" required onchange="aggiornaLimiti()" style="flex:2; min-width:0;">
                    <option value="">Scegli aula…</option>
                    <?php foreach ($orari_aule_config as $nome => $c): ?>
                        <option value="<?= htmlspecialchars($nome) ?>"
                            data-ini="<?= $c['inizio'] ?>" data-fin="<?= $c['fine'] ?>"
                            data-dal="<?= dataIta($c['data_inizio']) ?>" data-al="<?= dataIta($c['data_fine']) ?>"
                            data-dal-raw="<?= $c['data_inizio'] ?>" data-al-raw="<?= $c['data_fine'] ?>"
                            <?= $val['aula']==$nome ? 'selected' : '' ?>>
                            <?= htmlspecialchars($nome) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date"  name="giorno"     value="<?= $val['giorno'] ?>"      required  style="flex:1.4; min-width:0;">
                <input type="time"  name="ora_inizio" id="ora_inizio" value="<?= $val['ora_inizio'] ?>" required style="flex:1; min-width:0;">
                <span style="flex-shrink:0; position:relative; display:inline-flex; align-items:center;" class="tip-wrap">
                    <span style="width:16px; height:16px; background:#0056b3; color:white; border-radius:50%; font-size:.65rem; font-weight:800; display:flex; align-items:center; justify-content:center; cursor:default; user-select:none;">i</span>
                    <span class="tip-box">🕐 Orario di <strong>inizio</strong> prenotazione</span>
                </span>
                <input type="time"  name="ora_fine"   id="ora_fine"   value="<?= $val['ora_fine'] ?>"   required style="flex:1; min-width:0;">
                <span style="flex-shrink:0; position:relative; display:inline-flex; align-items:center;" class="tip-wrap">
                    <span style="width:16px; height:16px; background:#0056b3; color:white; border-radius:50%; font-size:.65rem; font-weight:800; display:flex; align-items:center; justify-content:center; cursor:default; user-select:none;">i</span>
                    <span class="tip-box">🕐 Orario di <strong>fine</strong> prenotazione</span>
                </span>
            </div>

            <!-- Riga 2: note + salva -->
            <div style="display:flex; gap:10px;">
                <input type="text" name="note" value="<?= htmlspecialchars($val['note']) ?>" placeholder="Note facoltative (es. Classe 3A, n° alunni…)" style="flex:1; min-width:0;">
                <button type="submit" class="btn" style="background:#28a745; color:white; border:none; padding:9px 22px; white-space:nowrap; flex-shrink:0;">
                    <?= $edit_id ? "✔ Aggiorna" : "✔ Salva" ?>
                </button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <!-- Tab Lista / Calendario -->
    <div class="no-print" style="display:flex; border-bottom:2px solid #dee2e6; margin-bottom:14px;">
        <a href="index.php<?= $filtro_aula ? '?filtro_aula='.urlencode($filtro_aula) : '' ?>"
           style="padding:8px 20px; font-size:.875rem; font-weight:700; color:#0056b3; text-decoration:none; border-bottom:3px solid #0056b3; margin-bottom:-2px;">
            📋 Lista
        </a>
        <a href="calendario.php<?= $filtro_aula ? '?filtro_aula='.urlencode($filtro_aula) : '' ?>"
           style="padding:8px 20px; font-size:.875rem; font-weight:700; color:#555; text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px;">
            📆 Calendario
        </a>
    </div>

    <div class="filtro-stampa no-print">
        <label><b>Filtra per aula:</b></label>
        <select onchange="location.href='index.php?filtro_aula=' + this.value" style="padding:5px; border-radius:4px;">
            <option value="">--- Tutte le aule ---</option>
            <?php foreach ($orari_aule_config as $nome => $c): ?>
                <option value="<?= htmlspecialchars($nome) ?>" <?= $filtro_aula == $nome ? 'selected' : '' ?>>
                    <?= htmlspecialchars($nome) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($filtro_aula): ?>
            <a href="index.php" style="font-size:12px; margin-left:10px; color:red;">Rimuovi filtro</a>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr style="background:#2c5aa0; color:white;">
                <th>AMBIENTE / AULA</th><th>Giorno</th><th>Orario</th>
                <th>Docente</th><th>Note</th><th class="no-print">Gestione</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($prenotazioni as $id => $p):
            if ($filtro_aula && $p['aula'] !== $filtro_aula) continue;
            $abilitato = $loggato && ($is_admin || ($p['session_id'] === session_id() && (600 - (time() - ($p['timestamp'] ?? 0))) > 0));
        ?>
        <tr>
            <td>
                <span style="background-color:<?= $orari_aule_config[$p['aula']]['colore'] ?? '#6c757d' ?>; color:white; padding:4px 10px; border-radius:5px; font-weight:bold;">
                    <?= htmlspecialchars($p['aula']) ?>
                </span>
            </td>
            <td><b><?= dataIta($p['giorno']) ?></b></td>
            <td><?= htmlspecialchars($p['ora_inizio']) ?> - <?= htmlspecialchars($p['ora_fine']) ?></td>
            <td><?= htmlspecialchars($p['docente']) ?></td>
            <td><small><?= htmlspecialchars($p['note']) ?></small></td>
            <td class="no-print" style="text-align:center;">
                <div class="gestione-cella">
                    <?php if ($abilitato): ?>
                        <div>
                            <a href="index.php?edit=<?= $p['id'] ?>">📝</a>
                            <!-- FIX: cancella via POST con CSRF invece di semplice link GET -->
                            <form method="post" action="cancella_rapida.php" style="display:inline" onsubmit="return confirm('Eliminare questa prenotazione?')">
                                <input type="hidden" name="csrf_token" value="<?= generaCSRF() ?>">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                                <button type="submit">🗑️</button>
                            </form>
                        </div>
                        <?php if (!$is_admin): ?>
                            <span class="timer-badge" data-sec="<?= 600 - (time() - $p['timestamp']) ?>"></span>
                        <?php else: ?>
                            <span class="admin-label">ADMIN</span>
                        <?php endif; ?>
                    <?php else: ?>🔒<?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<div class="no-print" style="margin-top:20px; padding-top:15px; border-top:1px solid #eee; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div style="font-size:13px; color:#666; line-height:1.8;">
         Avola (SR) – Sviluppato da Sebastiano <strong style="color:#333;">Basile</strong> | 
        <span style="font-size:11px;">v2.0 &middot; Open source &middot; Licenza MIT</span>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="https://capuanadeamicis.it" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #1d4ed8;color:#1d4ed8;">🌐 capuanadeamicis.it</a>
        <a href="https://t.me/sostegno"   target="_blank" style="display:inline-flex;align-i tems:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #0088cc;color:#0088cc;">✈️ Telegram</a>
        <a href="https://github.com/sebastianobasile" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #24292f;color:#24292f;">💻 GitHub</a>
        <a href="https://paypal.me/superscuola" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #003087;color:#003087;">💙 Sostieni il progetto</a>
    </div>
</div>

</div><!-- /container -->

<script>
window.onload = function() {
    const params = new URLSearchParams(window.location.search);
    const msg = params.get('msg');
    if (msg === 'errore_occupato') {
        alert("⚠️ AMBIENTE O AULA GIÀ OCCUPATA!\n\nDocente: " + params.get('conflitto') + "\nOrario: " + params.get('ora_conf'));
    } else if (msg === 'errore_permessi') {
        alert("⛔ Non hai i permessi per questa operazione.");
    } else if (msg === 'errore_periodo') {
        alert("❌ Data fuori periodo consentito!\nDal " + params.get('dal') + " al " + params.get('al'));
    }
    // Rimuovi i parametri dall'URL senza ricaricare la pagina
    if (msg) window.history.replaceState({}, '', 'index.php');
};

function aggiornaLimiti() {
    const sel = document.getElementById('selAula');
    const box = document.getElementById('box-limiti');
    const opt = sel.options[sel.selectedIndex];
    if (sel.value) {
        box.style.display = 'block';
        box.innerHTML = `⚠️ <b>Info ${sel.value}:</b> Prenotabile dal <b>${opt.dataset.dal}</b> al <b>${opt.dataset.al}</b> | Ore: <b>${opt.dataset.ini} – ${opt.dataset.fin}</b>`;
    }
}

function validaOrario() {
    const sel    = document.getElementById('selAula');
    const opt    = sel.options[sel.selectedIndex];
    const giorno = document.getElementsByName('giorno')[0].value;
    const ini    = document.getElementById('ora_inizio').value;
    const fin    = document.getElementById('ora_fine').value;
    if (giorno < opt.dataset.dalRaw || giorno > opt.dataset.alRaw) { alert("❌ Data fuori periodo!"); return false; }
    if (ini < opt.dataset.ini || fin > opt.dataset.fin)             { alert("❌ Orario fuori fascia!"); return false; }
    if (ini >= fin)                                                   { alert("❌ Errore: l'orario di fine deve essere dopo quello di inizio!"); return false; }
    return true;
}

setInterval(function() {
    document.querySelectorAll('.timer-badge').forEach(function(el) {
        let sec = parseInt(el.dataset.sec);
        if (sec > 0) {
            sec--;
            el.dataset.sec = sec;
            el.innerText = "-" + Math.floor(sec / 60) + "m " + (sec % 60 < 10 ? '0' : '') + (sec % 60) + "s";
        } else {
            el.closest('.gestione-cella').innerHTML = "🔒";
        }
    });
}, 1000);
</script>
</body>
</html>
