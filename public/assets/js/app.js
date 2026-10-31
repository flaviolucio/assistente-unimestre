(function () {
    const button = document.getElementById('btn-recarregar-diagnostico');

    if (!button) {
        return;
    }

    button.addEventListener('click', function () {
        button.disabled = true;
        button.textContent = 'Verificando...';
        window.location.reload();
    });
})();