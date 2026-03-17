<?php
require_once 'config.php';
verificaLoggato();

$id           = $_GET['id'] ?? '';
$lista        = caricaDati();
$prenotazione = $lista[$id] ?? null;

if (!$prenotazione) {
    header("Location: index.php");
    exit;
}

$errore = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificaCSRF();

    $pass   = $_POST['admin_pass'] ?? '';
    $azione = $_POST['azione']     ?? '';

    // Verifica password admin (compatibile con hash e testo)
    $match = password_verify($pass, $password_admin) || $pass === $password_admin;

    if (!$match) {
        $errore = "❌ Password Admin errata!";
    } else {
        if ($azione === 'elimina') {
            // FIX: usa unset() invece di array_splice (che rompe le chiavi associative)
            unset($lista[$id]);
            salvaDati($lista);
            logAzione('ADMIN_CANCELLA', [
                'id'      => $id,
                'aula'    => $prenotazione['aula']    ?? '',
                'docente' => $prenotazione['docente'] ?? ''
            ]);
            header("Location: index.php?msg=eliminato");
            exit;

        } elseif ($azione === 'modifica') {
            // FIX: aggiorna direttamente per chiave, non per indice numerico
            $lista[$id]['docente']    = htmlspecialchars(strip_tags($_POST['docente']));
            $lista[$id]['ora_inizio'] = $_POST['ora_inizio'];
            $lista[$id]['ora_fine']   = $_POST['ora_fine'];
            $lista[$id]['note']       = htmlspecialchars(strip_tags($_POST['note']));
            $lista[$id]['giorno']     = $_POST['giorno'];
            salvaDati($lista);
            logAzione('ADMIN_MODIFICA', [
                'id'      => $id,
                'aula'    => $lista[$id]['aula']    ?? '',
                'docente' => $lista[$id]['docente'] ?? ''
            ]);
            header("Location: index.php?msg=ok");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione SuperAdmin</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container-form">
        <header class="form-header">
            <h1>Gestione SuperAdmin</h1>
            <p>Modifica o elimina la prenotazione per <strong><?= htmlspecialchars($prenotazione['aula']) ?></strong></p>
        </header>

        <?php if ($errore): ?>
            <div class="alert err-modern"><?= htmlspecialchars($errore) ?></div>
        <?php endif; ?>

        <form method="post" class="form-multi-column">
            <input type="hidden" name="csrf_token" value="<?= generaCSRF() ?>">

            <div class="form-col-details">
                <div class="form-group">
                    <label>Docente</label>
                    <input type="text" name="docente" value="<?= htmlspecialchars($prenotazione['docente']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Giorno</label>
                    <input type="date" name="giorno" value="<?= $prenotazione['giorno'] ?>" required>
                </div>
                <div class="form-group">
                    <label>Orario (Dalle — Alle)</label>
                    <div class="form-row-orari">
                        <input type="time" name="ora_inizio" value="<?= $prenotazione['ora_inizio'] ?>" required>
                        <input type="time" name="ora_fine"   value="<?= $prenotazione['ora_fine']   ?>" required>
                    </div>
                </div>
            </div>

            <div class="form-col-notes">
                <div class="form-group">
                    <label>Note</label>
                    <textarea name="note" rows="5"><?= htmlspecialchars($prenotazione['note']) ?></textarea>
                </div>
                <div class="form-group" style="background:#fff3cd; padding:15px; border-radius:8px; border:1px solid #ffeeba;">
                    <label>Conferma Password Admin</label>
                    <input type="password" name="admin_pass" placeholder="Inserisci Password SuperAdmin" required>
                </div>
            </div>

            <div class="form-actions-row">
                <button type="submit" name="azione" value="modifica" class="btn btn-large" style="background:#007bff; width:100%;">💾 Salva Modifiche</button>
                <button type="submit" name="azione" value="elimina"  class="btn btn-large" style="background:#dc3545; width:100%;" onclick="return confirm('Eliminare definitivamente?')">🗑️ Elimina Prenotazione</button>
                <a href="index.php" class="back-link-modern">Annulla e torna al tabellone</a>
            </div>
        </form>
    </div>
</body>
</html>
