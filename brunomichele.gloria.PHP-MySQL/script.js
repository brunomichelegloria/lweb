let portfolioChart = null;

function generaGrafico(liqAttualePerc) {
    const righe = document.querySelectorAll(
        '#tab-portafoglio > tbody > tr[data-type]'
    );

    const labels = [];
    const targets = [];
    const data = [];

	let hasAnyAttuale = false;

    const colors = [
        '#4e79a7', '#f28e2b', '#e15759', '#76b7b2',
        '#59a14f', '#edc949', '#af7aa1', '#ff9da7',
        '#9c755f', '#bab0ab'
    ];

    righe.forEach(riga => {
        const nomeEl = riga.querySelector('.nome');
        const attualeEl = riga.querySelector('.attuale');
        const targetEl = riga.querySelector('.target');

        if (!nomeEl || !attualeEl || !targetEl) {
            return;
        }

        const nome = nomeEl.textContent.trim();

        const attuale = parseFloat(
            attualeEl.textContent.replace(',', '.')
        );
        const target = parseFloat(
            targetEl.textContent.replace(',', '.')
        );

        const att = Number.isFinite(attuale) ? attuale : 0;
        const tar = Number.isFinite(target) ? target : 0;

		if (att > 0) {
			hasAnyAttuale = true;
		}

        if (att > 0 || tar > 0) {
            labels.push(nome);
            data.push(att);
            targets.push(tar);
        }
    });

	if (!hasAnyAttuale && liqAttualePerc <= 0) {
		if (portfolioChart) {
			portfolioChart.destroy();
			portfolioChart = null;
		}
		return;
	}

    labels.push('Liquidità');

    const liqTarget = parseFloat(document.getElementById('liquidita-totale')?.dataset.liqTarget) || 0;

    data.push(Number(liqAttualePerc.toFixed(2)));
    targets.push(liqTarget);

    const canvas = document.getElementById('graph');
    if (!canvas) {
        return;
    }

    if (portfolioChart) {
        portfolioChart.destroy();
        portfolioChart = null;
    }

    portfolioChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Target',
                    data: targets,
                    backgroundColor: colors.slice(0, labels.length),
                    weight: 1
                },
                {
                    label: 'Attuale',
                    data: data,
                    backgroundColor: colors.slice(0, labels.length),
                    weight: 2
                }
            ]
        },
        options: {
            responsive: false,
            cutout: '0%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        generateLabels: chart => {
                            return chart.data.labels.map((label, i) => ({
                                text: label,
                                fillStyle: colors[i],
                                strokeStyle: '#fff',
                                lineWidth: 1,
                                index: i
                            }));
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.dataset.label}: ${ctx.parsed}%`
                    }
                }
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('[data-bucket-zone][data-depth]').forEach(el => {
		const depth = parseInt(el.dataset.depth, 10);
		el.classList.remove('bucket-zone-even', 'bucket-zone-odd');
		el.classList.add((depth % 2 === 0) ? 'bucket-zone-even' : 'bucket-zone-odd');
	});

	document.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-toggle]');
		if (!btn) return;

		const bucketId = btn.dataset.toggle;
		const detailsRow = document.querySelector(`tr.bucket-details[data-details-of="${bucketId}"]`);
		if (!detailsRow) return;

		const isOpen = detailsRow.style.display === 'table-row';
		detailsRow.style.display = isOpen ? 'none' : 'table-row';

		btn.classList.toggle('rotate-90', !isOpen);
	});

	const dialog = document.getElementById('asset-dialog');
	const form = document.getElementById('asset-form');
	const dialogBody = dialog?.querySelector('.dialog-body');

	const inpPortfolioId = document.getElementById('asset-dialog-portfolioId');
	const inpScopeBucketId = document.getElementById('asset-dialog-scopeBucketId');

	const btnAddBucket = document.getElementById('assetAddBucket');
	const btnAddAzione = document.getElementById('assetAddAzione');
	const btnAddEtf = document.getElementById('assetAddEtf');
	const btnAddObb = document.getElementById('assetAddObb');
	const btnCancel = document.getElementById('btn-cancel');

	const tplInfo = document.getElementById('template-portfolio-info');
	const tplBucket = document.getElementById('template-bucket');
	const tplAzione = document.getElementById('template-azione');
	const tplEtf = document.getElementById('template-etf');
	const tplObb = document.getElementById('template-obbligazione');

	function mustTemplate(tpl, id) {
		if (tpl) return tpl;
		console.error(`Template mancante: #${id}`);
		return null;
	}

	const editorState = {
		openBucketId: null,
		isRoot: false,
		tempCounter: 0,
		dirtyKeys: new Set()
	};

	function nextTempId() {
		editorState.tempCounter += 1;
		return 't' + editorState.tempCounter;
	}

	function setDirty(fieldset) {
		if (!fieldset) return;
		fieldset.dataset.dirty = '1';
		if (fieldset.dataset.key) editorState.dirtyKeys.add(fieldset.dataset.key);
	}

	function isDisabledEl(el) {
		return el && ('disabled' in el) && el.disabled;
	}

	function setFieldsetEdit(fieldset, enable) {
		const controls = fieldset.querySelectorAll('input, select, textarea, button');
		controls.forEach(el => {
			if (el.classList.contains('asset-edit') || el.classList.contains('asset-remove')) return;
			if (!('disabled' in el)) return;
			if (enable) {
				el.disabled = false;
			} else {
				el.disabled = true;
			}
		});
	}

	function setFieldsetRemoved(fieldset, removed) {
		const inpRemove = fieldset.querySelector('input[name$="[remove]"]');
		if (inpRemove) {
			inpRemove.disabled = false;
			inpRemove.value = removed ? '1' : '0';
		}

		const inputs = fieldset.querySelectorAll('input, select, textarea');
		inputs.forEach(el => {
			if (!('disabled' in el)) return;
			if (el === inpRemove) return;
			el.disabled = removed ? true : el.disabled;
		});

		fieldset.classList.toggle('muted', removed);
	}

	function replaceIdTokens(root, idToken) {
		root.querySelectorAll('[name]').forEach(el => {
			el.name = el.name.replaceAll('__ID__', idToken);
		});
	}

	function createFieldsetFromTemplate(templateEl, kind, key, idToken) {
		if (!templateEl) {
			return null;
		}

		const frag = templateEl.content.cloneNode(true);
		const fieldset = frag.querySelector('fieldset');
		if (!fieldset) {
			console.error('Template senza fieldset:', templateEl);
			return null;
		}

		replaceIdTokens(fieldset, idToken);

		fieldset.dataset.kind = kind;
		fieldset.dataset.key = key;
		fieldset.dataset.dirty = '0';

		return fieldset;
	}

	function fillCommonAssetFields(fieldset, asset) {
		const idToken = fieldset.dataset.idToken;

		const setVal = (suffix, v) => {
			const el = fieldset.querySelector(`[name="assets[${idToken}][${suffix}]"]`);
			if (!el) return;
			el.disabled = true;
			el.value = v ?? '';
		};

		setVal('Nome', asset.Nome ?? '');
		setVal('Valuta', asset.Valuta ?? '');
		setVal('TargetPctNelBucket', asset.TargetPctNelBucket ?? '');
		setVal('TaxRate', asset.TaxRatePct != null ? (asset.TaxRatePct * 100) : '');

		const idBucketEl = fieldset.querySelector(`[name="assets[${idToken}][ID_Bucket]"]`);
		if (idBucketEl) {
			idBucketEl.disabled = true;
			idBucketEl.value = asset.ID_Bucket;
		}
	}

	function wireFieldsetActions(fieldset) {
		const btnEdit = fieldset.querySelector('.asset-edit');
		const btnRemove = fieldset.querySelector('.asset-remove');

		if (btnEdit) {
			btnEdit.addEventListener('click', () => {
				const anyEnabled = Array.from(fieldset.querySelectorAll('input, select, textarea')).some(el => !isDisabledEl(el));
				const enable = !anyEnabled;

				setFieldsetEdit(fieldset, enable);
				if (enable) setDirty(fieldset);
			});
		}

		if (btnRemove) {
			btnRemove.addEventListener('click', () => {
				const inpRemove = fieldset.querySelector('input[name$="[remove]"]');
				const now = !(inpRemove && inpRemove.value === '1');
				setFieldsetRemoved(fieldset, now);
				setDirty(fieldset);
			});
		}

		fieldset.addEventListener('input', (e) => {
			const t = e.target;
			if (!t || !t.name) return;
			setDirty(fieldset);
		});
	}

	function addBucketFieldset(bucket, isNew) {
		const idToken = isNew ? ('NB_' + nextTempId()) : ('B_' + bucket.ID_Bucket);
		const key = isNew ? ('NB:' + idToken) : ('B:' + bucket.ID_Bucket);

		const fieldset = createFieldsetFromTemplate(tplBucket, 'bucket', key, idToken);
		fieldset.dataset.idToken = idToken;

		const nomeEl = fieldset.querySelector(`[name="assets[${idToken}][Nome]"]`);
		const tgtEl = fieldset.querySelector(`[name="assets[${idToken}][TargetPctSuPadre]"]`);
		const idEl = fieldset.querySelector(`[name="assets[${idToken}][ID_Bucket]"]`);
		const newEl = fieldset.querySelector(`[name="assets[${idToken}][new]"]`);
		const remEl = fieldset.querySelector(`[name="assets[${idToken}][remove]"]`);

		if (nomeEl) { nomeEl.disabled = true; nomeEl.value = bucket.Nome ?? ''; }
		if (tgtEl) { tgtEl.disabled = true; tgtEl.value = bucket.TargetPctSuPadre ?? ''; }
		if (idEl) { idEl.disabled = true; idEl.value = bucket.ID_Bucket ?? ''; }
		if (newEl) { newEl.disabled = true; newEl.value = isNew ? '1' : '0'; }
		if (remEl) { remEl.disabled = true; remEl.value = '0'; }

		wireFieldsetActions(fieldset);

		dialogBody.appendChild(fieldset);

		if (isNew) {
			setFieldsetEdit(fieldset, true);
			setDirty(fieldset);
		}
	}

	function addAzioneFieldset(asset, isNew) {
		const idToken = isNew ? ('NA_' + nextTempId()) : ('A_' + asset.ID_Bucket + '_' + asset.ISIN);
		const key = isNew ? ('NA:' + idToken) : ('A:' + asset.ID_Bucket + ':' + asset.ISIN);

		const fieldset = createFieldsetFromTemplate(tplAzione, 'azione', key, idToken);
		fieldset.dataset.idToken = idToken;

		const isinEl = fieldset.querySelector(`[name="assets[${idToken}][ISIN]"]`);
		if (isinEl) { isinEl.disabled = true; isinEl.value = asset.ISIN ?? ''; }

		const idBucketEl = fieldset.querySelector(`[name="assets[${idToken}][ID_Bucket]"]`);
		if (idBucketEl) { idBucketEl.disabled = true; idBucketEl.value = asset.ID_Bucket; }

		const newEl = fieldset.querySelector(`[name="assets[${idToken}][new]"]`);
		const remEl = fieldset.querySelector(`[name="assets[${idToken}][remove]"]`);
		if (newEl) { newEl.disabled = true; newEl.value = isNew ? '1' : '0'; }
		if (remEl) { remEl.disabled = true; remEl.value = '0'; }

		fillCommonAssetFields(fieldset, asset);
		wireFieldsetActions(fieldset);

		dialogBody.appendChild(fieldset);

		if (isNew) {
			setFieldsetEdit(fieldset, true);
			setDirty(fieldset);
		}
	}
		function addEtfFieldset(asset, isNew) {
		const idToken = isNew ? ('NA_' + nextTempId()) : ('A_' + asset.ID_Bucket + '_' + asset.ISIN);
		const key = isNew ? ('NA:' + idToken) : ('A:' + asset.ID_Bucket + ':' + asset.ISIN);

		const fieldset = createFieldsetFromTemplate(tplEtf, 'etf', key, idToken);
		fieldset.dataset.idToken = idToken;

		const tickerEl = fieldset.querySelector(`[name="assets[${idToken}][Ticker]"]`);
		if (tickerEl) { tickerEl.disabled = true; tickerEl.value = asset.Ticker ?? ''; }

		const isinHidden = fieldset.querySelector(`[name="assets[${idToken}][ISIN]"]`);
		if (isinHidden) { isinHidden.disabled = true; isinHidden.value = asset.ISIN ?? ''; }

		const isinDetails = fieldset.querySelector(`[name="assets[${idToken}][ISIN_DETAILS]"]`);
		if (isinDetails) { isinDetails.disabled = true; isinDetails.value = asset.ISIN ?? ''; }

		const terEl = fieldset.querySelector(`[name="assets[${idToken}][TER]"]`);
		if (terEl) { terEl.disabled = true; terEl.value = asset.TER ?? ''; }

		const distEl = fieldset.querySelector(`[name="assets[${idToken}][Distribuzione]"]`);
		if (distEl) { distEl.disabled = true; distEl.value = asset.Distribuzione ?? 'Accumulating'; }

		const indEl = fieldset.querySelector(`[name="assets[${idToken}][Indice]"]`);
		if (indEl) { indEl.disabled = true; indEl.value = asset.Indice ?? ''; }

		const idBucketEl = fieldset.querySelector(`[name="assets[${idToken}][ID_Bucket]"]`);
		if (idBucketEl) { idBucketEl.disabled = true; idBucketEl.value = asset.ID_Bucket; }

		const newEl = fieldset.querySelector(`[name="assets[${idToken}][new]"]`);
		const remEl = fieldset.querySelector(`[name="assets[${idToken}][remove]"]`);
		if (newEl) { newEl.disabled = true; newEl.value = isNew ? '1' : '0'; }
		if (remEl) { remEl.disabled = true; remEl.value = '0'; }

		fillCommonAssetFields(fieldset, asset);
		wireFieldsetActions(fieldset);

		dialogBody.appendChild(fieldset);

		if (isNew) {
			setFieldsetEdit(fieldset, true);
			setDirty(fieldset);
		}
	}

	function addObbFieldset(asset, isNew) {
		const idToken = isNew ? ('NA_' + nextTempId()) : ('A_' + asset.ID_Bucket + '_' + asset.ISIN);
		const key = isNew ? ('NA:' + idToken) : ('A:' + asset.ID_Bucket + ':' + asset.ISIN);

		const fieldset = createFieldsetFromTemplate(tplObb, 'obbligazione', key, idToken);
		fieldset.dataset.idToken = idToken;

		const isinEl = fieldset.querySelector(`[name="assets[${idToken}][ISIN]"]`);
		if (isinEl) { isinEl.disabled = true; isinEl.value = asset.ISIN ?? ''; }

		const idBucketEl = fieldset.querySelector(`[name="assets[${idToken}][ID_Bucket]"]`);
		if (idBucketEl) { idBucketEl.disabled = true; idBucketEl.value = asset.ID_Bucket; }

		const cedEl = fieldset.querySelector(`[name="assets[${idToken}][CedolaPct]"]`);
		if (cedEl) { cedEl.disabled = true; cedEl.value = asset.CedolaPct ?? ''; }

		const freqEl = fieldset.querySelector(`[name="assets[${idToken}][FrequenzaCedola]"]`);
		if (freqEl) { freqEl.disabled = true; freqEl.value = asset.FrequenzaCedola ?? ''; }

		const scadEl = fieldset.querySelector(`[name="assets[${idToken}][Scadenza]"]`);
		if (scadEl) { scadEl.disabled = true; scadEl.value = asset.Scadenza ?? ''; }

		const newEl = fieldset.querySelector(`[name="assets[${idToken}][new]"]`);
		const remEl = fieldset.querySelector(`[name="assets[${idToken}][remove]"]`);
		if (newEl) { newEl.disabled = true; newEl.value = isNew ? '1' : '0'; }
		if (remEl) { remEl.disabled = true; remEl.value = '0'; }

		fillCommonAssetFields(fieldset, asset);
		wireFieldsetActions(fieldset);

		dialogBody.appendChild(fieldset);

		if (isNew) {
			setFieldsetEdit(fieldset, true);
			setDirty(fieldset);
		}
	}

	function addPortfolioInfoFieldset(info, portfolioId) {
		const tpl = mustTemplate(tplInfo, 'template-portfolio-info');
		if (!tpl) return;

		const idToken = 'INFO';
		const key = 'P:' + portfolioId;

		const fieldset = createFieldsetFromTemplate(tpl, 'portfolio', key, idToken);
		if (!fieldset) return;
		fieldset.dataset.idToken = idToken;

		const setVal = (suffix, v) => {
			const el = fieldset.querySelector(`[name="assets[info][${suffix}]"]`);
			if (!el) return;
			el.disabled = true;
			el.value = v ?? '';
		};

		setVal('Liquidita', info.Liquidita ?? '');
		setVal('TargetLiquiditaPct', info.TargetLiquiditaPct ?? '');
		setVal('Tolleranza', info.Tolleranza ?? '');
		setVal('Commissione', info.Commissione ?? '');

		const selTipo = fieldset.querySelector(`[name="assets[info][TipoCommissione]"]`);
		if (selTipo) {
			selTipo.disabled = true;
			selTipo.value = info.TipoCommissione ?? 'Fissa';
		}

		setVal('Valuta', info.Valuta ?? 'EUR');

		wireFieldsetActions(fieldset);
		dialogBody.appendChild(fieldset);
	}

	async function openEditorForBucket(bucketId) {
		if (!dialog || !form || !dialogBody || !inpPortfolioId || !inpScopeBucketId) return;

		const portfolioId = parseInt(inpPortfolioId.value, 10) || 0;
		if (!portfolioId) return;

		editorState.openBucketId = bucketId;
		editorState.tempCounter = 0;
		editorState.dirtyKeys.clear();

		inpScopeBucketId.value = String(bucketId);

		dialogBody.innerHTML = '';

		const url = `lib/getEditorData.php?portfolioId=${encodeURIComponent(portfolioId)}&bucketId=${encodeURIComponent(bucketId)}`;
		const res = await fetch(url, { credentials: 'same-origin' });
		if (!res.ok) {
			alert('Errore caricamento dati');
			return;
		}

		const json = await res.json();
		if (!json || !json.ok) {
			alert('Errore caricamento dati');
			return;
		}

		editorState.isRoot = !!json.isRoot;

		if (json.isRoot && json.portfolioInfo) {
			addPortfolioInfoFieldset(json.portfolioInfo, portfolioId);
		}

		(json.childBuckets || []).forEach(b => addBucketFieldset(b, false));

		(json.assets || []).forEach(a => {
			if (a.Tipo === 'ETF') {
				addEtfFieldset(a, false);
			} else if (a.Tipo === 'Obbligazione') {
				addObbFieldset(a, false);
			} else {
				addAzioneFieldset(a, false);
			}
		});

		dialog.showModal();
	}

	document.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-open-assets][data-bucket-id]');
		if (!btn) return;

		const bucketId = parseInt(btn.dataset.bucketId, 10) || 0;
		if (!bucketId) return;

		openEditorForBucket(bucketId);
	});

	btnAddBucket?.addEventListener('click', () => {
		if (!editorState.openBucketId) return;
		addBucketFieldset({ ID_Bucket: '', Nome: '', TargetPctSuPadre: '' }, true);
	});

	btnAddAzione?.addEventListener('click', () => {
		if (!editorState.openBucketId) return;
		addAzioneFieldset({
			ID_Bucket: editorState.openBucketId,
			ISIN: '',
			Nome: '',
			TargetPctNelBucket: '',
			TaxRatePct: null,
			Valuta: 'EUR'
		}, true);
	});

	btnAddEtf?.addEventListener('click', () => {
		if (!editorState.openBucketId) return;
		addEtfFieldset({
			ID_Bucket: editorState.openBucketId,
			ISIN: '',
			Ticker: '',
			Nome: '',
			TargetPctNelBucket: '',
			TaxRatePct: null,
			Valuta: 'EUR',
			TER: '',
			Distribuzione: 'Accumulating',
			Indice: ''
		}, true);
	});

	btnAddObb?.addEventListener('click', () => {
		if (!editorState.openBucketId) return;
		addObbFieldset({
			ID_Bucket: editorState.openBucketId,
			ISIN: '',
			Nome: '',
			TargetPctNelBucket: '',
			TaxRatePct: null,
			Valuta: 'EUR',
			CedolaPct: '',
			FrequenzaCedola: '',
			Scadenza: ''
		}, true);
	});

	btnCancel?.addEventListener('click', () => {
		if (!dialog) return;
		dialog.close();
	});

	dialog?.addEventListener('close', () => {
		if (!dialogBody) return;
		dialogBody.innerHTML = '';
		editorState.openBucketId = null;
		editorState.dirtyKeys.clear();
	});

	form?.addEventListener('submit', (e) => {
		const dirtyFieldsets = dialogBody ? dialogBody.querySelectorAll('fieldset[data-dirty="1"]') : [];
		if (!dirtyFieldsets || dirtyFieldsets.length === 0) {
			e.preventDefault();
			dialog?.close();
			return;
		}

		const payload = {
			portfolioId: parseInt(inpPortfolioId?.value ?? '0', 10) || 0,
			scopeBucketId: parseInt(inpScopeBucketId?.value ?? '0', 10) || 0,
			changes: []
		};

		dirtyFieldsets.forEach(fs => {
			const idToken = fs.dataset.idToken;
			const key = fs.dataset.key || '';
			const kind = fs.dataset.kind || '';

			const obj = { key, kind, fields: {} };

			const controls = fs.querySelectorAll('input[name], select[name], textarea[name]');
			controls.forEach(el => {
				const name = el.name;
				if (!name) return;

				const m = name.match(/^assets\[(.+?)\]\[(.+?)\]$/);
				if (!m) return;

				const token = m[1];
				const field = m[2];

				if (token !== idToken && !(idToken === 'INFO' && token === 'info')) return;

				obj.fields[field] = el.value;
			});

			payload.changes.push(obj);
		});

		e.preventDefault();

		const hidden = document.createElement('input');
		hidden.type = 'hidden';
		hidden.name = 'payload';
		hidden.value = JSON.stringify(payload);

		form.querySelectorAll('input[name="payload"]').forEach(n => n.remove());
		form.appendChild(hidden);

		form.submit();
	});

    generaGrafico(0);
});
