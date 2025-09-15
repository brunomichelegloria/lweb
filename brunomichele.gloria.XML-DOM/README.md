# Portfolio Rebalancing Web App

This project is a **personal and academic** web application developed for educational purposes, as part of a school assignment. It demonstrates the use of **XML**, **HTML**, **CSS**, **JavaScript**, and **DOM manipulation** to manage and visualize a financial portfolio. The app includes functionalities such as:

- Representing a portfolio in XML  
- Validating the XML structure with DTD/XSD  
- Displaying data in an HTML table  
- Calculating target deviations and rebalance actions  
- Simulating asset purchases and sales  

## ⚠️ Disclaimer

- This project is **not intended for real-world financial planning or investment decisions**  
- The app **does not guarantee accuracy or reliability** of the data it uses or displays  
- No financial data is stored permanently; no database is used  
- Asset prices are obtained in real-time (or near real-time) from public APIs for demonstration purposes only  
- The calculations and results provided are **simplified simulations** and **should not be interpreted as financial advice**  

## Legal Notice

This software is provided “as is”, **without any warranty of any kind**, express or implied, including but not limited to warranties of merchantability, fitness for a particular purpose, or non-infringement. In no event shall the author(s) or contributors be held liable for any claim, damages, or other liability, whether in an action of contract, tort, or otherwise, arising from, out of, or in connection with the software or the use or other dealings in the software.

**Use of this software is at your own risk.**

## License

No license. This is a private academic project.  
**Not intended for reuse or redistribution.**



// === (Δ)Qty: arrotondamento GLOBALE budget-neutral + conformità post-trade ===
{
  const tab = document.getElementById('tab-portafoglio');
  const tolStr = tab?.dataset.tolleranza;
  const tol = (tolStr !== undefined && tolStr !== '') ? parseFloat(tolStr) : NaN;

  const rows = Array.from(document.querySelectorAll('table tbody tr[data-ticker]'));
  const p = rows.map(r => parseFloat(r.dataset.prezzo || '0'));
  const v = rows.map(r => parseFloat(r.dataset.valore || '0'));
  const wT = rows.map(r => parseFloat(r.querySelector('.target')?.textContent || '0') / 100);

  const V = v.reduce((a,b)=>a+(isFinite(b)?b:0),0);
  // delta continuo per asset
  const dStar = rows.map((_,i) => (p[i] > 0 ? (wT[i]*V - v[i]) / p[i] : 0));

  // base: floor su tutti per evitare overspend
  const base = dStar.map(d => Math.floor(d));

  // residuo in valore da distribuire
  let residual = dStar.reduce((acc, d, i) => acc + (d - base[i]) * p[i], 0);

  // largest remainders: ordina per parte frazionaria decrescente
  const frac = dStar.map((d,i) => d - base[i]); // in [0,1)
  const order = [...frac.keys()].sort((i,j) => frac[j] - frac[i]);

  const minPrice = Math.min(...p.filter(x => isFinite(x) && x > 0));
  for (const i of order) {
    if (!(isFinite(p[i]) && p[i] > 0)) continue;
    if (frac[i] <= 0) continue;
    if (residual + 1e-9 < p[i]) continue;
    base[i] += 1;              // compra +1 (o riduci una vendita eccessiva)
    residual -= p[i];
  }

  // simulazione post-trade
  const vPrime = v.map((vi,i) => vi + base[i]*p[i]);
  const Vprime = vPrime.reduce((a,b)=>a+(isFinite(b)?b:0),0);

  rows.forEach((r,i) => {
    const deltaCell = r.querySelector('.delta-qty');
    if (!deltaCell) return;

    // tolleranza mancante -> mostra solo "–" attenuato
    if (!isFinite(tol)) {
      deltaCell.textContent = '-';
      deltaCell.classList.remove('ok','ko');
      deltaCell.style.opacity = '0.7';
      return;
    }

    const targetPct = wT[i]*100;
    const percPrime = Vprime > 0 ? (vPrime[i]/Vprime)*100 : 0;
    const within = Math.abs(percPrime - targetPct) <= tol;

    const k = base[i]; // delta intero definitivo
    const kStr = `${k >= 0 ? '+' : ''}${k}`;
    deltaCell.textContent = `${within ? 'OK' : 'KO'} Δ${kStr}`;
    deltaCell.classList.toggle('ok', within);
    deltaCell.classList.toggle('ko', !within);
    deltaCell.style.opacity = '';
  });
}
