<?php
require_once 'config.php';

$settings     = caricaImpostazioni();
$pubblico     = $settings['visibilita_pubblica'] ?? false;
$loggato      = isset($_SESSION['loggato']) && $_SESSION['loggato'] === true;
if (!$pubblico && !$loggato) { header("Location: login.php"); exit; }

$orari_aule   = $settings['orari_aule'] ?? [];
$prenotazioni = caricaDati();
$is_admin     = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// Navigazione settimana
$offset = (int)($_GET['w'] ?? 0);
$base   = new DateTime();
$base->modify("{$offset} week");
$dow    = (int)$base->format('N');
$lunedi = (clone $base)->modify('-'.($dow-1).' days');
$sabato = (clone $lunedi)->modify('+5 days');
$giorni = [];
for ($i = 0; $i < 6; $i++) $giorni[] = (clone $lunedi)->modify("+{$i} days");

$filtro   = $_GET['filtro_aula'] ?? '';
$aule_vis = $filtro ? [$filtro => $orari_aule[$filtro] ?? null] : $orari_aule;

// Fascia oraria globale
$ora_min = 8; $ora_max = 19;
foreach ($orari_aule as $c) {
    $h = (int)explode(':', $c['inizio'])[0]; if ($h < $ora_min) $ora_min = $h;
    $h = (int)explode(':', $c['fine'])[0] + 1; if ($h > $ora_max) $ora_max = $h;
}
$num_ore = $ora_max - $ora_min;

// Mappa prenotazioni per aula → giorno
$mappa = [];
foreach ($prenotazioni as $p) $mappa[$p['aula']][$p['giorno']][] = $p;
$giorni_ita = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];
$oggi_str   = date('Y-m-d');
$vista      = ($_GET['vista'] ?? 'settimana') === 'mese' ? 'mese' : 'settimana';

// Navigazione mese
$mese_offset = (int)($_GET['m'] ?? 0);
$mese_base   = new DateTime('first day of this month');
$mese_base->modify("{$mese_offset} month");
$mese_anno   = (int)$mese_base->format('Y');
$mese_num    = (int)$mese_base->format('n');
$mesi_ita    = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];

