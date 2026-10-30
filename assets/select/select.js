(() => {
    const visibleRows = 8;
    const pageSize = 20;
    const enhanced = new WeakSet();
    const pickers = new Map();
    let generatedId = 0;

    const normalize = (text) => text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

    const element = (tag, className, attributes = {}) => {
        const node = document.createElement(tag);
        node.className = className;
        for (const [name, value] of Object.entries(attributes)) {
            node.setAttribute(name, value);
        }
        return node;
    };

    const enhance = (select) => {
        if (enhanced.has(select) || select.multiple || select.size > 1) {
            return;
        }
        enhanced.add(select);

        if (select.id === '') {
            generatedId += 1;
            select.id = `select-picker-${generatedId}`;
        }
        const id = select.id;
        const texts = document.body.dataset;
        const label = document.querySelector(`label[for="${CSS.escape(id)}"]`);
        if (label !== null && label.id === '') {
            label.id = `${id}-label`;
        }

        const wrapper = element('div', 'dropdown select-picker');
        const toggle = element('button', '', {
            type: 'button',
            id: `${id}-picker`,
            'aria-haspopup': 'listbox',
            'aria-expanded': 'false',
            'aria-controls': `${id}-picker-list`,
        });
        const value = element('span', 'select-picker-value');
        toggle.append(value);
        const menu = element('div', 'dropdown-menu select-picker-menu');
        const list = element('ul', 'select-picker-list', {role: 'listbox', id: `${id}-picker-list`, tabindex: '-1'});
        const empty = element('p', 'select-picker-empty', {role: 'status'});
        let search = null;
        if (select.options.length > visibleRows) {
            search = element('input', 'form-control form-control-sm select-picker-search', {
                type: 'search',
                role: 'combobox',
                'aria-autocomplete': 'list',
                'aria-expanded': 'true',
                'aria-controls': list.id,
                'aria-label': texts.selectSearch ?? 'Search',
                placeholder: texts.selectSearch ?? 'Search',
                autocomplete: 'off',
                spellcheck: 'false',
            });
            menu.append(search);
        }
        menu.append(list, empty);
        wrapper.append(toggle, menu);

        if (label !== null) {
            toggle.setAttribute('aria-labelledby', `${label.id} ${toggle.id}`);
            list.setAttribute('aria-labelledby', label.id);
        } else if (select.hasAttribute('aria-label')) {
            toggle.setAttribute('aria-label', select.getAttribute('aria-label'));
            list.setAttribute('aria-label', select.getAttribute('aria-label'));
        }

        const parentDisplay = select.parentElement ? getComputedStyle(select.parentElement).display : '';
        if (parentDisplay.includes('flex')) {
            const width = select.style.width;
            select.style.width = 'auto';
            if (select.offsetWidth > 0) {
                wrapper.style.width = `${select.offsetWidth}px`;
            }
            select.style.width = width;
        }

        select.classList.add('visually-hidden');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        select.after(wrapper);

        const focusTarget = () => search ?? list;
        const isOpen = () => menu.classList.contains('show');
        const items = () => [...list.querySelectorAll('[role="option"]')];
        const enabledItems = () => items().filter((item) => item.getAttribute('aria-disabled') !== 'true');
        const optionOf = new WeakMap();
        let highlighted = null;

        const highlight = (item, scroll = true) => {
            highlighted?.classList.remove('is-highlighted');
            highlighted = item;
            if (item === null) {
                focusTarget().removeAttribute('aria-activedescendant');
                return;
            }
            item.classList.add('is-highlighted');
            focusTarget().setAttribute('aria-activedescendant', item.id);
            if (scroll) {
                const bottom = item.offsetTop + item.offsetHeight;
                if (item.offsetTop < list.scrollTop) {
                    list.scrollTop = item.offsetTop;
                } else if (bottom > list.scrollTop + list.clientHeight) {
                    list.scrollTop = bottom - list.clientHeight;
                }
            }
        };

        let pending = [];
        let total = 0;

        const appendItems = (count) => {
            for (const [option, index] of pending.splice(0, count)) {
                const item = element('li', 'dropdown-item select-picker-option', {
                    role: 'option',
                    id: `${id}-picker-option-${index}`,
                    'aria-selected': option.selected ? 'true' : 'false',
                    'aria-posinset': String(list.children.length + 1),
                    'aria-setsize': String(total),
                });
                if (option.value === '') {
                    item.classList.add('select-picker-placeholder');
                }
                if (option.disabled) {
                    item.setAttribute('aria-disabled', 'true');
                }
                item.textContent = option.text;
                optionOf.set(item, option);
                list.append(item);
            }
        };

        const render = () => {
            const query = normalize((search?.value ?? '').trim());
            list.replaceChildren();
            list.scrollTop = 0;
            highlighted = null;
            pending = [...select.options]
                .map((option, index) => [option, index])
                .filter(([option]) => !option.hidden && (query === '' || (option.value !== '' && normalize(option.text).includes(query))));
            total = pending.length;
            const chosen = query === '' ? pending.findIndex(([option]) => option.selected && !option.disabled) : -1;
            appendItems(Math.max(pageSize, chosen + 1));
            empty.textContent = total === 0 ? (texts.selectNoMatches ?? 'No matches') : '';
            const selected = chosen === -1 ? null : list.querySelector('[aria-selected="true"]:not([aria-disabled="true"])');
            highlight(selected ?? enabledItems()[0] ?? null);
        };

        const place = () => {
            wrapper.classList.remove('select-picker-up');
            toggle.scrollIntoView({block: 'nearest'});
            const bounds = toggle.getBoundingClientRect();
            const below = window.innerHeight - bounds.bottom;
            if (below < menu.offsetHeight && bounds.top > below) {
                wrapper.classList.add('select-picker-up');
            }
        };

        const open = (typed = '') => {
            if (select.disabled || isOpen()) {
                return;
            }
            if (search !== null) {
                search.value = typed;
            }
            menu.classList.add('show');
            toggle.setAttribute('aria-expanded', 'true');
            pickers.set(wrapper, close);
            render();
            place();
            focusTarget().focus({preventScroll: true});
            menu.scrollIntoView({block: 'nearest'});
        };

        function close(restoreFocus = false) {
            if (!isOpen()) {
                return;
            }
            menu.classList.remove('show');
            toggle.setAttribute('aria-expanded', 'false');
            pickers.delete(wrapper);
            if (search !== null) {
                search.value = '';
            }
            highlight(null);
            if (restoreFocus) {
                toggle.focus();
            }
        }

        const refresh = () => {
            const classes = [...select.classList].filter((name) => name !== 'visually-hidden');
            toggle.className = [...classes, 'select-picker-toggle'].join(' ');
            toggle.disabled = select.disabled;
            if (select.classList.contains('is-invalid')) {
                toggle.setAttribute('aria-invalid', 'true');
            } else {
                toggle.removeAttribute('aria-invalid');
            }
            if (select.hasAttribute('aria-describedby')) {
                toggle.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
            } else {
                toggle.removeAttribute('aria-describedby');
            }
            value.textContent = select.selectedOptions[0]?.text ?? '';
            if (select.disabled) {
                close();
            } else if (isOpen()) {
                render();
            }
        };

        const choose = (item) => {
            const option = optionOf.get(item);
            if (option === undefined || option.disabled) {
                return;
            }
            select.value = option.value;
            select.dispatchEvent(new Event('input', {bubbles: true}));
            select.dispatchEvent(new Event('change', {bubbles: true}));
            refresh();
            close(true);
        };

        const move = (step) => {
            let enabled = enabledItems();
            while (step > 0 && pending.length > 0 && enabled.indexOf(highlighted) >= enabled.length - 1) {
                appendItems(pageSize);
                enabled = enabledItems();
            }
            if (enabled.length === 0) {
                return;
            }
            const index = enabled.indexOf(highlighted);
            const next = index === -1 ? 0 : Math.min(Math.max(index + step, 0), enabled.length - 1);
            highlight(enabled[next]);
        };

        select.addEventListener('focus', () => toggle.focus());
        select.addEventListener('change', refresh);

        toggle.addEventListener('click', () => (isOpen() ? close(true) : open()));
        toggle.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                open();
            } else if (search !== null && event.key.length === 1 && event.key !== ' ' && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                open(event.key);
            }
        });

        menu.addEventListener('keydown', (event) => {
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    move(1);
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    move(-1);
                    break;
                case 'Home':
                case 'End':
                    if (search === null) {
                        event.preventDefault();
                        appendItems(pending.length);
                        const enabled = enabledItems();
                        highlight((event.key === 'Home' ? enabled[0] : enabled[enabled.length - 1]) ?? null);
                    }
                    break;
                case 'Enter':
                    event.preventDefault();
                    if (highlighted !== null) {
                        choose(highlighted);
                    }
                    break;
                case 'Escape':
                    event.preventDefault();
                    event.stopPropagation();
                    close(true);
                    break;
                case 'Tab':
                    close();
                    break;
            }
        });
        menu.addEventListener('mousedown', (event) => {
            if (event.target !== search) {
                event.preventDefault();
            }
        });
        list.addEventListener('click', (event) => {
            const item = event.target.closest('[role="option"]');
            if (item !== null) {
                choose(item);
            }
        });
        list.addEventListener('mousemove', (event) => {
            const item = event.target.closest('[role="option"]');
            if (item !== null && item !== highlighted && item.getAttribute('aria-disabled') !== 'true') {
                highlight(item, false);
            }
        });
        list.addEventListener('scroll', () => {
            if (pending.length > 0 && list.scrollTop + 2 * list.clientHeight >= list.scrollHeight) {
                appendItems(pageSize);
            }
        });
        search?.addEventListener('input', render);
        wrapper.addEventListener('focusout', (event) => {
            if (!wrapper.contains(event.relatedTarget)) {
                close();
            }
        });

        new MutationObserver(refresh).observe(select, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['disabled', 'hidden', 'selected', 'label', 'class', 'aria-describedby'],
        });
        refresh();
        if (document.activeElement === select) {
            toggle.focus();
        }
    };

    const enhanceWithin = (root) => {
        if (root instanceof HTMLSelectElement) {
            enhance(root);
        } else if (root instanceof Element && root.closest('.select-picker') === null) {
            root.querySelectorAll('select').forEach(enhance);
        }
    };

    const start = () => {
        enhanceWithin(document.body);
        new MutationObserver((records) => {
            for (const record of records) {
                record.addedNodes.forEach(enhanceWithin);
            }
        }).observe(document.body, {childList: true, subtree: true});
        document.addEventListener('pointerdown', (event) => {
            for (const [wrapper, close] of [...pickers]) {
                if (!wrapper.contains(event.target)) {
                    close();
                }
            }
        }, true);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
