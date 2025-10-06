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
