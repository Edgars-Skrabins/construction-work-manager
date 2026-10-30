(() => {
    const isoDate = /^\d{4}-\d{2}-\d{2}$/;
    const enhanced = new WeakSet();

    const enhance = (wrapper) => {
        if (enhanced.has(wrapper) || !('showPicker' in HTMLInputElement.prototype)) {
            return;
        }
        enhanced.add(wrapper);

        const text = wrapper.querySelector('input');
        const button = wrapper.querySelector('.date-input-button');
        const picker = document.createElement('input');
        picker.type = 'date';
        picker.className = 'date-input-picker';
        picker.tabIndex = -1;
        picker.setAttribute('aria-hidden', 'true');
        wrapper.append(picker);

        button.hidden = false;
        button.addEventListener('click', () => {
            picker.value = isoDate.test(text.value) ? text.value : '';
            try {
                picker.showPicker();
            } catch {
                text.focus();
            }
        });
        picker.addEventListener('change', () => {
            if (picker.value === '') {
                return;
            }
            text.value = picker.value;
            text.dispatchEvent(new Event('input', {bubbles: true}));
            text.dispatchEvent(new Event('change', {bubbles: true}));
            text.focus();
        });
    };

    const enhanceAll = (root) => root.querySelectorAll('[data-date-input]').forEach(enhance);

    document.addEventListener('app:content-loaded', (event) => enhanceAll(event.target));
    document.addEventListener('DOMContentLoaded', () => enhanceAll(document));
})();
