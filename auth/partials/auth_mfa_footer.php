<?php
?>

<p class="text-center text-[10px] text-gray-600 mt-8 uppercase tracking-[0.3em]">©MEeL - 2025</p>
</main>

<script src="../assets/js/compatibilitas/sweetalert2.all.min.js"></script>
<script>    lucide.createIcons();
    const mfaCodeInput = document.getElementById('code');
    if (mfaCodeInput) {
        mfaCodeInput.focus();
        mfaCodeInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            if (this.value.length === 6) {
                setTimeout(() => {
                    if (this.form) this.form.submit();
                }, 300);
            }
        });
        mfaCodeInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && this.value.length >= 6) {
                this.form.submit();
            }
        });
    }
</script>
</body>

</html>
