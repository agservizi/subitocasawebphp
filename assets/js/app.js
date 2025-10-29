(function () {
    var form = document.querySelector('form');
    var submitBtn = document.getElementById('submitBtn');
    if (!form || !submitBtn) {
        return;
    }
    form.addEventListener('submit', function () {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Invio in corso...';
        setTimeout(function () {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Invia segnalazione';
        }, 8000);
    });
})();
