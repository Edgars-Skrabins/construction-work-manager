for (const calendar of document.querySelectorAll('[data-calendar]')) {
    calendar.classList.add('calendar-enhanced');
}

document.addEventListener('change', (event) => {
    const form = event.target.closest('form[data-calendar-picker]');
    if (form === null) {
        return;
    }
    if (event.target.matches('select') || form.checkValidity()) {
        form.requestSubmit();
    }
});

document.getElementById('calendar-modal')?.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) {
        return;
    }
    const modal = event.currentTarget;
    modal.querySelector('.modal-title').textContent = trigger.dataset.calendarTitle;
    const details = trigger.dataset.calendarEntries.split(' ').map((id) => document.getElementById(`calendar-entry-${id}`)?.content.cloneNode(true));
    modal.querySelector('.modal-body').replaceChildren(...details.filter(Boolean));
});
