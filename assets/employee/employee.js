document.addEventListener('DOMContentLoaded', () => {
    const role = document.getElementById('role');
    const manager = document.getElementById('managerId');
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
});
