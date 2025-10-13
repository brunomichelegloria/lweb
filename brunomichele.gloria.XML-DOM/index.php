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

        <div id="ops-popover" class="ops-popover">
            <div class="ops-switch">
                <button type="button" id="ops-buy" class="active">Compra</button> <!-- Sostituire i due bottoni con lo switch del
                                                                                 progetto spiaggia/modificheSpiaggia/#modeSwitch --->
                <button type="button" id="ops-sell">Vendi</button>
            </div>
            <div class="ops-row">
                <label>Quantità</label>
                <input id="ops-qty" class="ops-input" type="number" min="1" step="1">
            </div>
            <div class="ops-row" id="ops-price-row">
                <label>Prezzo</label>
                <input id="ops-price" class="ops-input" type="number" step="0.000001" placeholder="0.000000">
            </div>
            <div id="ops-hint"></div>
            <div class="ops-errors" id="ops-errors"></div>
            <div class="ops-actions">
                <button type="button" class="ops-btn" id="ops-submit">Invia</button>
                <button type="button" class="ops-btn secondary" id="ops-close">Chiudi</button>
            </div>
            <div class="ops-actions">
                <button type="button" class="ops-btn secondary" id="ops-cumulate">Cumula</button>
            </div>
        </div>
    </body>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            generaGrafico(parseFloat(document.getElementById('liquidita-totale')?.dataset.liqattuale.replace(',', '.') || 0));


            document.querySelectorAll('.toggle-details-button').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.classList.toggle('rotate-90');

                    const detailsRow = this.closest('tr').nextElementSibling;
                    if (detailsRow && detailsRow.classList.contains('bucket-details')) {
                        detailsRow.style.display = detailsRow.style.display === 'table-row' ? 'none' : 'table-row';
                    }
                });
            });
        });
    </script>

</html>