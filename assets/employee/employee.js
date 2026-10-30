const initEmployeeForm = (root) => {
    const role = root.querySelector('#role');
    const manager = root.querySelector('#managerId');
    if (role === null || manager === null) {
        return;
    }

    const lockManager = () => {
        const locked = role.value === 'admin' || role.value === 'manager';
        if (locked) {
            manager.value = '';
        }
        manager.disabled = locked;
    };

    role.addEventListener('change', lockManager);
    lockManager();
};

document.addEventListener('app:content-loaded', (event) => initEmployeeForm(event.target));

document.addEventListener('DOMContentLoaded', () => initEmployeeForm(document));
