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
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f9; }
    .info-limiti { background:#fff3cd; color:#856404; padding:12px; border-radius:6px; margin-bottom:20px; font-size:14px; border:1px solid #ffeeba; display:none; }
    .gestione-cella { display:flex; flex-direction:column; align-items:center; gap:2px; min-width:60px; }
    .gestione-cella a, .gestione-cella button { text-decoration:none !important; font-size:1.1rem; display:inline-block; margin:0 2px; line-height:1; background:none; border:none; cursor:pointer; padding:0; }
    .admin-label { display:block; font-size:7px; color:#999; font-weight:normal; text-transform:uppercase; margin-top:4px !important; line-height:1; }
    .timer-badge { font-size:10px; color:#d35400; font-weight:bold; }
    header h1 { margin-bottom:10px !important; }
    .scuola-print { display:block; font-weight:bold; font-size:1.1rem; color:#555; margin-top:5px; margin-bottom:25px; }
    table { border-collapse:collapse; width:100%; background:white; table-layout:fixed; box-shadow:0 2px 5px rgba(0,0,0,0.05); }
    th { background:#f8f9fa !important; color:#333 !important; text-transform:uppercase; font-size:0.85rem; border:1px solid #dee2e6; padding:12px 8px; }
    td { border:1px solid #dee2e6; padding:8px; font-size:0.9rem; word-wrap:break-word; }
    th:nth-child(1), td:nth-child(1) { width:20%; }
    th:nth-child(2), td:nth-child(2) { width:10%; }
    th:nth-child(3), td:nth-child(3) { width:12%; }
    th:nth-child(4), td:nth-child(4) { width:23%; }
    th:nth-child(5), td:nth-child(5) { width:27%; }
    th:nth-child(6), td:nth-child(6) { width:8%; }
    .filtro-stampa { background:#fff; padding:15px; border-radius:8px; margin-bottom:10px; border:1px solid #dee2e6; }
    @media print {
        .no-print, .area-prenota, .nav-actions, .alert, #box-limiti, .filtro-stampa, th:last-child, td:last-child { display:none !important; }
        header { display:block !important; text-align:center !important; margin-bottom:20px; }
        header h1 { font-size:18pt !important; margin-bottom:8px; }
        .scuola-print { display:block !important; font-size:13pt; color:black; text-align:center; margin-top:5px; }
        body { background:white !important; padding:0; }
        table { border:1px solid black !important; box-shadow:none; margin-top:0; }
        th { background:#eee !important; border:1px solid black !important; font-size:9pt; }
        td { border:1px solid black !important; font-size:9pt; }
        td span { background:transparent !important; color:black !important; padding:0 !important; font-weight:bold !important; border:none !important; }
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
            <button onclick="window.print();" class="btn">🖨️ Stampa PDF</button>
            <?php if ($loggato): ?>
                <?php if ($is_admin): ?>
                    <a href="admin_settings.php" class="btn" style="background:#6c757d; color:white;">⚙️ Configurazione</a>
                <?php endif; ?>
                <a href="logout.php" style="color:red; margin-left:15px;">Esci</a>
            <?php else: ?>
                <a href="login.php" class="btn" style="background:#007bff; color:white;">➕ Nuova Prenotazione</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($loggato): ?>
    <section class="area-prenota no-print">
        <h3><?= $edit_id ? "📝 Modifica" : "+ Nuova Prenotazione" ?></h3>
        <div id="box-limiti" class="info-limiti"></div>
        <form method="post" onsubmit="return validaOrario()" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:15px;">
            <input type="hidden" name="csrf_token"  value="<?= generaCSRF() ?>">
            <input type="hidden" name="id_univoco"  value="<?= htmlspecialchars($edit_id) ?>">
            <div>
                <label>Docente</label><br>
                <input type="text" name="docente" value="<?= htmlspecialchars($val['docente']) ?>" required style="width:100%">
            </div>
            <div>
                <label>Ambiente / Aula</label><br>
                <select name="aula" id="selAula" required style="width:100%" onchange="aggiornaLimiti()">
                    <option value="">Scegli...</option>
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
            </div>
            <div><label>Giorno</label><br><input type="date" name="giorno" value="<?= $val['giorno'] ?>" required style="width:100%"></div>
            <div><label>Dalle</label><br><input type="time" name="ora_inizio" id="ora_inizio" value="<?= $val['ora_inizio'] ?>" required style="width:100%"></div>
            <div><label>Alle</label><br><input type="time" name="ora_fine" id="ora_fine" value="<?= $val['ora_fine'] ?>" required style="width:100%"></div>
            <div><label>Note</label><br><input type="text" name="note" value="<?= htmlspecialchars($val['note']) ?>" style="width:100%"></div>
            <div style="display:flex; align-items:flex-end;">
                <button type="submit" class="btn" style="background:#28a745; color:white; border:none; padding:10px; width:100%">
                    <?= $edit_id ? "Aggiorna" : "Salva" ?>
                </button>
            </div>
        </form>
    </section>
    <?php endif; ?>

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
</div>

<div class="no-print" style="margin-top:20px; padding-top:15px; border-top:1px solid #eee; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div style="font-size:13px; color:#666; line-height:1.8;">
         Avola (SR) – Sviluppato da <strong style="color:#333;">Sebastiano Basile</strong> | 
        <span style="font-size:11px;">v2.0 &middot; Open source &middot; Licenza MIT</span>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="https://superscuola.com" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #1d4ed8;color:#1d4ed8;">🌐 superscuola.com</a>
        <a href="https://t.me/sostegno"   target="_blank" style="display:inline-flex;align-i tems:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #0088cc;color:#0088cc;">✈️ Telegram</a>
        <a href="https://github.com/sebastianobasile" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #24292f;color:#24292f;">💻 GitHub</a>
        <a href="https://paypal.me/superscuola" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #003087;color:#003087;">💙 Sostieni il progetto</a>
    </div>
</div>

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
