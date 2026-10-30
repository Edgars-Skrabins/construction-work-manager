(() => {
    const element = document.querySelector('[data-row-actions-sheet]');
    if (element === null) {
        return;
    }

    const compact = window.matchMedia('(max-width: 1199.98px)');
    const sheet = bootstrap.Modal.getOrCreateInstance(element);
    const title = element.querySelector('.modal-title');
    const body = element.querySelector('.modal-body');
    let chosen = null;
    let lastRow = null;

    const rowOf = (target) => {
        const row = target.closest('[data-list-results] tbody tr');
        return row !== null && row.querySelector('.list-row-actions') !== null ? row : null;
    };

    const apply = () => {
        document.querySelectorAll('[data-list-results] table').forEach((table) => {
            table.classList.toggle('list-compact', compact.matches);
        });
        document.querySelectorAll('[data-list-results] tbody tr').forEach((row) => {
            const group = row.querySelector('.list-row-actions');
            if (group === null) {
                return;
            }
            if (compact.matches) {
                row.tabIndex = 0;
            } else {
                row.removeAttribute('tabindex');
            }
            row.querySelectorAll('.list-row-actions a, .list-filter-link').forEach((link) => {
                if (compact.matches) {
                    link.tabIndex = -1;
                } else {
                    link.removeAttribute('tabindex');
                }
            });
        });
    };

    const choice = (link, className, text) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = className;
        item.textContent = text;
        item.addEventListener('click', () => {
            chosen = link;
            sheet.hide();
        });
        return item;
    };

    const open = (row) => {
        const group = row.querySelector('.list-row-actions');
        lastRow = row;
        title.textContent = group.getAttribute('aria-label');
        const actions = Array.from(group.querySelectorAll('a'), (link) => choice(link, `${link.className.replace(' btn-sm', '')} w-100`, link.textContent));
        const filters = Array.from(row.querySelectorAll('.list-filter-link'), (link) => choice(link, 'btn btn-outline-secondary w-100 text-start text-truncate', link.dataset.filterLabel));
        body.replaceChildren(...actions, ...(filters.length > 0 ? [document.createElement('hr'), ...filters] : []));
        sheet.show();
    };

    document.addEventListener('click', (event) => {
        if (!compact.matches || event.target.closest('a, button, input, select, label') !== null) {
            return;
        }
        const row = rowOf(event.target);
        if (row !== null) {
            open(row);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!compact.matches || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }
        const row = event.target.matches('tr') ? rowOf(event.target) : null;
        if (row !== null) {
            event.preventDefault();
            open(row);
        }
    });

    element.addEventListener('hidden.bs.modal', () => {
        if (chosen !== null) {
            const link = chosen;
            chosen = null;
            link.click();
            return;
        }
        lastRow?.focus();
    });

    document.addEventListener('focusin', (event) => {
        if (compact.matches && event.target.matches('.list-row-actions a, .list-filter-link')) {
            rowOf(event.target)?.focus();
        }
    });

    compact.addEventListener('change', apply);
    document.querySelectorAll('[data-list-results]').forEach((results) => {
        new MutationObserver(apply).observe(results, {childList: true, subtree: true});
    });
    apply();
})();
