<!doctype html>

<?php 
session_start();

$priceCache = $_SESSION['priceCache'] ?? [];

$BASE_DIR  = __DIR__ . '/data';
$BASE_REAL = realpath($BASE_DIR);

if(!($rel = $_SESSION['selectedPortfolio'] ?? '')) {
    header('Location: index.php', true, 302);
    exit;
}
$rel = ltrim($rel, '/');

$path = realpath($BASE_DIR . '/' . $rel);
if (!$path || !str_starts_with($path, $BASE_REAL)) {
    header('Location: index.php', true, 404);
    exit;
}

$selectedPortfolio = $rel;
?>

<html lang="it">
    <head>
        <meta charset="utf-8">
        <title>Portafoglio</title>
        <link rel="stylesheet" href="style.css" type="text/css">
        <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%3E%3Ctext%20y='.9em'%20font-size='90'%3E🚀%3C/text%3E%3C/svg%3E">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="script.js"></script>
    </head>

    <body>
        <header>
            <a href="index.php">🏠 Portafogli</a>
        </header>
        <div id="page">
        <h1><?php echo htmlspecialchars(pathinfo($selectedPortfolio, PATHINFO_FILENAME)) ?></h1>
        <div id="tabella-e-grafico">
            <div id="titoli">
                
                <?php include __DIR__ . '/load.php'; ?>
            </div>

            <canvas id="graph" width="400" height="400"></canvas>
        </div>

        <div id="ops-popover" class="toggable-menu">
            <div class="ops-switch">
                <span id="modeLabel">Acquisto</span>
                <label class="switch">
                    <input type="checkbox" id="modeSwitch">
                    <span class="slider"></span>
                </label>
            </div>
            <form id="ops-form" action="lib/addOps.php" method="POST">
                <input type="hidden" name="portfolio" value='<?php echo htmlspecialchars($selectedPortfolio); ?>'>
                <input type="hidden" name="path" id="op-path" value="">
                <input type="hidden" name="type" id="op-type" value="buy">
                <div class="ops-row">
                    <label>Quantità</label>
                    <input id="op-qty" name="qty" type="number" min="1" step="1">
                </div>
                <div class="ops-row" id="ops-price-row">
                    <label>Prezzo</label>
                    <input id="op-price" name="price" class="ops-input" type="number" min="0" step="0.000001" placeholder="0.000000">
                </div>
                <div id="ops-hint"></div>
                <div class="ops-actions">
                    <button type="submit" class="ops-btn" id="ops-submit">Invia</button>
                </div>
            </form>
        </div>
        <div class="ops-forms-container">
            <form id="cumulForm" class="helper-form" action="lib/addOps.php" method="POST" onsubmit="return confirm('Lo storico delle operazioni verrà perso. Confermi l\'operazione?')">
                <input type="hidden" name="portfolio" value='<?php echo htmlspecialchars($selectedPortfolio); ?>'>
                <input type="hidden" name="type" value="cumulate">
                <div id="cumulBox">
                    <button id="cumul-btn" class="ops-btn" type="submit">Cumula operazioni</button>
                    <button id="cumul-help" class="cumul-meta-help help-btn" type="button">?</button>
                </div>
                <p class="cumul-meta-help help-text">
                    <br>Cumula operazioni" trasforma lo storico delle operazioni effettuate
                    <br>su ogni asset in un'unica operazione datata ad oggi con quantità e prezzo
                    <br>di acquisto tali da mantenere equivalente l'allocazione in portafoglio e
                    <br>la tassazione in fase di vendita.
                </p>
            </form>
            <form id="rebalanceForm" class="helper-form" action="gestionalePortafoglio.php" method="GET" onsubmit="return confirm('Confermi di voler ribilanciare il portafoglio in base ai target impostati?\nL\'azione potrebbe richedere del tempo variabile, dipendentemente dal numero di asset in portafoglio.')">
                <input type="hidden" name="rebalance" value='true'>
                <div id="rebalanceBox">
                    <button id="rebalance-btn" class="ops-btn" type="submit">Ribilancia portafoglio</button>
                    <button id="rebalance-help" class="rebalance-meta-help help-btn" type="button">?</button>
                </div>
                <p class="rebalance-meta-help help-text">
                    <br>Ribilancia portafoglio" simulando operazioni di acquisto e vendita
                    <br>per trovare un allocazione del portafoglio più vicina ai
                    <br>target percentuali impostati su ogni asset.
                    <br>(Utilizzabile anche per decidere come investire nuova liquidit&agrave;.)
                </p>
            </form>
        </div>

        <dialog id="asset-dialog" class="toggable-menu" data-counter="0">
            <form id="asset-form" method="POST" action="lib/modifyAssets.php">
                <input type="hidden" name="path" id="asset-dialog-path" value="">
                <input type="hidden" name="portfolio" value='<?php echo htmlspecialchars($selectedPortfolio); ?>'>
                <div class="dialog-body"></div>
                <div class="dialog-footer">
                    <div class="footer-left">
                        <button type="button" id="assetAddBucket">+ Bucket</button>
                        <button type="button" id="assetAddAzione">+ Azione</button>
                        <button type="button" id="assetAddEtf">+ ETF</button>
                        <button type="button" id="assetAddObb">+ Obbligazione</button>
                    </div>
                    <div class="footer-right">
                        <button type="button" id="btn-cancel">Annulla</button>
                        <button type="submit">Invia</button>
                    </div>
                </div>
            </form>
        </dialog>

        <template id="template-portfolio-info">
            <fieldset id="portfolio-info-fieldset" class="asset-field">
                <legend>Informazioni Portafoglio</legend>
                <div class="template-div">
                    <label for="liquidita-form">Liquidit&agrave;:<input type="number" id="liquidita-form" name="assets[info][liquidita]" disabled></label>
                    <label for="liq-target-form">Target liquidit&agrave; (%):<input type="number" id="liq-target-form" name="assets[info][liq-target]" min="0" max="100" disabled></label>
                    <br>
                    <label for="tolleranza-form">Tolleranza (%):<input type="number" id="tolleranza-form" name="assets[info][tolleranza]" min="0" max="100" disabled></label>
                    <label for="commissione-form">Commissione:<input type="number" id="commissione-form" name="assets[info][commissione]" min="0" disabled></label>
                    <label for="default-currency-form">Valuta:<select id="default-currency-form" name="assets[info][valuta]" disabled>
                                <option>€</option>
                                <option>$</option>
                                <option>£</option>
                        </select>
                    </label>
                </div>
                <button type="button" class="asset-edit" title="Modifica">✎</button>
            </fieldset>
        </template>
        <template id="template-bucket">
            <fieldset class="asset-field">
                <div class="template-div">
                    <p>Bucket</p>
                    <div class="template-bucket-main">
                        <label for="assets[__ID__][nome]">Nome:<input name="assets[__ID__][nome]" disabled required></label>
                        <label for="assets[__ID__][target]">Target:<input type="number" min="0" max="100" name="assets[__ID__][target]" disabled></label>
                        <label>Valuta:<select name="assets[__ID__][valuta]">
                                <option>€</option>
                                <option>$</option>
                                <option>£</option>
                            </select>
                        </label>
                        <input type="hidden" name="assets[__ID__][tipo]" value="bucket" disabled>
                        <input type="hidden" name="new[__ID__]" value="0" disabled>
                        <input type="hidden" name="remove[__ID__]" value="0" disabled>
                    </div>
                </div>
                <div>
                        <button type="button" class="asset-edit" title="Modifica">✎</button>
                        <button type="button" class="asset-remove">⌫</button>
                    </div>
            </fieldset>
        </template>
        <template id="template-azione">
            <fieldset class="asset-field">
                <div class="template-div">
                    <p>Azione</p>
                    <div class="template-azione-main">
                        <label for="assets[__ID__][ticker]">Ticker:<input name="assets[__ID__][ticker]" required disabled></label>
                        <label for="assets[__ID__][nome]">Nome:<input name="assets[__ID__][nome]" disabled></label>
                        <label for="assets[__ID__][target]">Target:<input type="number" min="0" max="100" name="assets[__ID__][target]" disabled></label>
                        <input type="hidden" name="assets[__ID__][tipo]" value="azione" disabled>
                        <input type="hidden" name="new[__ID__]" value="0" disabled>
                        <input type="hidden" name="remove[__ID__]" value="0" disabled>
                    </div>
                    <details class="template-azione-details">
                        <summary>Dettagli</summary>
                        <label for="assets[__ID__][taxRate]">Tax rate:<input min="0" max="100" name="assets[__ID__][taxRate]" disabled></label>
                        <label>Valuta:<select name="assets[__ID__][valuta]">
                                <option>€</option>
                                <option>$</option>
                                <option>£</option>
                            </select>
                        </label>
                    </details>
                </div>
                <div>
                    <button type="button" class="asset-edit" title="Modifica">✎</button>
                    <button type="button" class="asset-remove">⌫</button>
                </div>
            </fieldset>
        </template>
        <template id="template-etf">
            <fieldset class="asset-field">
                <div class="template-div">
                    <p>ETF</p>
                    <div class="template-etf-main">
                        <label for="assets[__ID__][ticker]">Ticker:<input name="assets[__ID__][ticker]" required disabled placeholder="ACWI.MI (MI = borsa di scambio)"></label>
                        <label for="assets[__ID__][nome]">Nome:<input name="assets[__ID__][nome]" disabled></label>
                        <label for="assets[__ID__][target]">Target:<input type="number" min="0" max="100" name="assets[__ID__][target]" disabled></label>
                        <input type="hidden" name="assets[__ID__][tipo]" value="etf" disabled>
                        <input type="hidden" name="new[__ID__]" value="0" disabled>
                        <input type="hidden" name="remove[__ID__]" value="0" disabled>
                    </div>
                    <details class="template-etf-details">
                        <summary>Dettagli</summary>
                        <label for="assets[__ID__][taxRate]">Tax rate:<input min="0" max="100" name="assets[__ID__][taxRate]" disabled></label>
                        <label>Valuta:<select name="assets[__ID__][valuta]">
                            <option>€</option>
                            <option>$</option>
                            <option>£</option>
                        </select></label>
                    </details>
                </div>
                <div>
                    <button type="button" class="asset-edit" title="Modifica">✎</button>
                    <button type="button" class="asset-remove">⌫</button>
                </div>
            </fieldset>
        </template>
        <template id="template-obbligazione">
            <fieldset class="asset-field">
                <div class="template-div">
                    <p>Obbligazione</p>
                    <div class="template-obbligazione-main">
                        <label for="assets[__ID__][ticker]">ISIN:<input name="assets[__ID__][ticker]" required disabled></label>
                        <label for="assets[__ID__][nome]">Nome:<input name="assets[__ID__][nome]" disabled></label>
                        <label for="assets[__ID__][target]">Target:<input type="number" min="0" max="100" name="assets[__ID__][target]" disabled></label>
                        <input type="hidden" name="assets[__ID__][tipo]" value="obbligazione" disabled>
                        <input type="hidden" name="new[__ID__]" value="0" disabled>
                        <input type="hidden" name="remove[__ID__]" value="0" disabled>
                    </div>
                    <details class="template-obbligazione-details">
                        <summary>Dettagli</summary>
                        <label for="assets[__ID__][taxRate]">Tax rate:<input min="0" max="100" name="assets[__ID__][taxRate]" disabled></label>
                        <label>Valuta:<select name="assets[__ID__][valuta]">
                                <option>€</option>
                                <option>$</option>
                                <option>£</option>
                            </select>
                        </label>
                        <label for="assets[__ID__][cedola]">Cedola:<input name="assets[__ID__][cedola]" type="number" disabled></label>
                        <br>
                        <label for="assets[__ID__][fcedola]">Frequenza cedola:<input name="assets[__ID__][fcedola]" disabled></label>
                        <label for="assets[__ID__][scadenza]">Scadenza:<input name="assets[__ID__][scadenza]" type="date" disabled></label>
                    </details>
                </div>
                <div>
                    <button type="button" class="asset-edit" title="Modifica">✎</button>
                    <button type="button" class="asset-remove">⌫</button>
                </div>
            </fieldset>
        </template>
        <?php if (!empty($_GET['err'])): ?>
            <div class="ops-errors"><?= '⚠️' . htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8') . '⚠️' ?></div>
            <script>
                history.replaceState(null, '', location.pathname + location.hash + location.search.replace(/(\?|&)err=[^&]*/g, ''));
            </script>
        <?php endif; ?>
        </div>
    </body>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            generaGrafico(parseFloat(document.getElementById('liquidita-totale')?.dataset.liqattuale.replace(',', '.') || 0));

            setupEventListeners();
        });
    </script>

</html>