// The authenticated host has already validated PP membership and the return target.
window.ordinaryPuzzlesStorage.onExit = () => {
    let target = '/experiences/ordinary-puzzles';
    try {
        const link = parent.document.getElementById('webdoor-return');
        if (link) {
            const url = new URL(link.href, location.origin);
            if (url.origin === location.origin) target = url.pathname + url.search + url.hash;
        }
    } catch { /* Standalone authenticated entry uses the direct Experience return. */ }
    parent.location.assign(target);
};
