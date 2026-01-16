<?php
session_start();
require_once __DIR__ . '/lib/misc.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$portfolioId = (int)($_GET['id'] ?? 0);
if ($portfolioId <= 0) {
    header('Location: selectPortfolio.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT ID_Portafoglio, Nome, Liquidita, TargetLiquiditaPct, Tolleranza, Commissione, TipoCommissione, ID_Radice
    FROM Portafoglio
    WHERE ID_Portafoglio = ? AND ID_Utente = ?
");
$stmt->execute([$portfolioId, $userId]);
$pf = $stmt->fetch();

if (!$pf) {
    http_response_code(404);
    echo "Portafoglio non trovato";
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= h($_SESSION['username'] ?? 'User') ?> - <?= h($pf['Nome'] ?? 'none') ?></title>

        <link rel="stylesheet" href="portfolio.css">
        <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%3E%3Ctext%20y='.9em'%20font-size='90'%3E🚀%3C/text%3E%3C/svg%3E">

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script defer src="script.js"></script>
    </head>

    <body>
        <header>
            <div class="pf-headerbar">
                <div class="pf-header-left">
                    <a class="pf-header-link" href="selectPortfolio.php">Portafogli</a>
                    <span class="pf-header-sep">/</span>
                    <strong class="pf-header-title"><?= h((string)($pf['Nome'] ?? '')) ?></strong>
                </div>

                <div class="pf-header-right">
                    <span class="pf-header-user">Utente: <?= h($_SESSION['username'] ?? '') ?></span>
                    <a class="pf-header-link" href="logout.php">Logout</a>
                </div>
            </div>
        </header>

        <div id="page">

            <h1 class="pf-title"><?= h((string)($pf['Nome'] ?? '')) ?></h1>
            <p class="pf-subtitle">Gestione portafoglio e ribilanciamento</p>

            <?php if ($flash): ?>
                <div class="pf-flash">
                <?= h((string)($flash['msg'] ?? '')) ?>
                </div>
            <?php endif; ?>

            <div id="tabella-e-grafico">

                <!-- TABELLA -->
                <div class="pf-tablewrap">

                    <table class="bucket-table" id="tab-portafoglio">
                        <thead>
                        <tr>
                            <th class="col-icon"></th>
                            <th>Tipo</th>
                            <th>Nome</th>
                            <th>Qty</th>
                            <th>Prezzo</th>
                            <th>Valore</th>
                            <th>Attuale %</th>
                            <th>Target %</th>
                            <th>Δ Qty</th>
                        </tr>
                        </thead>

                        <tbody id="bucket-tbody">
                        <tr>
                            <td class="pf-empty" colspan="9">
                            Tabella vuota (in attesa dei dati dal database).
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <tfoot>
                        <tr>
                            <td id="footer-data" colspan="9">
                                <div class="pf-tablefooter">
                                    <span id="liquidita-totale" data-liq-target="<?= h((string)$pf['TargetLiquiditaPct']) ?>">
                                        Liquidità: <strong><?= h(number_format((float)$pf['Liquidita'], 2, ',', '.')) ?> €</strong>
                                    </span>
                                    <span class="pf-footer-sep">|</span>
                                    <span>Target liquidità: <strong><?= h(number_format((float)$pf['TargetLiquiditaPct'], 3, ',', '.')) ?>%</strong></span>
                                    <span class="pf-footer-sep">|</span>
                                    <span>Tolleranza: <strong><?= h(number_format((float)$pf['Tolleranza'], 3, ',', '.')) ?>%</strong></span>
                                    <span class="pf-footer-sep">|</span>
                                    <span>
                                        Commissione:
                                        <strong><?= h(number_format((float)($pf['Commissione'] ?? 0), 4, ',', '.')) ?></strong>
                                        (<?= h((string)$pf['TipoCommissione']) ?>)
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tfoot>

                    <div class="ops-forms-container">
                        <div class="helper-form">
                            <div id="cumulBox">
                                <button id="cumul-btn" class="btn-mini" type="button">Cumula operazioni</button>
                                <button class="help-btn btn-mini" type="button" title="Aiuto">?</button>
                            </div>
                            <div class="help-text">Cumula BUY/SELL per ottenere la quantità attuale.</div>
                        </div>

                        <div class="helper-form">
                            <button id="rebalance-btn" class="btn-mini" type="button">Ribilancia portafoglio</button>
                            <div class="help-text">Calcola le quote da comprare/vendere per raggiungere i target.</div>
                        </div>
                    </div>
                </div>

                <div id="graph-wrap">
                    <canvas id="graph" width="300" height="300"></canvas>
                </div>

            </div>
        </div>

        <div id="ops-popover">
            <div><strong>Operazione</strong></div>

            <div id="ops-form">
                <div class="ops-switch">
                    <label class="switch">
                        <input type="checkbox" id="ops-is-sell">
                        <span class="slider"></span>
                    </label>
                    <span id="ops-type-label">BUY</span>
                </div>

                <div>
                    <label>Qty</label>
                    <input id="ops-qty" type="number" step="0.000001">
                </div>

                <div>
                    <label>Prezzo</label>
                    <input id="ops-price" type="number" step="0.000001">
                </div>

                <div>
                    <button id="ops-submit" class="btn-mini" type="button">Salva</button>
                </div>
            </div>
        </div>

        <dialog id="asset-dialog">
            <form id="asset-form" method="dialog">
                <div class="dialog-body">
                    <h3 class="pf-dialog-title">Gestione portafoglio</h3>

                    <div class="asset-field">
                        <label>Liquidità</label>
                        <input type="number" step="0.01" value="<?= h((string)$pf['Liquidita']) ?>">
                    </div>

                    <div class="asset-field">
                        <label>Target liquidità (%)</label>
                        <input type="number" step="0.001" value="<?= h((string)$pf['TargetLiquiditaPct']) ?>">
                    </div>

                    <div class="asset-field">
                        <label>Tolleranza (%)</label>
                        <input type="number" step="0.001" value="<?= h((string)$pf['Tolleranza']) ?>">
                    </div>

                    <div class="asset-field">
                        <label>Commissione</label>
                        <input type="number" step="0.0001" value="<?= h((string)($pf['Commissione'] ?? 0)) ?>">
                    </div>

                    <hr>

                    <p class="pf-dialog-hint">Qui inseriremo l’editor di bucket e asset (come nella versione originale).</p>
                </div>

                <div class="dialog-footer">
                    <div>
                        <button class="btn-mini" type="button" id="add-bucket">+ Bucket</button>
                        <button class="btn-mini" type="button" id="add-azione">+ Azione</button>
                        <button class="btn-mini" type="button" id="add-etf">+ ETF</button>
                        <button class="btn-mini" type="button" id="add-obbl">+ Obbligazione</button>
                    </div>
                    <div>
                        <button class="btn-mini" value="cancel">Annulla</button>
                        <button class="btn-mini" value="default">Invia</button>
                    </div>
                </div>
            </form>
        </dialog>

    </body>
</html>