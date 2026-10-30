document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[data-instant-search]');
    const input = form?.querySelector('input[name="q"]');
    if (!form || !input || document.querySelector('[data-list-results]') === null) {
        return;
    }

    let timer = null;
    let controller = null;
    let latest = 0;
    let requested = window.location.href;

    const searchUrl = () => {
        const url = new URL(window.location.href);
        const q = input.value.trim();
        if (q === '') {
            url.searchParams.delete('q');
        } else {
            url.searchParams.set('q', q);
        }
        url.searchParams.delete('page');
        return url.toString();
    };

    const apply = (fetched, url) => {
        const results = document.querySelector('[data-list-results]');
        const newResults = fetched.querySelector('[data-list-results]');
        results.replaceWith(newResults);
        const clear = form.querySelector('[data-list-clear]');
        const newClear = fetched.querySelector('[data-list-clear]');
        if (clear && newClear) {
            clear.replaceWith(newClear);
        }
        const status = document.querySelector('[data-list-status]');
        const newStatus = fetched.querySelector('[data-list-status]');
        if (status && newStatus) {
            status.textContent = newStatus.textContent;
        }
        window.history.replaceState(window.history.state, '', url);
    };

    const search = async () => {
        window.clearTimeout(timer);
        const url = searchUrl();
        if (url === requested) {
            return;
        }
        requested = url;
        controller?.abort();
        controller = new AbortController();
        const request = ++latest;
        document.querySelector('[data-list-results]').setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {Accept: 'text/html'},
            });
            const text = response.ok ? await response.text() : '';
            if (request !== latest) {
                return;
            }
            const fetched = new DOMParser().parseFromString(text, 'text/html');
            if (!response.ok || fetched.querySelector('[data-list-results]') === null) {
                window.location.assign(url);
                return;
            }
            apply(fetched, url);
        } catch (error) {
            if (error.name !== 'AbortError' && request === latest) {
                window.location.assign(url);
            }
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 300);
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        search();
    });

    form.addEventListener('click', (event) => {
        const link = event.target.closest('[data-list-clear] a');
        if (link === null) {
            return;
        }
        event.preventDefault();
        input.value = '';
        search();
        input.focus();
    });
});
