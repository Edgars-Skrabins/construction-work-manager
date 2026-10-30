const initAssignmentForm = (root) => {
    const employee = root.querySelector('#employee');
    const site = root.querySelector('#constructionSite');
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
};

document.addEventListener('app:content-loaded', (event) => initAssignmentForm(event.target));

document.addEventListener('DOMContentLoaded', () => {
    new bootstrap.Tooltip(document.body, {selector: '[data-bs-toggle="tooltip"]'});

    const employee = document.getElementById('employee');
    if (employee !== null && window.location.hash === '#employee') {
        employee.focus();
    }

    initAssignmentForm(document);
});
