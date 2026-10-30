document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.matches('form[data-confirm]') && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const employee = document.getElementById('employee');
    if (employee !== null && window.location.hash === '#employee') {
        employee.focus();
    }

    const site = document.getElementById('constructionSite');
    if (employee === null || site === null) {
        return;
    }

    const filterSites = () => {
        const chosen = employee.selectedOptions[0];
        const level = chosen && chosen.value !== '' ? Number(chosen.dataset.accessLevel) : 0;
        for (const option of site.options) {
            if (option.value === '') {
                continue;
            }
            const allowed = Number(option.dataset.requiredLevel) <= level;
            option.hidden = !allowed;
            option.disabled = !allowed;
        }
        if (site.selectedOptions[0]?.disabled) {
            site.value = '';
        }
    };

    employee.addEventListener('change', filterSites);
    filterSites();
});
