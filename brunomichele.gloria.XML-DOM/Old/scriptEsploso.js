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

async function aggiornaPrezzi() {
    const tab = document.getElementById('tab-portafoglio');
    const symbol = tab?.dataset.symbol || '';
    const tolStr = tab?.dataset.tolleranza;
    const tol = (tolStr !== undefined && tolStr !== '') ? parseFloat(tolStr) : NaN;
    const fee = parseFloat(tab?.dataset.commissione || '0') || 0;
    const allowFundingSell = true;
    const righe = document.querySelectorAll('table tbody tr[data-ticker]');
    const defStepAz   = Math.max(1, parseInt(tab?.dataset.defaultStepAz   || '1') || 1);
    const defStepEtf  = Math.max(1, parseInt(tab?.dataset.defaultStepEtf  || '1') || 1);
    const defStepBond = Math.max(1, parseInt(tab?.dataset.defaultStepBond || '1') || 1);

    for (const riga of righe) {
        const tipo = (riga.dataset.type || '').toLowerCase();
        const ticker = riga.dataset.ticker;

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

        function parseNumLocale(s){ 
            if(s==null)return 0; 
            s=(''+s).trim().replace(/\./g,'').replace(',', '.'); 
            const x=parseFloat(s); 
            return Number.isFinite(x)?x:0; 
        }


        const isBond = (tipo === 'obbligazione');
        const qty = parseNumLocale(riga.dataset.quantita);

        // priceFromFetchOrCell = numero letto dai PHP o dalla cella (come hai già)
        let unitPrice = NaN;     // €/€ per bond ed equity/ETF
        let priceDisplay = NaN;  // ciò che mostriamo in .prezzo (quota per bond, unitPrice per altri)

        // Calcolo unitPrice + priceDisplay
        if (isBond) {
            const quota = parseNumLocale(priceFromFetchOrCell); // es. 102.14 (€/100)
            unitPrice   = quota / 100;                          // €/€
            priceDisplay = quota;                               // in UI mostriamo la quota
        } else {
            unitPrice   = parseNumLocale(priceFromFetchOrCell); // €/share
            priceDisplay = unitPrice;                           // in UI mostriamo l'unitario
        }

        if (isFinite(unitPrice)) {
            const valore = qty * unitPrice;

            // DISPLAY: usa i tuoi riferimenti corretti (scegline uno: o cellPrezzo/cellValore o prezzoCell/valoreCell)
            prezzoCell.textContent = priceDisplay.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '€';
            valoreCell.textContent = valore.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '€';

            // DATASET per i calcoli successivi
            r.dataset.prezzo = String(unitPrice);
            r.dataset.valore = String(valore);
        } else {
            prezzoCell.textContent = '-';
            valoreCell.textContent = '-';
            r.dataset.prezzo = '';
            r.dataset.valore = '';
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

    // tradeStep per-asset con fallback ai default per tipo
    const tradeStep = rows.map(r => {
        const raw = parseInt(r.dataset.tradestep || '0') || 0;
        const tipo = (r.dataset.type || '').toLowerCase();
        if (raw >= 1) return raw;
        if (tipo === 'obbligazione') return defStepBond;
        if (tipo === 'azione')       return defStepAz;
        if (tipo === 'etf')          return defStepEtf;
        return 1;
    });

    // passo effettivo di acquisto: stepUnit = lotto * tradeStep (sell resta a lotto)
    const stepUnit = rows.map((_, i) => lot[i] * tradeStep[i]);

    // Aliquota per-asset: dall'XML (data-taxrate-asset), default 26%
    const rate    = rows.map(r => {
        let t = r.dataset.taxrateAsset;
        if (t === undefined || t === '') t = '0.26';
        let x = parseFloat(t) || 0;
        return x > 1 ? x / 100 : x; // consente "26" -> 0.26
    });

    // --- Parametri di contesto ---
    const liqTargetStr = tab?.dataset.liqTarget ?? '';
    const liqTarget = liqTargetStr === '' ? null : parseFloat(liqTargetStr); // null = non specificato
    const liqDisponibile = liqNum; // già calcolata prima (footer)

    // % attuale pre-trade per ogni asset (sul portafoglio: asset + liquidità)
    const percNow = rows.map((_, i) => totaleBase > 0 ? (v[i] / totaleBase) * 100 : 0);
    const targetPct = rows.map((r, i) => parseFloat(r.querySelector('.target')?.textContent || '0'));

    // In banda (per-asset, rispetto a tol)? NB: asset con target=0 NON sono "in banda": vanno liquidati
    const inBand = rows.map((_, i) => {
    const t = targetPct[i];
    if (t === 0) return false; // target=0 => vendita a zero anche se "vicino"
        return isFinite(tol) ? Math.abs(percNow[i] - t) <= tol : false;
    });

    // Tutti in banda?
    const allInBand = inBand.every(Boolean);

    // Caso speciale "greedy": tutti in banda e liquidità con target=0 e cassa > 0 (soglia: >= fee)
    const greedyMode = allInBand && liqTarget === 0 && (liqDisponibile > (fee || 0));

    // Delta continui (includendo la liquidità nel denominatore, come già fai con totaleBase)
    // --- Calcolo sui PASSI di lotto ---
    // === dStar sui PASSI di lotto con GATING tolleranza / target=0 / modalità greedy ===
    // prezzo di UN passo: pStep = p * lot
    const pStep = p.map((pi, i) => pi * lot[i]);

    const dStarStep = rows.map((_, i) => {
        const pi = pStep[i];
        if (!(isFinite(pi) && pi > 0)) return 0;

        // 1) target=0 => liquidare (vendere tutto il valore dell'asset)
        if (targetPct[i] === 0) {
            return -(v[i] / pi); // in passi di lotto (sarà arrotondato verso il basso poi)
        }

        // 2) se non greedy e asset in banda => nessun trade
        if (!greedyMode && inBand[i]) return 0;

        // delta continuo in passi di lotto verso il TARGET (centrare la banda)
        return ( (wT[i] * totaleBase) - v[i] ) / pi;
    });

    if (greedyMode) {
        let bestIdx = -1;
        let bestGap = -Infinity;
        for (let i = 0; i < rows.length; i++) {
            const gap = targetPct[i] - percNow[i];
            // candidati solo gli underweight con target>0
            if (targetPct[i] > 0 && gap > bestGap) { bestGap = gap; bestIdx = i; }
        }
        for (let i = 0; i < dStarStep.length; i++) {
            if (i !== bestIdx) dStarStep[i] = 0; // disattiva tutti gli altri
            else dStarStep[i] = Math.max(0, dStarStep[i]); // in greedy compriamo soltanto
        }
    }

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
   // fee per lato: una per asset in acquisto e una per asset in vendita (nello stesso piano non coesistono buy+sell sullo stesso titolo)
    const buyCount  = base.reduce((n, k) => n + (k > 0 ? 1 : 0), 0);
    const sellCount = base.reduce((n, k) => n + (k < 0 ? 1 : 0), 0);
    let feeTotal = fee * (buyCount + sellCount);

    // tieni comunque il set per aggiornare fee quando una posizione torna a 0
    const traded = new Set(base.map((k, i) => (k !== 0 ? i : null)).filter(i => i !== null));

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
                if (currQty <= 0 || base[i] > 0) continue; // non puoi vendere oltre, nè trasformare un buy in sell
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

    // ===== Greedy a cascata: spendi la cassa disponibile =====
    let cashLeft = Math.max(0, -need);

    // candidati buy: target>0, non in sell in questo piano
    const buying = new Set(); // per contare fee buy solo la prima volta
    const selling = new Set(rows.map((_, i) => (base[i] < 0 ? i : null)).filter(i => i !== null));
    for (let i = 0; i < base.length; i++) {
        if (base[i] > 0) buying.add(i);
    }

    function recomputePercNowWithPlan() {
        // Valori post-piano parziale: includi sia buy che sell già pianificati
        const vPlan = v.map((vi, i) => vi + (isFinite(base[i]) ? base[i] * p[i] : 0));
        const Vplan = vPlan.reduce((a, b) => a + (isFinite(b) ? b : 0), 0);
        // Denominatore corretto: asset (post piano parziale) + cassa residua
        const denom = Vplan + Math.max(0, cashLeft);
        return rows.map((_, i) => denom > 0 ? (vPlan[i] / denom) * 100 : 0);
    }

    function stepCostFor(i, stepQty) {
        if (!(isFinite(p[i]) && p[i] > 0)) return Infinity;
        if (selling.has(i)) return Infinity; // mai buy + sell sullo stesso asset
        const feeIntro = buying.has(i) ? 0 : fee;
        return p[i] * stepQty + feeIntro;
    }

    function feasibleStepFor(i, cashAvail) {
        // quante unità posso comprare su i rispettando (stepUnit[i] ... lot[i]) e la cassa?
        const feeIntro = buying.has(i) ? 0 : fee;
        const budget = cashAvail - feeIntro;
        if (budget <= 0) return 0;
        const maxSteps = Math.floor(budget / (p[i] * lot[i])); // in multipli di lotto
        if (maxSteps <= 0) return 0;
        const stepMult = Math.min(tradeStep[i], maxSteps);
        return stepMult * lot[i];
    }

    let safety = 0;
    while (cashLeft > 1e-9 && safety++ < 5000) {
        const percPlan = recomputePercNowWithPlan();

        // ordina candidati per gap decrescente (bucket=portafoglio per ora)
        const candidates = [];
        for (let i = 0; i < rows.length; i++) {
            if (targetPct[i] <= 0) continue;
            if (!(isFinite(p[i]) && p[i] > 0)) continue;
            if (selling.has(i)) continue;
            const gap = (targetPct[i] - percPlan[i]); // può essere <=0: proviamo comunque per logica "cascata controllata"
            const qty = feasibleStepFor(i, cashLeft);
            if (qty <= 0) continue;
            // punteggio semplice = gap; tie-breaker: prezzo step minore prima
            candidates.push({ i, gap, stepQty: qty, stepCost: stepCostFor(i, qty) });
        }

        if (candidates.length === 0) break;

        candidates.sort((a, b) => (b.gap - a.gap) || (a.stepCost - b.stepCost));
        const pick = candidates[0];

        // se il migliore ha gap <= 0 e TUTTI hanno gap <= 0, fermati (niente overbuy cieco)
        const maxGap = pick.gap;
        if (maxGap <= 0) {
            // ma se desideri comunque spendere (overbuy totale) commenta questa riga
            break;
        }

        // esegui 1 passo (acquisto) sul migliore
        const i = pick.i;
        const qty = pick.stepQty;
        const cost = stepCostFor(i, qty);
        if (cost > cashLeft + 1e-9) {
            // riduci lo step fino al minimo fattibile
            const smallQty = feasibleStepFor(i, cashLeft);
            if (smallQty <= 0) {
            // niente da fare su questo: prova nel prossimo giro (riordinerà e forse troverà altri)
            // per evitare loop infinito, togli questo candidato forzando cashLeft lieve variazione
            break;
            }
            base[i] += smallQty;
            if (!buying.has(i)) buying.add(i);
            cashLeft -= stepCostFor(i, smallQty);
        } else {
            base[i] += qty;
            if (!buying.has(i)) buying.add(i);
            cashLeft -= cost;
        }
    }

    // --- Simulazione finale e scrittura cella "(Δ)Qty" ---
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
    aggiornaPrezzi();
  });
