'use strict';
document.querySelectorAll('[data-toggle-password]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.togglePassword);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Hide' : 'Show';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(show));
    });
});

// Dashboard controls only run when their elements are present.
const periodSelect = document.getElementById('period');
const customDates = document.getElementById('custom-dates');
if (periodSelect && customDates) {
    const updateDates = () => {
        const isCustom = periodSelect.value === 'custom';
        customDates.hidden = !isCustom;
        customDates.querySelectorAll('input').forEach((input) => {
            input.disabled = !isCustom;
            input.required = isCustom;
        });
    };
    periodSelect.addEventListener('change', updateDates);
    updateDates();
}

const collapseButton = document.getElementById('sidebar-collapse');
if (collapseButton) {
    const setCollapsed = (collapsed) => {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        collapseButton.setAttribute('aria-expanded', String(!collapsed));
        collapseButton.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
    };
    try { setCollapsed(localStorage.getItem('profitpocket_sidebar') === 'collapsed'); } catch (_) { /* Storage is optional. */ }
    collapseButton.addEventListener('click', () => {
        const collapsed = !document.body.classList.contains('sidebar-collapsed');
        setCollapsed(collapsed);
        try { localStorage.setItem('profitpocket_sidebar', collapsed ? 'collapsed' : 'expanded'); } catch (_) { /* Storage is optional. */ }
    });
}

const chartWrap = document.querySelector('[data-chart]');
if (chartWrap) {
    const canvas = document.getElementById('financial-chart');
    const fallback = () => {
        canvas.hidden = true;
        document.getElementById('chart-unavailable').hidden = false;
        document.querySelector('.chart-data-table').open = true;
    };
    try {
        if (typeof Chart === 'undefined') throw new Error('Chart dependency unavailable');
        const data = JSON.parse(chartWrap.dataset.chart);
        const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2 });
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Sales', data: data.sales, backgroundColor: '#269967', borderRadius: 3, maxBarThickness: 26 },
                    { label: 'Operating expenses', data: data.expenses, backgroundColor: '#9caaa3', borderRadius: 3, maxBarThickness: 26 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#263b32', padding: 10, callbacks: { label: (context) => `${context.dataset.label}: ${pesos.format(context.parsed.y)}` } }
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: '#839086', maxRotation: 0, maxTicksLimit: 10, font: { size: 10 } } },
                    y: { beginAtZero: true, border: { display: false }, grid: { color: '#eff3f0' }, ticks: { maxTicksLimit: 5, color: '#839086', font: { size: 10 }, callback: (value) => `₱${Number(value).toLocaleString('en-PH')}` } }
                }
            }
        });
    } catch (_) { fallback(); }
}
