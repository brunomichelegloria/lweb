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

async function aggiornaPrezziDaYahoo() {
  const righe = document.querySelectorAll('table tr[data-ticker]');
  let totalePortafoglio = 0;
  const valori = [];

  // Step 1: recupera prezzi e calcola valore per riga
  for (const riga of righe) {
    const ticker = riga.dataset.ticker;
    const quantita = parseFloat(riga.dataset.quantita);
    const cellaValore = riga.querySelector('.valore');

    let valore = 0;
    if (quantita > 0) {
      const prezzo = await getRealtimePrice(ticker);
      if (prezzo !== null) {
        valore = quantita * prezzo;
        cellaValore.textContent = valore.toFixed(2);
      } else {
        cellaValore.textContent = 'Errore';
      }
    } else {
      cellaValore.textContent = '0';
    }

    valori.push(valore);
    totalePortafoglio += valore;
  }

  // Step 2: aggiorna % attuale
  righe.forEach((riga, i) => {
    const attualeCell = riga.querySelector('.attuale');
    const percentuale = totalePortafoglio > 0 ? (valori[i] / totalePortafoglio * 100) : 0;
    attualeCell.textContent = percentuale.toFixed(2);
  });

  // Step 3: grafico a torta
  generaGrafico();
}

function generaGrafico() {
  const righe = document.querySelectorAll('table tr[data-ticker]');
  const labels = [];
  const data = [];

  righe.forEach(riga => {
    const nome = riga.querySelector('.nome').textContent;
    const attuale = parseFloat(riga.querySelector('.attuale').textContent);
    if (attuale > 0) {
      labels.push(nome);
      data.push(attuale);
    }
  });

  new Chart(document.getElementById('grafico'), {
    type: 'pie',
    data: {
      labels: labels,
      datasets: [{
        label: 'Distribuzione attuale',
        data: data
      }]
    }
  });
}

// Avvio: carica la tabella da PHP, poi aggiorna
fetch('load.php')
  .then(res => res.text())
  .then(html => {
    document.getElementById('tabella').innerHTML = html;
    aggiornaPrezziDaYahoo();
  });
