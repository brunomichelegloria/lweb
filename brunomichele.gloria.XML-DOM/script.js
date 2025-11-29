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
        const att = Number.isFinite(attuale) ? attuale : 0;
        const tar = Number.isFinite(target)  ? target  : 0;

        if (tar > 0 || att > 0) {
            labels.push(nome);
            targets.push(tar);
            data.push(att);
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


function setupAssetPopup() {
    const dlg  = document.getElementById('asset-dialog');
    const form = document.getElementById('asset-form');
    if (!dlg || !form) return;

    // Evita doppie inizializzazioni
    if (form.dataset.assetsInitialized === '1') return;
    form.dataset.assetsInitialized = '1';

    const body = form.querySelector('.dialog-body');
    const pathInput = document.getElementById('asset-dialog-path');

    // ---------- Helpers ----------
    const qsa = (root, sel) => Array.from(root.querySelectorAll(sel));
    const toCamel = s => s.replace(/-([a-z])/g, (_,c)=>c.toUpperCase()); // "tax-rate" -> "taxRate"
    const fieldFromName = name => (name.match(/\[([^\]]+)\]$/) || [null, null])[1];

    function normalizePath(p) {
        if (!p || p === '/') return '';
        return p.endsWith('/') ? p.slice(0, -1) : p;
    }

    function isDirectChild(rootPath, childPath) {
        const root  = normalizePath(rootPath);
        const child = normalizePath(childPath || '');
        if (root === '') {
            const segs = child.split('/').filter(Boolean);
            return segs.length === 1; // "/X"
        }
        const prefix = root + '/';
        if (!child.startsWith(prefix)) return false;
        const rest = child.slice(prefix.length);
        return rest.length > 0 && !rest.includes('/'); // esattamente un segmento
    }

    function getDirectChildrenByDataPath(rootPath) {
        return Array.from(document.querySelectorAll('[data-path]')).filter(el => isDirectChild(rootPath, el.dataset.path));
    }

    function replacePlaceholders(root, id){
        qsa(root, '[name]').forEach(el => el.name = el.name.replaceAll('__ID__', id));
        qsa(root, '[id]').forEach(el => el.id = el.id.replaceAll('__ID__', id));
        qsa(root, '[for]').forEach(el => el.setAttribute('for', el.getAttribute('for').replaceAll('__ID__', id)));
        qsa(root, '[value]').forEach(el => {
            const v = el.getAttribute('value'); if (v) el.setAttribute('value', v.replaceAll('__ID__', id));
        });
    }

    function createChangedMarker(container, id, field){
        const name = `changed[${id}][${field}]`;
        let marker = container.querySelector(`input[name="${name}"]`);

        if (!marker) {
            marker = document.createElement('input');
            marker.type = 'hidden'; marker.name = name; marker.value = '1';
            container.appendChild(marker);
        }
    }

    function readText(el, sel, fallback='') {
        const n = sel ? el.querySelector(sel) : null;
        const txt = (n ? n.textContent : '').trim();
        return txt || fallback;
    }

    function safeIdFromPath(p) {
        // ultimo segmento dopo '/', ripulisci per usarlo come chiave di form
        const seg = (p || '').split('/').filter(Boolean).pop() || ('N' + Math.random().toString(36).slice(2,9));
        return seg.replace(/[^\w\-.\(\)]+/g, '_'); // solo [A-Za-z0-9_ -]
    }
    
    function detectType(src) {
        // prova in ordine: data-type, classi, default "azione"
        const t = (src.dataset.type || '').toLowerCase();
        if (t) return t;
        const cls = src.className.toLowerCase();
        if (/\bazion/.test(cls)) return 'azione';
        if (/\betf\b/.test(cls)) return 'etf';
        if (/\bobbligaz/.test(cls)) return 'obbligazione';
        if (/\bbuck/.test(cls)) return 'bucket';
        return 'bho';
    }
    function collectDataFromSource(src) {
        // Usa data-* se presenti; altrimenti prova a leggere da celle note; fallback = vuoto
        // ID: se non presente, deriva dall'ultimo segmento del data-path
        const data = {};
        data.id       = src.dataset.id || safeIdFromPath(src.dataset.path);
        data.type     = detectType(src);
        data.ticker   = src.dataset.ticker   ?? readText(src, '[data-col="ticker"]');
        data.nome     = src.dataset.nome     ?? readText(src, '[data-col="nome"]');
        data.target   = src.dataset.targetRaw   ?? readText(src, '[data-col="target-raw"]');
        data.taxRate  = src.dataset.taxRate  ?? readText(src, '[data-col="tax-rate"]');
        data.cedola   = src.dataset.cedola   ?? readText(src, '[data-col="cedola"]');
        data.fcedola  = src.dataset.fcedola  ?? readText(src, '[data-col="fcedola"]');
        data.scadenza = src.dataset.scadenza ?? readText(src, '[data-col="scadenza"]');
        data.valuta   = src.dataset.valuta   ?? readText(src, '[data-col="valuta"]');
        return data;
    }

    function enableEditing(fieldset){
        // abilita tutti i campi dati del blocco
        const inputs = fieldset.querySelectorAll('[name^="assets["], .template-div > label > *');
        inputs.forEach(el => {
            if (!el.hasAttribute('data-original')) {
                const val = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;
                el.setAttribute('data-original', val);
            }
            el.disabled = false; // ora modificabile
        });

        // focus sul primo campo utile
        const first = fieldset.querySelector('input:not([type="hidden"]), select, textarea');
        if (first) first.focus();

        //apri i dettagli se collassati
        const det = fieldset.querySelector('details');
        if (det) det.open = true;

        
    }

    function fillExistingBlock(fieldset, id, data){
        qsa(fieldset, '[name^="assets[').forEach(el => {
            const f = fieldFromName(el.name); if (!f) return;
            const key = toCamel(f); // mappa es. tax-rate -> taxRate
            const val = (data[key] ?? '');

            if (el.type === 'checkbox') {
                const checked = (val === '1' || val === 1 || val === true || val === 'true');
                el.checked = checked; 
                el.setAttribute('data-original', checked ? '1' : '0'); 
                el.disabled = true;
            } else {
                el.value = val; 
                el.setAttribute('data-original', val); 
                el.disabled = true;
            }
        });

        const rem = fieldset.querySelector(`input[name="remove[${id}]"]`);
        if (rem) { 
            rem.disabled = true; 
            rem.value = '0'; 
        }
        const nw  = fieldset.querySelector(`input[name="new[${id}]"]`);
        if (nw) { 
            nw.disabled  = true; 
            nw.value  = '0'; 
        }
    }

    function makeNewBlockEditable(fieldset, id){
        const nw = fieldset.querySelector(`input[name="new[${id}]"]`);
        if (nw) { 
            nw.disabled = false; 
            nw.value = '1'; 
        }
        qsa(fieldset, '[name^="assets["]').forEach(el => { el.disabled = false; });
    }

    function buildBlockFromSource(src, rootPath){
        const d   = collectDataFromSource(src, rootPath);
        const id  = d.id;
        const typ = d.type;

        const tmpl = document.getElementById(`template-${typ}`);
        const frag = tmpl.content.cloneNode(true);
        replacePlaceholders(frag, id);

        const fieldset = frag.querySelector('.asset-field');
        fieldset.dataset.id = id;

        // titolo: aggiungi ticker/isin se presente
        const titleP = fieldset.querySelector('div[class="template-div"] > p');
        const label  = d.nome ? ` — ${d.nome}` : '';
        if (titleP) titleP.textContent = titleP.textContent.replace(/\s*—.*$/,'') + label;

        fillExistingBlock(fieldset, id, d);

        body.appendChild(fieldset);
        body.appendChild(document.createElement('hr'));
    }

    function addNewAsset(type){
        const tmpl = document.getElementById(`template-${type}`) || document.getElementById('template-azione');
        const counterEl = document.getElementById('asset-dialog');
        let n = parseInt(counterEl.dataset.counter, 10) + 1;
        let id = `N${n}`;
        while (document.querySelector(`.asset-field[data-id="${id}"]`)) {
            ++n;
            id = `N${n}`;
        }
        counterEl.dataset.counter = String(n);

        const frag = tmpl.content.cloneNode(true);
        replacePlaceholders(frag, id);
        const fieldset = frag.querySelector('.asset-field');
        fieldset.dataset.id = id;

        const titleP = fieldset.querySelector('div[class="template-div"] > p');
        if (titleP) titleP.textContent = (titleP.textContent || type.toUpperCase()) + ' — nuovo';

        makeNewBlockEditable(fieldset, id);
        body.appendChild(fieldset);
        body.appendChild(document.createElement('hr'));

        const first = fieldset.querySelector('input,select,textarea');
        if (first) first.focus();
    }

    function handleFieldChange(input){
        if (!input.name?.startsWith('assets[')) return;
        const fieldset = input.closest('.asset-field'); 
        if (!fieldset) return;
        const id = fieldset.dataset.id; const field = fieldFromName(input.name);
        if (!field) return;

        const isNew = !!fieldset.querySelector(`input[name="new[${id}]"]:not([disabled])`);
        if (isNew) { 
            input.disabled = false; 
            return; 
        }

        const orig = input.getAttribute('data-original') ?? '';
        const curr = (input.type === 'checkbox') ? (input.checked ? '1' : '0') : input.value;

        if (curr !== orig) {
            input.disabled = false;
            createChangedMarker(fieldset, id, field);
            input.classList.toggle('changed-field', true);
        } else {
            input.disabled = true;
            const m = fieldset.querySelector(`input[name="changed[${id}][${field}]"]`);
            if (m) m.remove();
            el.classList.toggle('changed-field', false);
        }
    }

    function buildPortfolioBlock(){
        const templ = document.getElementById(`template-portfolio-info`);
        if (!templ) return;
        const frag  = templ.content.cloneNode(true);
        const fieldset = frag.querySelector('#portfolio-info-fieldset');
        if (!fieldset) return;
        fieldset.dataset.id = 'info';
        const table = document.getElementById('tab-portafoglio');
        if (!table) return;
        const footerSpan = document.getElementById('footer-data');
        const liqSpan = document.getElementById('liquidita-totale');

        const data = {
            liquidita: liqSpan?.textContent.trim().replace(',', '.') || '',
            liqTarget: liqSpan?.dataset.liqTarget || '',
            commissione: footerSpan?.dataset.commissione || '',
            tolleranza: footerSpan?.dataset.tolleranza || '',
            valuta: table?.dataset.valuta || '',
        };
        
        const liqFormInput = fieldset.querySelector('input[name="assets[info][liquidita]"]');
        if (liqFormInput) liqFormInput.value = data.liquidita.replace(data.valuta, '');
        const liqTargetFormInput = fieldset.querySelector('input[name="assets[info][liq-target]"]');
        if (liqTargetFormInput) liqTargetFormInput.value = data.liqTarget;
        const commFormInput = fieldset.querySelector('input[name="assets[info][commissione]"]');
        if (commFormInput) commFormInput.value = data.commissione;
        const tollFormInput = fieldset.querySelector('input[name="assets[info][tolleranza]"]');
        if (tollFormInput) tollFormInput.value = data.tolleranza;
        const valutaFormInput = fieldset.querySelector('select[name="assets[info][valuta]"]');
        if (valutaFormInput) valutaFormInput.value = data.valuta;

        console.log('RAW:', liqSpan?.textContent, 'CLEAN:', liqSpan?.textContent.trim().replace(',', '.').replace(data.valuta, ''));
        const hr = document.createElement('hr');
        body.prepend(hr);
        body.prepend(fieldset);
    }

    // ---------- LISTENERS ----------

    // Apertura popup: prende root tramite btn.closest('[data-path]') e cerca i FIGLI DIRETTI via data-path
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-open-assets]');
        if (!btn) return;

        const rootEl   = btn.closest('[data-path]');
        const rootPath = rootEl ? (rootEl.dataset.path || '') : '';
        pathInput.value = rootPath;

        const sources = getDirectChildrenByDataPath(rootPath);
        body.innerHTML = '';
        if (rootPath === '') {
            buildPortfolioBlock();
        }
        sources.forEach(src => buildBlockFromSource(src, rootPath));
        dlg.dataset.counter = '0';

        if (dlg.showModal) dlg.showModal(); 
        else dlg.setAttribute('open','');
    });

    // Cambi campo -> invia solo differenze
    form.addEventListener('input',  (e) => { 
        const el = e.target; 
        if (el.matches('input,select,textarea')) handleFieldChange(el); 
    });
    form.addEventListener('change', (e) => {
        const el = e.target;
        if (el.matches('input,select,textarea')) handleFieldChange(el); 
    });

    form.addEventListener('click', (e) => {
        // EDIT
        const editBtn = e.target.closest('.asset-edit');
        if (editBtn) {
            e.preventDefault();    // evita submit/toggle
            e.stopPropagation();   // evita il toggle del <summary>
            const fieldset = editBtn.closest('.asset-field');
            if (fieldset) enableEditing(fieldset);
            return;
        }

        // REMOVE
        const removeBtn = e.target.closest('.asset-remove');
        if (removeBtn) {
            e.preventDefault();
            e.stopPropagation();
            const fieldset = removeBtn.closest('.asset-field'); if (!fieldset) return;
            const id = fieldset.dataset.id;
            const isNew = !!fieldset.querySelector(`input[name="new[${id}]"]:not([disabled])`);
            if (isNew) {
                const hr = fieldset.nextElementSibling;
                fieldset.remove(); 
                if (hr && hr.tagName === 'HR') hr.remove();
            } else {
                const rem = fieldset.querySelector(`input[name="remove[${id}]"]`);
                if (rem) { 
                    rem.disabled = false;
                    rem.value = '1';
                }
                fieldset.querySelectorAll('[name^="assets["]').forEach(el => el.disabled = true);
                fieldset.querySelectorAll('input[name^="changed["]').forEach(el => el.remove());
                fieldset.style.opacity = .5;
                fieldset.style.pointerEvents = 'none';
            }
            return;
        }
    });

    form.addEventListener('submit', () => {
        const blocks = Array.from(form.querySelectorAll('.asset-field'));
        blocks.forEach(fieldset => {
            const id = fieldset.dataset.id;
            const isNew = !!fieldset.querySelector(`input[name="new[${id}]"]:not([disabled])`);
            if (isNew) return; // i nuovi inviano tutto

            Array.from(fieldset.querySelectorAll('[name^="assets["]')).forEach(el => {
                const field = (el.name.match(/\[([^\]]+)\]$/) || [null,null])[1];
                if (!field) return;
            
                const marker = fieldset.querySelector(`input[name="changed[${id}][${field}]"]`);
                // Valore attuale vs originale
                const orig = el.getAttribute('data-original') ?? '';
                const curr = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;

                // Se il campo NON è cambiato e non ha marker, non inviarlo
                if (!marker && curr === orig) el.disabled = true;
            });
        });
    });

    // Bottoni “+”
    document.getElementById('assetAddBucket')?.addEventListener('click', () => addNewAsset('bucket'));
    document.getElementById('assetAddAzione')?.addEventListener('click', () => addNewAsset('azione'));
    document.getElementById('assetAddEtf')?.addEventListener('click',    () => addNewAsset('etf'));
    document.getElementById('assetAddObb')?.addEventListener('click',    () => addNewAsset('obbligazione'));

    // Annulla
    document.getElementById('btn-cancel')?.addEventListener('click', () => dlg.close());
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



    document.addEventListener('click', function (e) {
        document.querySelectorAll('.toggable-menu').forEach(menu => {
            const isDialog = menu.tagName.toLowerCase() === 'dialog';

            if (isDialog) {
                if (!menu.open) return;

                if (e.target === menu) menu.close();
                return;
            }

            const isVisible = window.getComputedStyle(menu).display !== 'none';
            if (isVisible && !menu.contains(e.target)) {
                menu.style.display = 'none';
            }
        });

        var helpers = document.querySelectorAll('.help-btn');
        helpers.forEach(helper => {
            if (helper.contains(e.target)) {
                var helpBtnClass = helper.className.split(' ')[0];
                var helperText = document.querySelector(`p.${helpBtnClass}`);
                helperText.style.opacity = helperText.style.opacity === '0.7' ? '0' : '0.7';
            }
        });
    });

    setupAssetPopup();
}

function bilanciaAssets() {}
