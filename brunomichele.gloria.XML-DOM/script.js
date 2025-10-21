// === GRAFICO A TORTA ===
function generaGrafico(liqAttualePerc) {
    const righe = document.querySelectorAll('#tab-portafoglio > tbody > tr[data-type]');
    const labels = [];
    const targets = [];
    const data = [];
    const colors = ['#4e79a7', '#f28e2b',
        '#e15759', '#76b7b2', '#59a14f', 
        '#edc949', '#af7aa1', '#ff9da7',
        '#9c755f', '#bab0ab'];

    righe.forEach(riga => {
        const nome = riga.querySelector('.nome').textContent;
        const attuale = parseFloat(riga.querySelector('.attuale').textContent.replace(',', '.'));
        const target = parseFloat(riga.querySelector('.target').textContent.replace(',', '.'));
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

// === FUNZIONI AUSILIARIE ===

function openForRow(row, anchor){
    state.path = row.dataset.path || '';
    state.type = (row.dataset.type || '').toLowerCase();
    state.qty = parseInt(row.dataset.quantita || '0', 10) || 0;
    state.lastPrice = parseFloat(row.dataset.prezzo || '0') || 0;
    state.step = (state.type === 'obbligazione') ? 1000 : 1;
    state.anchor = anchor;

    setMode('buy');

    qtyIn.value = state.step;
    qtyIn.min = state.step;
    qtyIn.step = state.step;
    priceIn.value = (state.lastPrice > 0) ? to6(state.lastPrice) : '';

    positionNear(anchor);
    pop.style.display = 'block';
    qtyIn.focus();
}

// === SETUP EVENTI ===

function setupEventListeners() {
    
    document.querySelectorAll('.toggle-details-button').forEach(btn => {
        btn.addEventListener('click', function() {
            this.classList.toggle('rotate-90');

            const detailsRow = this.closest('tr').nextElementSibling;
            if (detailsRow && detailsRow.classList.contains('bucket-details')) {
                detailsRow.style.display = detailsRow.style.display === 'table-row' ? 'none' : 'table-row';
            }
        });
    });

    document.getElementById('modeSwitch').addEventListener('change', () => {
        document.getElementById('modeLabel').textContent = modeSwitch.checked ? 'Vendita' : 'Acquisto';
        var setOp = document.getElementById('op-type');
        if (setOp) {
            setOp.setAttribute('value', modeSwitch.checked ? 'sell' : 'buy');
        }
    });

    document.querySelectorAll('button[data-role="ops-gear"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var menu = document.getElementById('ops-popover');

            if (menu) {
                var row = btn.closest('tr');
                var rect = btn.getBoundingClientRect();
                var top = window.scrollY + rect.top;
                var left = window.scrollX + rect.left;
                var priceInput = document.getElementById('op-price');
                var priceTmp = parseFloat(row.dataset.prezzo || '0');
                var pathInput = document.getElementById('op-path');

                pathInput.value = row.dataset.path || '';
                
                menu.style.display = menu.style.display === 'flex' ? 'none' : 'flex';
                menu.style.top = (top - menu.offsetHeight - 10) + 'px';
                menu.style.left = left + 'px';
                priceInput.placeholder = priceTmp;
            }
        })
    });

    document.addEventListener('click', function(e){
        var menu = document.getElementById('ops-popover');

        if (menu && menu.style.display === 'flex' && !menu.contains(e.target)) {
            menu.style.display = 'none';
        }
    });
}

function sendOrder() {}
function bilanciaAssets() {}
