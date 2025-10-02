async function getRealtimePrice(ticker) {
    try {
        const res = await fetch(`fetch_price.php?ticker=${encodeURIComponent(ticker)}`);
        if (!res.ok) {
            throw new Error(await res.text());
        }
        const json = await res.json();
        return json.price;
    } catch (e) {
        console.error(`Errore fetching ${ticker}:`, e);
        return null;
    }
}

async function getBondPrice(ticker) {
    try {
        const res = await fetch(`fetch_bond.php?ticker=${encodeURIComponent(ticker)}`);
        if (!res.ok) {
            throw new Error(await res.text());
        }
        const json = await res.json();
        return json.price;
    } catch (e) {
        console.error(`Errore nel recupero prezzo obbligazione ${ticker}:`, e);
        return null;
    }
}

async function aggiornaPrezzi($tabName) {
    const tab = document.getElementById($tabName);
    const righe = document.querySelectorAll('table tbody tr[data-ticker]');

    let totaleAsset = 0;
    const symbol = tab?.dataset.symbol || '';
    const liqTxt = document.getElementById('liquidita-totale')?.textContent || '0';
    const liqNum = parseFloat(liqTxt.replace(/[^\d,-.]/g,'').replace(/\./g,'').replace(',', '.')) || 0;
    const tolleranza = document.getElementById('footer-data')?.dataset.tolleranza || '';
    const commissione = document.getElementById('footer-data')?.dataset.commissione || '';
    let totaleTarget = 0;
    let numUntargeted = 0;

    for (const riga of righe) {
        const tipo = (riga.dataset.type || '').toLowerCase();
        const ticker = riga.dataset.ticker;
        const qty = parseFloat(riga.dataset.quantita || '0');


        let price = null;
        try {
            if (tipo === 'obbligazione') {
                price = await getBondPrice(ticker);      // fetch_bond.php
            } else {
                price = await getRealtimePrice(ticker);  // fetch_price.php
            }
        } catch (e) {
            console.error(`Errore nel recupero del prezzo per ${ticker}:`, e);
            price = null;
        }

        const prezzoCell = riga.querySelector('.prezzo');
        const valoreCell = riga.querySelector('.valore');

        const isBond = (tipo === 'obbligazione');
        const unitPrice = (price != null && isFinite(price))
        ? (isBond ? (price / 100) : price)   // €/€ nominale per bond, €/share per altri
        : NaN;

        if (isFinite(unitPrice)) {
            const valore = qty * unitPrice;      // NB: per bond = qty_nominale * (prezzo_quotato/100)
            totaleAsset += valore;

            // DISPLAY: lasciamo il prezzo "quotato" com'è, come richiesto (con simbolo)
            prezzoCell.textContent = symbol ? `${price.toFixed(2)}${symbol}` : price.toFixed(2);
            valoreCell.textContent = symbol ? `${valore.toFixed(2)}${symbol}` : valore.toFixed(2);

            // CALCOLO: salviamo nei dataset il prezzo per unità di decisione (€/€ per bond, €/share per altri)
            riga.dataset.prezzo = String(unitPrice);
            riga.dataset.valore = String(valore);
        } else {
            prezzoCell.textContent = '-';
            valoreCell.textContent = '-';
            riga.dataset.prezzo = '';
            riga.dataset.valore = '';
        }
        const targetCell = riga.querySelector('.target');
        numUntargeted += (targetCell?.textContent === '-') ? 1 : 0;
        totaleTarget += parseFloat(targetCell?.textContent);
    }

    const untargeted = totaleTarget < 100 ? (100 - totaleTarget)/numUntargeted : 0;

    // % attuale per riga, e "Conforme" rispetto alla tolleranza
    const totaleBase = totaleAsset + liqNum;

    for (const r of righe) {
        const valore = parseFloat(r.dataset.valore || '0');

        // % attuale
        const perc = totaleBase > 0 ? (valore / totaleBase) * 100 : 0;
        const attualeCell = r.querySelector('.attuale');
        if (attualeCell) attualeCell.textContent = perc.toFixed(2);
    }

    generaGrafico(liqNum/totaleBase * 100);
}

function generaGrafico(liqAttualePerc) {
    const righe = document.querySelectorAll('table tr[data-type]');
    const labels = [];
    const targets = [];
    const data = [];
    const colors = ['#4e79a7', '#f28e2b',
        '#e15759', '#76b7b2', '#59a14f', 
        '#edc949', '#af7aa1', '#ff9da7',
        '#9c755f', '#bab0ab'];

    righe.forEach(riga => {
        const nome = riga.querySelector('.nome').textContent;
        const attuale = parseFloat(riga.querySelector('.attuale').textContent);
        const target = parseFloat(riga.querySelector('.target').textContent);
        if (attuale > 0 && target >= 0) {
            labels.push(nome);
            targets.push(target);
            data.push(attuale);
        }
    });
    labels.push('Liquidità');
    targets.push(document.getElementById('liquidita-totale')?.dataset.liqTarget || 0);
    data.push(liqAttualePerc.toFixed(2));


    new Chart(document.getElementById('graph'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                label: 'Target',
                data: targets,
                backgroundColor: colors.slice(0, labels.length),
                weight: 1,
            },{
                label: 'Attuale',
                data: data,
                backgroundColor: colors.slice(0, labels.length),
                weight: 2,
            }]
        },
        options: {
            responsive: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        generateLabels: (chart) => {
                            const labels = chart.data.labels;
                            return labels.map((label, i) => ({
                                text: label,
                                fillStyle: colors[i],
                                strokeStyle: '#fff',
                                lineWidth: 1,
                                index: i,
                                fontColor: 'rgb(255, 255, 255)'
                            }));
                        }
                    },
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `${context.dataset.label}: ${context.parsed}%`;
                        }
                    }
                },
                usePointStyle: true
            },
            cutout: '0%'
        }
    })
}

function bilanciaAssets() {}

fetch('load.php')
  .then(res => res.text())
  .then(html => {
    document.getElementById('titoli').innerHTML = html;
    aggiornaPrezzi('tab-portafoglio');
    bilanciaAssets();
  });