async function getRealtimePrice(ticker) {
    try {
        const res = await fetch(`fetch_price.php?ticker=${encodeURIComponent(ticker)}`);
        if (!res.ok) throw new Error(await res.text());
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
            throw new Error(`HTTP ${res.status}`);
        }
        const json = await res.json();
        return json.price;
    } catch (e) {
        console.error(`Errore nel recupero prezzo obbligazione ${ticker}:`, e);
        return null;
    }
}

async function aggiornaPrezziDaYahoo() {
    const tab = document.getElementById('tab-portafoglio');
    const symbol = tab?.dataset.symbol || '';
    const tolStr = tab?.dataset.tolleranza;
    const tol = (tolStr !== undefined && tolStr !== '') ? parseFloat(tolStr) : NaN;
    const righe = document.querySelectorAll('table tbody tr[data-ticker]');

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

        if (price != null && isFinite(price)) {
            const valore = qty * price;
            prezzoCell.textContent = symbol ? `${price.toFixed(2)}${symbol}` : price.toFixed(2);
            valoreCell.textContent = symbol ? `${valore.toFixed(2)}${symbol}` : valore.toFixed(2);

            riga.dataset.prezzo = String(price);
            riga.dataset.valore = String(valore);
        } else {
            prezzoCell.textContent = '-';
            valoreCell.textContent = '-';
            riga.dataset.prezzo = '';
            riga.dataset.valore = '';
        }
    }

    // % attuale per riga, e "Conforme" rispetto alla tolleranza
    const rows = Array.from(righe);

    const totaleAsset = rows.reduce((sum, r) => {
        const v = parseFloat(r.dataset.valore || '0');
        return sum + (isFinite(v) ? v : 0);
    }, 0);

    const liqTxt = document.getElementById('liquidita-totale')?.textContent || '0';
    const liqNum = parseFloat(liqTxt.replace(/[^\d,-.]/g,'').replace(/\./g,'').replace(',', '.')) || 0;
    const totaleBase = totaleAsset + liqNum;

    rows.forEach(r => {
        const valore = parseFloat(r.dataset.valore || '0');
        const prezzo = parseFloat(r.dataset.prezzo || '0');
        const targetPct = parseFloat(r.querySelector('.target')?.textContent || '0');

        // % attuale
        const perc = totaleBase > 0 ? (valore / totaleBase) * 100 : 0;
        const attualeCell = r.querySelector('.attuale');
        if (attualeCell) attualeCell.textContent = perc.toFixed(2);

        // Conforme (entro tolleranza?)
        const deltaCell = r.querySelector('.delta-qty');
        if (deltaCell && isFinite(tol)) {
            const desiredValue = (targetPct / 100) * totaleBase;
            const deltaValue = desiredValue - valore;
            const deltaQty = (prezzo > 0 && isFinite(prezzo)) ? (deltaValue / prezzo) : NaN;

            const within = isFinite(perc) && isFinite(targetPct) &&
                            Math.abs(perc - targetPct) <= (isFinite(tol) ? tol : 0);

            const qtyStr = isFinite(deltaQty) ? `${deltaQty >= 0 ? '+' : ''}${deltaQty.toFixed(2)}` : '-';

            deltaCell.textContent = `${within ? 'OK' : 'KO'} Δ${qtyStr}`;
            deltaCell.classList.toggle('ok', within);
            deltaCell.classList.toggle('ko', !within);
        } else if (deltaCell && !isFinite(tol)) {
            deltaCell.textContent = '-';
            deltaCell.classList.remove('ok', 'ko');
            deltaCell.classList.add('muted');
        }
    });

    generaGrafico();
}

function generaGrafico() {
    const righe = document.querySelectorAll('table tr[data-ticker]');
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
        if (attuale > 0) {
            labels.push(nome);
            targets.push(target);
            data.push(attuale);
        }
    });


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

fetch('load.php')
  .then(res => res.text())
  .then(html => {
    document.getElementById('titoli').innerHTML = html;
    aggiornaPrezziDaYahoo();
  });
