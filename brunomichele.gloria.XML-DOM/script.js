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

async function aggiornaPrezziDaYahoo() {
    const tab = document.getElementById('tab-portafoglio');
    const symbol = tab?.dataset.symbol || '';
    const tolStr = tab?.dataset.tolleranza;
    const tol = (tolStr !== undefined && tolStr !== '') ? parseFloat(tolStr) : NaN;
    const fee = parseFloat(tab?.dataset.commissione || '0') || 0;
    const allowFundingSell = true;
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

        const isBond = (tipo === 'obbligazione');
        const unitPrice = (price != null && isFinite(price))
        ? (isBond ? (price / 100) : price)   // €/€ nominale per bond, €/share per altri
        : NaN;

        if (isFinite(unitPrice)) {
        const valore = qty * unitPrice;      // NB: per bond = qty_nominale * (prezzo_quotato/100)

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
    });

    // === (Δ)Qty globale: largest remainder + fee fisse + tax per-asset + funding sell opzionale ===

    // vettori dai dati già presenti in tabella
    const p       = rows.map(r => parseFloat(r.dataset.prezzo || '0')); // €/€ per bond, €/share per altri
    const v       = rows.map(r => parseFloat(r.dataset.valore  || '0')); // valori correnti (pre-trade)
    const wT      = rows.map(r => parseFloat(r.querySelector('.target')?.textContent || '0') / 100); // target frazione

    const avgCost = rows.map(r => {
        const raw = parseFloat(r.dataset.costo || '0');             // dal PHP: quotato (per 100) sui bond
        const isBond = (r.dataset.type || '').toLowerCase() === 'obbligazione';
        return isBond ? (raw / 100) : raw;                          // €/€ per bond, €/share per altri
    });

    const qty0    = rows.map(r => parseFloat(r.dataset.quantita|| '0')); // quantità correnti
    const lot = rows.map(r => ((r.dataset.type || '').toLowerCase() === 'obbligazione') ? 1000 : 1); // lotto minimo (bond 1000 nominali, altri 1 share)    

    // Aliquota per-asset: dall'XML (data-taxrate-asset), default 26%
    const rate    = rows.map(r => {
        let t = r.dataset.taxrateAsset;
        if (t === undefined || t === '') t = '0.26';
        let x = parseFloat(t) || 0;
        return x > 1 ? x / 100 : x; // consente "26" -> 0.26
    });

    // Delta continui (includendo la liquidità nel denominatore, come già fai con totaleBase)
    // --- Calcolo sui PASSI di lotto ---
    // prezzo di UN passo (lotto): per bond = p[i]*1000, per altri = p[i]*1
    const pStep = p.map((pi, i) => pi * lot[i]);

    // delta continuo in "numero di lotti" (non in unità)
    const dStarStep = rows.map((_, i) => (pStep[i] > 0 ? (wT[i] * totaleBase - v[i]) / pStep[i] : 0));

    // base intera: quanti lotti (floor per tutti)
    const baseStep = dStarStep.map(d => Math.floor(d));

    // residuo in € da distribuire (in passi di lotto)
    let residual = dStarStep.reduce((acc, d, i) => acc + (d - baseStep[i]) * pStep[i], 0);

    // frazioni e ordine per largest remainder
    const frac  = dStarStep.map((d, i) => d - baseStep[i]);
    const order = [...frac.keys()].sort((i, j) => frac[j] - frac[i]);

    // distribuisci +1 lotto alla volta finché c'è residuo
    for (const i of order) {
        if (!(isFinite(pStep[i]) && pStep[i] > 0)) continue;
        if (frac[i] <= 0) continue;
        if (residual + 1e-9 < pStep[i]) continue; // epsilon fp
        baseStep[i] += 1;
        residual    -= pStep[i];
    }

    // delta in UNITÀ reali: lotti * dimensione lotto (1000 per bond, 1 per altri)
    const base = baseStep.map((b, i) => b * lot[i]);

    // --- COSTI: commissioni fisse + tasse sui guadagni delle vendite già presenti ---
    const traded = new Set(base.map((k, i) => (k !== 0 ? i : null)).filter(i => i !== null));
    let feeTotal = fee * traded.size;

    let taxTotal = 0;
    for (let i = 0; i < base.length; i++) {
        if (base[i] < 0 && isFinite(p[i]) && p[i] > 0) {
            const gainPerUnit = Math.max(0, p[i] - (isFinite(avgCost[i]) ? avgCost[i] : 0));
            taxTotal += rate[i] * gainPerUnit * Math.abs(base[i]);
        }
    }

