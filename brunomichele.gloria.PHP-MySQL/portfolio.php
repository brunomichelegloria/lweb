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
    SELECT ID_Portafoglio, Nome, Valuta, Liquidita, TargetLiquiditaPct, Tolleranza, Commissione, TipoCommissione, ID_Radice
    FROM Portafoglio
    WHERE ID_Portafoglio = ? AND ID_Utente = ?
");
$stmt->execute([$portfolioId, $userId]);
$pf = $stmt->fetch();
$valuta = $pf['Valuta'] ?? 'EUR';

if (!$pf) {
    http_response_code(404);
    echo "Portafoglio non trovato";
    exit;
}

$rootBucketId = (int)$pf['ID_Radice'];

$bucketById = [];
$childrenMap = [];

$stmtRoot = $pdo->prepare("SELECT ID_Bucket, ID_Padre, Nome, TargetPctSuPadre FROM Bucket WHERE ID_Bucket = ?");
$stmtRoot->execute([$rootBucketId]);
$rootRow = $stmtRoot->fetch();

if ($rootRow) {
    $queue = [$rootBucketId];

    $bucketById[$rootBucketId] = [
        'ID_Bucket' => (int)$rootRow['ID_Bucket'],
        'ID_Padre' => $rootRow['ID_Padre'] !== null ? (int)$rootRow['ID_Padre'] : null,
        'Nome' => (string)$rootRow['Nome'],
        'TargetPctSuPadre' => $rootRow['TargetPctSuPadre'] !== null ? (float)$rootRow['TargetPctSuPadre'] : null,
    ];

    while (!empty($queue)) {
        $batch = array_splice($queue, 0, 50);

        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $sql = "SELECT ID_Bucket, ID_Padre, Nome, TargetPctSuPadre
                FROM Bucket
                WHERE ID_Padre IN ($placeholders)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($batch);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            $idB = (int)$r['ID_Bucket'];
            $idP = $r['ID_Padre'] !== null ? (int)$r['ID_Padre'] : null;

            if (isset($bucketById[$idB])) {
                continue;
            }

            $bucketById[$idB] = [
                'ID_Bucket' => $idB,
                'ID_Padre' => $idP,
                'Nome' => (string)$r['Nome'],
                'TargetPctSuPadre' => $r['TargetPctSuPadre'] !== null ? (float)$r['TargetPctSuPadre'] : null,
            ];

            if (!isset($childrenMap[$idP])) $childrenMap[$idP] = [];
            $childrenMap[$idP][] = $idB;

            $queue[] = $idB;
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashTitle = $flash['title'] ?? null;
$flashDetails = $flash['details'] ?? [];
$flashCode = (int)($flash['code'] ?? 20);
if (!is_array($flashDetails)) $flashDetails = [];
?>
<!doctype html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= h($_SESSION['username'] ?? 'User') ?> - <?= h($pf['Nome'] ?? 'none') ?></title>

        <link rel="stylesheet" href="portfolio.css">
        <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%3E%3Ctext%20y='.9em'%20font-size='90'%3E🚀%3C/text%3E%3C/svg%3E">

        <script src=" https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js "></script>
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

            <?php if ($flashTitle): ?>
                <div class="pf-flash <?= $flashCode === 1 ? 'pf-flash-error' : ($flashCode === 2 ? 'pf-flash-warn' : 'pf-flash-ok') ?>">
                    <?= h((string)$flashTitle) ?>
                </div>
            <?php endif; ?>

            <div id="tabella-e-grafico">

                <div class="pf-tablewrap">

                    <table class="bucket-table" id="tab-portafoglio">
                        <thead>
                            <tr>
                                <th class="col-icon"><button type="button" class="edit-button" data-open-assets data-bucket-id="<?= (int)$rootBucketId ?>">🛠️</button></th>
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
                        <?php
                            	$pdo = getPDO();

                                [$tbodyHtml, $totalSum] = renderRootChildrenDB($pdo, $rootBucketId, $bucketById, $childrenMap, $pf['Liquidita'] ?? 0.0);

                                if ($tbodyHtml === '') {
                                    echo '<tr><td class="pf-empty" colspan="9">Portafoglio vuoto.</td></tr>';
                                } else {
                                    echo $tbodyHtml;
                                }
                        ?>
                        </tbody>

                        
                        <tfoot>
                            <tr>
                                <td id="footer-data" colspan="9">
                                    <div class="pf-tablefooter">
                                        <?php
                                        $liqAttuale = (float)($pf['Liquidita'] ?? 0);
                                        $liqPerc = ($totalSum > 0.0) ? ($liqAttuale / ($totalSum + $liqAttuale)) * 100.0 : 0.0;
                                        ?>
                                        <span id="liquidita-totale" data-liq-target="<?= h((string)$pf['TargetLiquiditaPct']) ?>" data-liq-perc="<?= h((string)number_format((float)$liqPerc, 2, '.', '')) ?>">
                                            Liquidità: <strong><?= h(number_format((float)$pf['Liquidita'], 2, ',', '.')) . (valutaToSimbolo($valuta) ?? '€') ?></strong>
                                        </span>
                                        <span class="pf-footer-sep">|</span>
                                        <span>Target liquidità: <strong><?= h(preg_replace('/[,.][0]+$/', '', number_format((float)$pf['TargetLiquiditaPct'], 3, ',', '.'))) ?>%</strong></span>
                                        <span class="pf-footer-sep">|</span>
                                        <span>Tolleranza: <strong><?= h(preg_replace('/[,.][0]+$/', '', number_format((float)$pf['Tolleranza'], 3, ',', '.'))) ?>%</strong></span>
                                        <span class="pf-footer-sep">|</span>
                                        <span>
                                            Commissione:
                                            <strong><?= h(preg_replace('/[,.][0]+$/', '', number_format((float)($pf['Commissione'] ?? 0), 4, ',', '.'))) . (valutaToSimbolo($valuta) ?? '€') ?></strong>
                                            (<?= h((string)$pf['TipoCommissione']) ?>)
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="ops-forms-container">
                        <form id="cumulForm" class="helper-form" action="lib/cumula.php" method="POST" onsubmit="return confirm('Lo storico delle operazioni verrà perso. Confermi l\'operazione?')">
                            <input type="hidden" name="portfolio_id" value="<?= h($portfolioId) ?>">
                            <div id="cumulBox">
                                <button id="cumul-btn" class="btn-mini" type="submit">Cumula operazioni</button>
                                <button class="cumul-meta-help btn-mini help-btn" type="button" title="Aiuto">?</button>
                            </div>
                            <p class="cumul-meta-help help-text">
                                <br>Cumula operazioni" trasforma lo storico delle operazioni effettuate
                                <br>su ogni asset in un'unica operazione datata ad oggi con quantità e prezzo
                                <br>di acquisto tali da mantenere equivalente l'allocazione in portafoglio e
                                <br>la tassazione in fase di vendita.
                            </p>
                        </form>

                        <form id="rebalanceForm" class="helper-form" action="lib/rebalance.php" method="GET" onsubmit="return confirm('Confermi di voler ribilanciare il portafoglio in base ai target impostati?\nL\'azione potrebbe richedere del tempo variabile, dipendentemente dal numero di asset in portafoglio.')">
                            <input type="hidden" name="portfolio_id" value="<?= h($portfolioId) ?>">
                            <div id="rebalanceBox">
                                <button id="rebalance-btn" class="btn-mini" type="submit">Ribilancia portafoglio</button>
                                <button id="rebalance-help" class="rebalance-meta-help btn-mini help-btn" type="button">?</button>
                            </div>
                            <p class="rebalance-meta-help help-text">
                                <br>Ribilancia portafoglio" simulando operazioni di acquisto e vendita
                                <br>per trovare un allocazione del portafoglio più vicina ai
                                <br>target percentuali impostati su ogni asset.
                                <br>(Utilizzabile anche per decidere come investire nuova liquidit&agrave;.)
                            </p>
                        </form>
                    </div>
                    
                    <?php if (!empty($flashDetails)): ?>
                        <div class="pf-flash-details">
                            <?php foreach ($flashDetails as $line): ?>
                                <div class="pf-flash-line"><?= h((string)$line) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
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
                    <span id="ops-type-label">BUY</span>
                    <label class="switch">
                        <input type="checkbox" id="ops-is-sell">
                        <span class="slider"></span>
                    </label>  
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

        <dialog id="asset-dialog" class="toggable-menu" data-counter="0">
            <form id="asset-form" method="POST" action="lib/modificaBucket.php">
                <input type="hidden" name="portfolioId" id="asset-dialog-portfolioId" value="<?= (int)$pf['ID_Portafoglio'] ?>">
                <input type="hidden" name="scopeBucketId" id="asset-dialog-scopeBucketId" value="">
                <input type="hidden" name="mode" id="asset-dialog-mode" value="bucket">

                <div class="dialog-body"></div>

                <div class="dialog-footer">
                    <div class="footer-left">
                        <button type="button" id="assetAddBucket" class="btn-mini">+ Bucket</button>
                        <button type="button" id="assetAddAzione" class="btn-mini">+ Azione</button>
                        <button type="button" id="assetAddEtf" class="btn-mini">+ ETF</button>
                        <button type="button" id="assetAddObb" class="btn-mini">+ Obbligazione</button>
                    </div>
                    <div class="footer-right">
                        <button type="button" id="btn-cancel" class="btn-mini">Annulla</button>
                        <button type="submit" class="btn-mini">Invia</button>
                    </div>
                </div>
            </form>
        </dialog>

        <template id="template-portfolio-info">
            <fieldset class="asset-field" data-kind="portfolio">
                <legend>Informazioni Portafoglio</legend>

                <div class="template-div">
                    <label>Liquidità:
                        <input type="number" step="0.01" name="assets[info][Liquidita]" disabled>
                    </label>

                    <label>Target liquidità (%):
                        <input type="number" step="0.001" min="0" max="100" name="assets[info][TargetLiquiditaPct]" disabled>
                    </label>

                    <label>Tolleranza (%):
                        <input type="number" step="0.001" min="0" max="100" name="assets[info][Tolleranza]" disabled>
                    </label>

                    <label>Commissione:
                        <input type="number" step="0.0001" min="0" name="assets[info][Commissione]" disabled>
                    </label>

                    <label>Tipo commissione:
                        <select name="assets[info][TipoCommissione]" disabled>
                            <option value="Fissa">Fissa</option>
                            <option value="Percentuale">Percentuale</option>
                        </select>
                    </label>

                    <label>Valuta:
                        <input type="text" name="assets[info][Valuta]" disabled>
                    </label>
                </div>

                <div class="asset-actions">
                    <button type="button" class="asset-edit btn-mini" title="Modifica">✎</button>
                </div>
            </fieldset>
        </template>

        <template id="template-bucket">
            <fieldset class="asset-field" data-kind="bucket">
                <div class="template-div">
                    <p>Bucket</p>

                    <div class="template-bucket-main">
                        <label>Nome:
                            <input name="assets[__ID__][Nome]" disabled required>
                        </label>

                        <label>Target:
                            <input type="number" step="0.01" min="0" max="100" name="assets[__ID__][TargetPctSuPadre]" disabled>
                        </label>

                        <input type="hidden" name="assets[__ID__][tipo]" value="bucket" disabled>
                        <input type="hidden" name="assets[__ID__][new]" value="0" disabled>
                        <input type="hidden" name="assets[__ID__][remove]" value="0" disabled>
                        <input type="hidden" name="assets[__ID__][ID_Bucket]" value="" disabled>
                    </div>
                </div>

                <div class="asset-actions">
                    <button type="button" class="asset-edit btn-mini" title="Modifica">✎</button>
                    <button type="button" class="asset-remove btn-mini btn-danger">⌫</button>
                </div>
            </fieldset>
        </template>
        
        <template id="template-azione">
            <fieldset class="asset-field" data-kind="azione">
                <div class="template-div">
                    <p>Azione</p>

                    <div class="template-azione-main">
                        <label>ISIN:
                            <input name="assets[__ID__][ISIN]" required disabled>
                        </label>

                        <label>Ticker:
                            <input name="assets[__ID__][Ticker]" required disabled placeholder="AAPL">
                        </label>

                        <label>Nome:
                            <input name="assets[__ID__][Nome]" disabled>
                        </label>

                        <label>Target:
                            <input type="number" step="0.01" min="0" max="100" name="assets[__ID__][TargetPctNelBucket]" disabled>
                        </label>

                        <input type="hidden" name="assets[__ID__][tipo]" value="Azione" disabled>
                        <input type="hidden" name="assets[__ID__][new]" value="0" disabled>
                        <input type="hidden" name="assets[__ID__][remove]" value="0" disabled>
                        <input type="hidden" name="assets[__ID__][ID_Bucket]" value="" disabled>
                    </div>

                    <details class="template-azione-details">
                        <summary>Dettagli</summary>

                        <label>Tax rate (%):
                            <input type="number" step="0.01" min="0" max="100" name="assets[__ID__][TaxRate]" disabled>
                        </label>

                        <label>Valuta:
                            <input type="text" name="assets[__ID__][Valuta]" disabled>
                        </label>
                    </details>
                </div>

                <div class="asset-actions">
                    <button type="button" class="asset-edit btn-mini" title="Modifica">✎</button>
                    <button type="button" class="asset-remove btn-mini btn-danger">⌫</button>
                </div>
            </fieldset>
        </template>

        <template id="template-etf">
            <fieldset class="asset-field" data-kind="etf">
                <div class="template-div">
                    <p>ETF</p>

                    <div class="template-etf-main">
                        <label>Ticker:
                            <input name="assets[__ID__][Ticker]" required disabled placeholder="ACWI.MI">
                        </label>

                        <label>ISIN:
                            <input name="assets[__ID__][ISIN]" required disabled>
                        </label>

                        <label>Nome:
                            <input name="assets[__ID__][Nome]" disabled>
                        </label>

                        <label>Target:
                            <input type="number" step="0.01" min="0" max="100" name="assets[__ID__][TargetPctNelBucket]" disabled>
                        </label>

                        <input type="hidden" name="assets[__ID__][tipo]" value="ETF" disabled>
                        <input type="hidden" name="assets[__ID__][new]" value="0" disabled>
                        <input type="hidden" name="assets[__ID__][remove]" value="0" disabled>
                        <input type="hidden" name="assets[__ID__][ID_Bucket]" value="" disabled>
                    </div>

                    <details class="template-etf-details">
                        <summary>Dettagli</summary>

                        <label>ISIN:
                            <input name="assets[__ID__][ISIN_DETAILS]" disabled>
                        </label>

                        <label>Tax rate (%):
                            <input type="number" step="0.01" min="0" max="100" name="assets[__ID__][TaxRate]" disabled>
                        </label>

                        <label>Valuta:
                            <input type="text" name="assets[__ID__][Valuta]" disabled>
                        </label>

                        <label>TER:
                            <input type="number" step="0.0001" name="assets[__ID__][TER]" disabled>
                        </label>

                        <label>Distribuzione:
                            <select name="assets[__ID__][Distribuzione]" disabled>
                                <option value="Accumulating">Accumulating</option>
                                <option value="Distributing">Distributing</option>
                            </select>
                        </label>

                        <label>Indice:
                            <input type="text" name="assets[__ID__][Indice]" disabled>
                        </label>
                    </details>
                </div>

                <div class="asset-actions">
                    <button type="button" class="asset-edit btn-mini" title="Modifica">✎</button>
                    <button type="button" class="asset-remove btn-mini btn-danger">⌫</button>
                </div>
            </fieldset>
        </template>

        <template id="template-obbligazione">
            <fieldset class="asset-field" data-kind="obbligazione">
                <div class="template-div">
                    <p>Obbligazione</p>

                    <div class="template-obbligazione-main">
                        <label>ISIN:
                            <input name="assets[__ID__][ISIN]" required disabled>
                        </label>

                        <label>Nome:
                            <input name="assets[__ID__][Nome]" disabled>
                        </label>

                        <label>Target:
                            <input type="number" step="0.01" min="0" max="100" name="assets[__ID__][TargetPctNelBucket]" disabled>
                        </label>

                        <input type="hidden" name="assets[__ID__][tipo]" value="Obbligazione" disabled>
                        <input type="hidden" name="assets[__ID__][new]" value="0" disabled>
                        <input type="hidden" name="assets[__ID__][remove]" value="0" disabled>
                        <input type="hidden" name="assets[__ID__][ID_Bucket]" value="" disabled>
                    </div>

                    <details class="template-obbligazione-details">
                        <summary>Dettagli</summary>

                        <label>Tax rate (%):
                            <input type="number" step="0.01" min="0" max="100" name="assets[__ID__][TaxRate]" disabled>
                        </label>

                        <label>Valuta:
                            <input type="text" name="assets[__ID__][Valuta]" disabled>
                        </label>

                        <label>Cedola (%):
                            <input type="number" step="0.0001" name="assets[__ID__][CedolaPct]" disabled>
                        </label>

                        <label>Frequenza cedola:
                            <select name="assets[__ID__][FrequenzaCedola]" disabled>
                                <option value=""></option>
                                <option value="Annuale">Annuale</option>
                                <option value="Semestrale">Semestrale</option>
                                <option value="Triennale">Triennale</option>
                                <option value="Mensile">Mensile</option>
                            </select>
                        </label>

                        <label>Scadenza:
                            <input type="date" name="assets[__ID__][Scadenza]" disabled>
                        </label>
                    </details>
                </div>

                <div class="asset-actions">
                    <button type="button" class="asset-edit btn-mini" title="Modifica">✎</button>
                    <button type="button" class="asset-remove btn-mini btn-danger">⌫</button>
                </div>
            </fieldset>
        </template>
    
    </body>
</html>