function pct(string $t, int $mn, int $no): float {
    [$h, $m] = explode(':', $t);
    return max(0, min(100, (((int)$h - $mn) * 60 + (int)$m) / ($no * 60) * 100));
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario — Prenotazione Aule</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .cal-nav { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
        .cal-nav-left { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .cal-nav h2 { margin:0; font-size:1rem; font-weight:700; color:#333; }
        .btn-nav { padding:6px 14px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px; font-size:.85em; font-weight:600; text-decoration:none; color:#333; }
        .btn-nav:hover { background:#e9ecef; }
        .legenda { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:14px; }
        .legenda-item { display:flex; align-items:center; gap:6px; font-size:.82em; color:#555; }
        .legenda-dot { width:11px; height:11px; border-radius:3px; flex-shrink:0; }
        .cal-scroll { overflow-x:auto; border-radius:6px; border:1px solid #dee2e6; }
        .cal-tbl { width:100%; min-width:750px; border-collapse:collapse; font-size:.8em; }
        .cal-tbl thead th { background:#0056b3; color:white; padding:10px 6px; text-align:center; font-size:.78em; font-weight:700; border-right:1px solid rgba(255,255,255,.2); }
        .cal-tbl thead th.col-aula { text-align:left; padding-left:12px; width:150px; }
        .cal-tbl thead th.oggi-col { background:#1976d2; }
        .cal-tbl tbody td { padding:0; border:1px solid #eee; vertical-align:top; }
        .td-aula { padding:0 10px; font-weight:700; font-size:.82em; background:#f8f9fa; border-right:2px solid #dee2e6; white-space:nowrap; height:52px; }
        .td-aula-inner { display:flex; align-items:center; gap:7px; height:100%; }
        .aula-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .day-cell { position:relative; height:52px; background:#fff; }
        .day-cell.is-oggi { background:#f0f7ff; }
        .day-cell.is-past { background:#fafafa; }
        .h-line { position:absolute; top:0; bottom:0; width:1px; background:#e9ecef; z-index:1; }
        .h-label { position:absolute; top:3px; font-size:.58em; color:#bbb; transform:translateX(-50%); pointer-events:none; z-index:2; }
        .bk { position:absolute; top:5px; bottom:5px; z-index:3; border-radius:4px; padding:2px 6px; color:white; font-size:.72em; font-weight:700; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.2); display:flex; flex-direction:column; justify-content:center; cursor:default; transition:filter .15s; }
        .bk:hover { filter:brightness(1.12); z-index:10; }
        .bk.cliccabile { cursor:pointer; }
        .bk-nome { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .bk-orario { font-size:.68em; opacity:.85; }
        .now-line { position:absolute; top:0; bottom:0; width:2px; background:#dc3545; z-index:8; }
        .now-dot { width:8px; height:8px; border-radius:50%; background:#dc3545; position:absolute; top:-4px; left:-3px; }
        .tab-bar { display:flex; border-bottom:2px solid #dee2e6; margin-bottom:18px; }
        .tab-bar a { padding:8px 20px; font-size:.875rem; font-weight:600; text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px; color:#555; }
        .tab-bar a.attivo { color:#0056b3; border-bottom-color:#0056b3; }
        .tab-bar a:hover { color:#0056b3; }
    </style>
</head>
<body>
<div class="container">

    <header>
        <div>
            <h1>📆 Calendario Prenotazioni</h1>
            <p>3° I.C. Capuana-De Amicis — Avola (SR)</p>
        </div>
        <div class="nav-actions">
            <?php if ($loggato): ?>
                <?php if ($is_admin): ?><a href="admin_settings.php" class="btn secondary">⚙️ Configurazione</a><?php endif; ?>
                <a href="logout.php" class="logout">Esci</a>
            <?php else: ?>
                <a href="login.php" class="btn">➕ Prenota</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Tabs Lista / Calendario + switch Settimana / Mese -->
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #dee2e6; margin-bottom:18px; flex-wrap:wrap; gap:8px;">
        <div class="tab-bar" style="border:none; margin:0;">
            <a href="index.php<?= $filtro ? '?filtro_aula='.urlencode($filtro) : '' ?>">📋 Lista</a>
            <a href="calendario.php<?= $filtro ? '?filtro_aula='.urlencode($filtro) : '' ?>" class="attivo">📆 Calendario</a>
        </div>
        <div style="display:flex; gap:4px; margin-bottom:2px;">
            <a href="?vista=settimana<?= $filtro?'&filtro_aula='.urlencode($filtro):'' ?>"
               style="padding:5px 14px; font-size:.8rem; font-weight:700; text-decoration:none; border-radius:4px;
                      <?= $vista==='settimana' ? 'background:#0056b3; color:white;' : 'background:#f1f5f9; color:#555; border:1px solid #dee2e6;' ?>">
                Settimana
            </a>
            <a href="?vista=mese<?= $filtro?'&filtro_aula='.urlencode($filtro):'' ?>"
               style="padding:5px 14px; font-size:.8rem; font-weight:700; text-decoration:none; border-radius:4px;
                      <?= $vista==='mese' ? 'background:#0056b3; color:white;' : 'background:#f1f5f9; color:#555; border:1px solid #dee2e6;' ?>">
                Mese
            </a>
        </div>
    </div>

    <?php if ($vista === 'settimana'): ?>

    <!-- Navigazione settimana -->
    <div class="cal-nav">
        <div class="cal-nav-left">
            <a href="?vista=settimana&w=<?= $offset-1 . ($filtro ? '&filtro_aula='.urlencode($filtro) : '') ?>" class="btn-nav">◀ Prec.</a>
            <?php if ($offset !== 0): ?>
                <a href="?vista=settimana&w=0<?= $filtro ? '&filtro_aula='.urlencode($filtro) : '' ?>" class="btn-nav">Oggi</a>
            <?php endif; ?>
            <a href="?vista=settimana&w=<?= $offset+1 . ($filtro ? '&filtro_aula='.urlencode($filtro) : '') ?>" class="btn-nav">Succ. ▶</a>
            <h2>Settimana <?= $lunedi->format('d/m') ?> — <?= $sabato->format('d/m/Y') ?></h2>
        </div>
        <div>
            <select onchange="location.href='?vista=settimana&w=<?= $offset ?>'+(this.value?'&filtro_aula='+encodeURIComponent(this.value):'')"
                    style="padding:6px 10px; border:1px solid #dee2e6; border-radius:4px; font-size:.875rem;">
                <option value="">— Tutte le aule —</option>
                <?php foreach ($orari_aule as $n => $c): ?>
                    <option value="<?= htmlspecialchars($n) ?>" <?= $filtro==$n?'selected':'' ?>><?= htmlspecialchars($n) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($filtro): ?>
                <a href="?vista=settimana&w=<?= $offset ?>" style="font-size:.8rem; color:#dc3545; text-decoration:none; margin-left:8px;">✕ Rimuovi</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Legenda -->
    <div class="legenda">
        <?php foreach ($orari_aule as $n => $c): ?>
            <div class="legenda-item">
                <span class="legenda-dot" style="background:<?= htmlspecialchars($c['colore']) ?>"></span>
                <span><?= htmlspecialchars($n) ?></span>
            </div>
        <?php endforeach; ?>
        <div class="legenda-item">
            <span class="legenda-dot" style="background:#dc3545; border-radius:50%;"></span>
            <span>Ora attuale</span>
        </div>
    </div>

    <!-- Banner informativo -->
    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:.84rem; color:#1e40af; display:flex; align-items:flex-start; gap:10px;">
        <span style="font-size:1.1rem; flex-shrink:0;">ℹ️</span>
        <span>
            <strong>Come leggere il calendario:</strong>
            ogni riga è un'aula, ogni colonna un giorno della settimana.
            I <strong>blocchi colorati</strong> indicano le prenotazioni esistenti — passaci sopra col mouse per vedere docente, orario e note.
            Le celle <strong>vuote</strong> sono orari liberi disponibili per la prenotazione.
            Per prenotare vai alla vista <a href="index.php" style="color:#1e40af; font-weight:700;">📋 Lista</a>.
        </span>
    </div>

    <!-- Timeline -->
    <div class="cal-scroll">
        <table class="cal-tbl">
            <thead>
                <tr>
                    <th class="col-aula">Aula / Spazio</th>
                    <?php foreach ($giorni as $g): ?>
                        <?php $it = $g->format('Y-m-d') === $oggi_str;
                              $nome_giorno = $giorni_ita[(int)$g->format('N') - 1]; ?>
                        <th class="<?= $it ? 'oggi-col' : '' ?>">
                            <?= strtoupper($nome_giorno) ?><br>
                            <span style="font-size:1.1em; font-weight:900;"><?= $g->format('d') ?></span><span style="opacity:.8;">/<?= $g->format('m') ?></span>
                            <?php if ($it): ?><br><span style="font-size:.6em; background:rgba(255,255,255,.25); padding:1px 5px; border-radius:3px;">OGGI</span><?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($aule_vis as $nome => $conf):
                if (!$conf) continue;
                $col = $conf['colore'] ?? '#0056b3';
            ?>
            <tr>
                <td class="td-aula" style="border-left:4px solid <?= $col ?>;">
                    <div class="td-aula-inner">
                        <span class="aula-dot" style="background:<?= $col ?>"></span>
                        <span><?= htmlspecialchars($nome) ?></span>
                    </div>
                </td>
                <?php foreach ($giorni as $g):
                    $ds      = $g->format('Y-m-d');
                    $is_og   = $ds === $oggi_str;
                    $is_pa   = $ds < $oggi_str;
                    $blocchi = $mappa[$nome][$ds] ?? [];
                ?>
                <td>
                    <div class="day-cell <?= $is_og ? 'is-oggi' : ($is_pa ? 'is-past' : '') ?>">

                        <?php for ($i = 0; $i < $num_ore; $i++):
                            $p = round($i / $num_ore * 100, 2); ?>
                            <div class="h-line"  style="left:<?= $p ?>%"></div>
                            <div class="h-label" style="left:<?= $p ?>%"><?= $ora_min + $i ?></div>
                        <?php endfor; ?>

                        <?php foreach ($blocchi as $bk):
                            $l   = pct($bk['ora_inizio'], $ora_min, $num_ore);
                            $r   = pct($bk['ora_fine'],   $ora_min, $num_ore);
                            $w   = max(0.8, $r - $l);
                            $can = $loggato && ($is_admin || ($bk['session_id'] === session_id() && (600 - (time() - ($bk['timestamp'] ?? 0))) > 0));
                        ?>
                        <div class="bk <?= $can ? 'cliccabile' : '' ?>"
                             style="left:<?= round($l,2) ?>%; width:<?= round($w,2) ?>%; background:<?= $col ?>;"
                             <?= $can ? "onclick=\"location.href='index.php?edit={$bk['id']}'\"" : '' ?>
                             title="<?= htmlspecialchars($bk['docente']) ?> · <?= $bk['ora_inizio'] ?>–<?= $bk['ora_fine'] ?><?= $bk['note'] ? ' · '.$bk['note'] : '' ?>">
                            <div class="bk-nome"><?= htmlspecialchars($bk['docente']) ?></div>
                            <div class="bk-orario"><?= $bk['ora_inizio'] ?>–<?= $bk['ora_fine'] ?></div>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($is_og):
                            $np = pct(date('H:i'), $ora_min, $num_ore);
                            if ($np >= 0 && $np <= 100): ?>
                                <div class="now-line" style="left:<?= round($np,2) ?>%"><div class="now-dot"></div></div>
                        <?php endif; endif; ?>

                    </div>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div><!-- /cal-scroll -->

    <?php else: ?>
    <!-- ══════════════════ VISTA MENSILE ══════════════════ -->

    <div class="cal-nav">
        <div class="cal-nav-left">
            <a href="?vista=mese&m=<?= $mese_offset-1 . ($filtro?'&filtro_aula='.urlencode($filtro):'') ?>" class="btn-nav">◀ Prec.</a>
            <?php if ($mese_offset !== 0): ?>
                <a href="?vista=mese&m=0<?= $filtro?'&filtro_aula='.urlencode($filtro):'' ?>" class="btn-nav">Oggi</a>
            <?php endif; ?>
            <a href="?vista=mese&m=<?= $mese_offset+1 . ($filtro?'&filtro_aula='.urlencode($filtro):'') ?>" class="btn-nav">Succ. ▶</a>
            <h2><?= $mesi_ita[$mese_num] ?> <?= $mese_anno ?></h2>
        </div>
        <div>
            <select onchange="location.href='?vista=mese&m=<?= $mese_offset ?>'+(this.value?'&filtro_aula='+encodeURIComponent(this.value):'')"
                    style="padding:6px 10px; border:1px solid #dee2e6; border-radius:4px; font-size:.875rem;">
                <option value="">— Tutte le aule —</option>
                <?php foreach ($orari_aule as $n => $c): ?>
                    <option value="<?= htmlspecialchars($n) ?>" <?= $filtro==$n?'selected':'' ?>><?= htmlspecialchars($n) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($filtro): ?>
                <a href="?vista=mese&m=<?= $mese_offset ?>" style="font-size:.8rem; color:#dc3545; text-decoration:none; margin-left:8px;">✕ Rimuovi</a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $primo_giorno    = new DateTime("{$mese_anno}-{$mese_num}-01");
    $ultimo_giorno   = (clone $primo_giorno)->modify('last day of this month');
    $giorni_nel_mese = (int)$ultimo_giorno->format('d');
    $start_dow       = (int)$primo_giorno->format('N');
    ?>

    <style>
        .mese-grid { display:grid; grid-template-columns:repeat(6,1fr); border:1px solid #dee2e6; border-radius:8px; overflow:hidden; }
        .mese-hdr  { background:#0056b3; color:white; text-align:center; font-size:.72rem; font-weight:700; padding:8px 4px; text-transform:uppercase; letter-spacing:.4px; }
        .mese-cell { border-right:1px solid #e9ecef; border-bottom:1px solid #e9ecef; min-height:88px; padding:5px; background:white; }
        .mese-cell:nth-child(6n+7) { border-right:none; }
        .mese-cell.fuori-mese { background:#f8fafc; }
        .mese-cell.oggi-cell  { background:#eff6ff; }
        .mese-num { font-size:.72rem; font-weight:800; color:#94a3b8; width:22px; height:22px; display:flex; align-items:center; justify-content:center; border-radius:50%; margin-bottom:3px; }
        .mese-num.oggi-num { background:#0056b3; color:white; }
        .mese-pill { display:block; font-size:.68rem; font-weight:700; color:white; border-radius:3px; padding:1px 5px; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mese-pill:hover { filter:brightness(1.1); }
        .mese-more { font-size:.65rem; color:#0056b3; font-weight:600; margin-top:2px; }
    </style>

    <div class="mese-grid">
        <?php foreach (['Lun','Mar','Mer','Gio','Ven','Sab'] as $g): ?>
            <div class="mese-hdr"><?= $g ?></div>
        <?php endforeach; ?>

        <?php
        // Celle vuote prima del primo giorno (salta domeniche)
        $start = $start_dow === 7 ? 6 : $start_dow - 1;
        for ($v = 0; $v < $start; $v++) echo '<div class="mese-cell fuori-mese"></div>';

        for ($d = 1; $d <= $giorni_nel_mese; $d++):
            $data_str = sprintf('%04d-%02d-%02d', $mese_anno, $mese_num, $d);
            $dow_cell = (int)(new DateTime($data_str))->format('N');
            if ($dow_cell === 7) continue; // salta domenica
            $is_oggi  = $data_str === $oggi_str;

            $pren_giorno = [];
            foreach ($aule_vis as $na => $ca) {
                if (!$ca) continue;
                foreach ($mappa[$na][$data_str] ?? [] as $bk)
                    $pren_giorno[] = ['bk'=>$bk, 'colore'=>$ca['colore']??'#0056b3'];
            }
            usort($pren_giorno, fn($a,$b) => strcmp($a['bk']['ora_inizio'],$b['bk']['ora_inizio']));
        ?>
        <div class="mese-cell <?= $is_oggi?'oggi-cell':'' ?>">
            <div class="mese-num <?= $is_oggi?'oggi-num':'' ?>"><?= $d ?></div>
            <?php foreach (array_slice($pren_giorno,0,3) as $item): ?>
                <span class="mese-pill" style="background:<?= htmlspecialchars($item['colore']) ?>;"
                      title="<?= htmlspecialchars($item['bk']['docente']) ?> · <?= $item['bk']['ora_inizio'] ?>–<?= $item['bk']['ora_fine'] ?><?= $item['bk']['note']?' · '.$item['bk']['note']:'' ?>">
                    <?= $item['bk']['ora_inizio'] ?> <?= htmlspecialchars(mb_substr($item['bk']['docente'],0,11)) ?>
                </span>
            <?php endforeach; ?>
            <?php if (count($pren_giorno)>3): ?>
                <div class="mese-more">+<?= count($pren_giorno)-3 ?> altri</div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>

        <?php
        $last_dow = (int)$ultimo_giorno->format('N');
        if ($last_dow === 7) $last_dow = 6;
        for ($v = $last_dow; $v < 6; $v++) echo '<div class="mese-cell fuori-mese"></div>';
        ?>
    </div>

    <?php endif; ?>
    <!-- ══════════════════ fine viste ══════════════════ -->

    <!-- Footer crediti -->
    <div class="no-print" style="margin-top:20px; padding-top:15px; border-top:1px solid #eee; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="font-size:13px; color:#666; line-height:1.8;">
            Sviluppato da <strong style="color:#333;">Sebastiano Basile</strong>
            <span style="font-size:11px;"> &middot; Open source &middot; Licenza MIT</span>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="https://superscuola.com"             target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #1d4ed8;color:#1d4ed8;">🌐 superscuola.com</a>
            <a href="https://t.me/sostegno"               target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #0088cc;color:#0088cc;">✈️ Telegram</a>
            <a href="https://github.com/superscuola" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #24292f;color:#24292f;">💻 GitHub</a>
            <a href="https://paypal.me/sebastianobasile"  target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #003087;color:#003087;">💙 Sostieni il progetto</a>
        </div>
    </div>

</div>

<script>
setInterval(function() {
    const mn = <?= $ora_min ?>, no = <?= $num_ore ?>, now = new Date();
    const p  = Math.max(0, Math.min(100, ((now.getHours()-mn)*60+now.getMinutes())/(no*60)*100));
    document.querySelectorAll('.now-line').forEach(el => el.style.left = p.toFixed(2)+'%');
}, 60000);
</script>
</body>
</html>
