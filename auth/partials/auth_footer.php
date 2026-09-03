<?php


?>

<p class="text-center text-[10px] text-gray-300 mt-8 uppercase tracking-[0.3em]">©MEeL - 2025</p>
</main>

<script>    lucide.createIcons();
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const iconEye = document.getElementById('iconEye');
    const iconEyeOff = document.getElementById('iconEyeOff');
    if (togglePassword) {
        togglePassword.addEventListener('click', function() {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            togglePassword.setAttribute('aria-pressed', String(isHidden));
            togglePassword.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Tampilkan password');
            iconEye.classList.toggle('hidden', !isHidden);
            iconEyeOff.classList.toggle('hidden', isHidden);
        });
    }
</script>
</body>

</html>
