<!doctype html>
<html lang="it">
    <head>
        <meta charset="utf-8" />
        <title>Portafoglio</title>
        <link rel="stylesheet" href="style.css" type="text/css" />
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚀</text></svg>">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="script.js"></script>
    </head>

    <body>
        <div id="tabella-e-grafico">
            <div id="titoli">
                <h1>Portafoglio Titoli</h1>
                <?php include __DIR__ . '/load.php'; ?>
            </div>

            <canvas id="graph" width="400" height="400"></canvas>
        </div>

        <div id="ops-popover">
            <div class="ops-switch">
                <span id="modeLabel">Acquisto</span>
                <label class="switch">
                    <input type="checkbox" id="modeSwitch">
                    <span class="slider"></span>
                </label>
            </div>
            <form id="ops-form" action="lib/addOps.php" method="POST">
                <input type="hidden" name="path" id="op-path" value="">
                <input type="hidden" name="type" id="op-type" value="buy">
                <div class="ops-row">
                    <label>Quantità</label>
                    <input id="op-qty" name="qty" type="number" min="1" step="1">
                </div>
                <div class="ops-row" id="ops-price-row">
                    <label>Prezzo</label>
                    <input id="op-price" class="ops-input" type="number" step="0.000001" placeholder="0.000000">
                </div>
                <div id="ops-hint"></div>
                <div class="ops-actions">
                    <button type="submit" class="ops-btn" id="ops-submit">Invia</button>
                </div>
            </form>
        </div>
        <div class="ops-errors" id="ops-errors"></div>
    </body>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            generaGrafico(parseFloat(document.getElementById('liquidita-totale')?.dataset.liqattuale.replace(',', '.') || 0));

            setupEventListeners();
        });
    </script>

</html>