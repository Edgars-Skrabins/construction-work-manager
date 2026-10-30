document.addEventListener('DOMContentLoaded', () => {
    const element = document.querySelector('[data-modal]');
    if (element === null) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(element);
    const title = element.querySelector('.modal-title');
    const body = element.querySelector('.modal-body');
    const fields = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])';

    let trigger = null;
    let generation = 0;
    let shown = false;
    let changed = false;
    let pendingFocus = null;

    const focus = (target) => {
        if (shown) {
            target.focus();
        } else {
            pendingFocus = target;
        }
    };

    const showLoading = () => {
        title.textContent = element.dataset.loadingText;
        const spinner = document.createElement('div');
        spinner.className = 'd-flex justify-content-center py-5';
        spinner.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden"></span></div>';
        spinner.querySelector('.visually-hidden').textContent = element.dataset.loadingText;
        body.replaceChildren(spinner);
        body.setAttribute('aria-busy', 'true');
    };

    const render = (content, first) => {
        const heading = content.querySelector('[data-modal-title]');
        title.textContent = heading === null ? '' : heading.textContent.trim();
        heading?.remove();
        body.replaceChildren(...content.childNodes);
        body.removeAttribute('aria-busy');
        body.dispatchEvent(new CustomEvent('app:content-loaded', {bubbles: true}));

        const hash = first && trigger !== null ? new URL(trigger.href).hash : '';
        const target = (hash !== '' ? body.querySelector('#' + CSS.escape(hash.slice(1))) : null)
            ?? body.querySelector('.is-invalid')
            ?? body.querySelector(fields)
            ?? element;
        focus(target);
    };

    const handle = async (response, url, current, first, followed) => {
        if (current !== generation) {
            return;
        }
        const location = response.status === 204 ? response.headers.get('X-Modal-Location') : null;
        if (location !== null) {
            changed = true;
            const target = new URL(location, window.location.href);
            if (target.pathname === window.location.pathname) {
                window.location.reload();
            } else if (followed) {
                window.location.assign(target.href);
            } else {
                await request(target.href, {}, current, false, true);
            }
            return;
        }

        const html = await response.text();
        if (current !== generation) {
            return;
        }
        const content = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-modal-content]');
        if (content === null) {
            window.location.assign(url);
            return;
        }
        render(content, first);
    };

    const request = async (url, options, current, first, followed) => {
        try {
            const response = await fetch(url, {
                ...options,
                credentials: 'same-origin',
                headers: {Accept: 'text/html', 'X-Modal': '1'},
            });
            await handle(response, url, current, first, followed);
        } catch {
            window.location.assign(url);
        }
    };

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-modal-link]');
        if (link === null || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        trigger = link;
        generation += 1;
        showLoading();
        modal.show();
        request(link.href, {}, generation, true, false);
    });

    body.addEventListener('click', (event) => {
        if (event.target.closest('[data-modal-cancel]') !== null) {
            event.preventDefault();
            modal.hide();
        }
    });

    body.addEventListener('submit', (event) => {
        const form = event.target;
        if (form.method.toLowerCase() !== 'post') {
            return;
        }
        event.preventDefault();
        const data = new FormData(form, event.submitter);
        for (const button of form.querySelectorAll('button[type="submit"], button:not([type])')) {
            button.disabled = true;
        }
        body.setAttribute('aria-busy', 'true');
        generation += 1;
        request(form.action, {method: 'POST', body: data}, generation, false, false);
    });

    element.addEventListener('shown.bs.modal', () => {
        shown = true;
        if (pendingFocus !== null) {
            pendingFocus.focus();
            pendingFocus = null;
        }
    });

    element.addEventListener('hidden.bs.modal', () => {
        shown = false;
        pendingFocus = null;
        generation += 1;
        if (changed) {
            window.location.reload();
            return;
        }
        body.replaceChildren();
        body.removeAttribute('aria-busy');
        title.textContent = '';
        if (trigger !== null && document.contains(trigger)) {
            trigger.focus();
        }
    });
});