// Cassa netta necessaria DOPO aver usato la liquidità disponibile (liqNum)
let cash = base.reduce((acc, k, i) => acc + (isFinite(p[i]) ? p[i] * k : 0), 0);
// need > 0 ⇒ serve ulteriore cassa oltre alla liquidità che hai
let need = feeTotal + taxTotal + cash - liqNum;

    // 1) Riduci acquisti marginali finché copri costi
    if (need > 1e-9) {
        const buyIdx = [...order].reverse().filter(i => base[i] > 0 && isFinite(p[i]) && p[i] > 0);
        for (const i of buyIdx) {
            if (need <= 1e-9) break;
            base[i] -= lot[i];          // togli 1 lotto
            cash    -= p[i] * lot[i];   // cassa migliora del prezzo di un lotto
            if (base[i] === 0) { traded.delete(i); feeTotal -= fee; }
            need = feeTotal + taxTotal + cash - liqNum;
        }
    }

    // 2) Se ancora serve cassa e l'opzione è attiva, vendi 1 quota dagli overweight (con guard-rail)
    if (need > 1e-9 && allowFundingSell) {
        // stato corrente post-trade parziale
        let vPrime = v.map((vi, i) => vi + base[i] * p[i]);
        let Vprime = vPrime.reduce((a, b) => a + (isFinite(b) ? b : 0), 0);

        let guard = 0;
        while (need > 1e-9 && guard++ < 500) {
        // denominatore attuale includendo liquidità dopo i costi già noti
        const liqAfter = liqNum - cash - feeTotal - taxTotal;
        const denomCurrent = Vprime + Math.max(0, liqAfter);

        // trova l'asset più sovrappesato (rispetto al target) sul denominatore corrente
        let bestIdx = -1, bestOver = -Infinity;
        for (let i = 0; i < rows.length; i++) {
            if (!(isFinite(p[i]) && p[i] > 0)) continue;
            const currQty = qty0[i] + base[i];
            if (currQty <= 0) continue; // non puoi vendere oltre
            const targetPct = wT[i] * 100;
            const percCurr  = denomCurrent > 0 ? (vPrime[i] / denomCurrent) * 100 : 0;
            const over      = percCurr - targetPct;
            if (over > bestOver) { bestOver = over; bestIdx = i; }
        }
        if (bestIdx === -1 || bestOver <= 0) break;

        const i = bestIdx;

        // prova una vendita di funding e verifica che resti entro tolleranza
        const newV_i   = vPrime[i] - p[i] * lot[i];
        const newVtot  = Vprime   - p[i] * lot[i];
        const extraTax = rate[i] * Math.max(0, p[i] - (isFinite(avgCost[i]) ? avgCost[i] : 0)) * lot[i];
        const liqAfter2 = liqNum - (cash - p[i] * lot[i]) - feeTotal - (taxTotal + extraTax);
        const denomAfter = newVtot + Math.max(0, liqAfter2);
        const newPerc    = denomAfter > 0 ? (newV_i / denomAfter) * 100 : 0;
        const tgtPct     = wT[i] * 100;

        if (newPerc + 1e-9 < (tgtPct - tol)) {
            // questa vendita porterebbe sotto il limite: prova un altro titolo nel giro successivo
        } else {
            // applica la vendita di funding
            base[i]  -= lot[i];
            vPrime[i] = newV_i;
            Vprime    = newVtot;

            if (!traded.has(i)) { traded.add(i); feeTotal += fee; } // nuova operazione → fee
            taxTotal += extraTax;                                   // tassa addizionale su 1 quota venduta

            cash -= p[i] * lot[i];                                           // cassa migliora (serve meno)
            need = feeTotal + taxTotal + cash - liqNum;
        }
        }
    }

    // --- Simulazione finale e scrittura cella "(Δ)Qty" ---
    const vPrimeFinal   = v.map((vi, i) => vi + base[i] * p[i]);
    const VprimeFinal   = vPrimeFinal.reduce((a, b) => a + (isFinite(b) ? b : 0), 0);
    const liqAfterFinal = liqNum - cash - feeTotal - taxTotal;
    const denomPost     = VprimeFinal + Math.max(0, liqAfterFinal);

    rows.forEach((r, i) => {
        const cell = r.querySelector('.delta-qty');
        if (!cell) return;

        if (!isFinite(tol)) {
            cell.textContent = '-';
            cell.classList.remove('ok', 'ko');
            cell.classList.add('muted');
            return;
        }

        const tgtPct    = wT[i] * 100;
        const percNow = totaleBase > 0 ? (v[i] / totaleBase) * 100 : 0;
        const within  = Math.abs(percNow - tgtPct) <= tol;

        const k = base[i];
        const kStr = `${k >= 0 ? '+' : ''}${k}`;

        cell.textContent = `${within ? 'OK' : 'KO'} Δ${kStr}`;
        cell.classList.toggle('ok', within);
        cell.classList.toggle('ko', !within);
        cell.classList.remove('muted');
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
