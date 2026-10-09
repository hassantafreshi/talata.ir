{{-- E-Namad trust seal. If the image from trustseal.enamad.ir does not load, /images/enamad.png (a copy of the
     seal hosted on this site, public/images/enamad.png) is shown instead. --}}
<a class="enamad-seal" referrerpolicy="origin" target="_blank" rel="noopener" href="https://trustseal.enamad.ir/?id=8111599&amp;Code=gPWRKPX8Bz7IdJSt8suxm9PW4ja1GbtX" aria-label="نماد اعتماد الکترونیکی">
    <img referrerpolicy="origin" src="https://trustseal.enamad.ir/logo.aspx?id=8111599&amp;Code=gPWRKPX8Bz7IdJSt8suxm9PW4ja1GbtX" alt="نماد اعتماد الکترونیکی" width="90" height="90" loading="lazy" code="gPWRKPX8Bz7IdJSt8suxm9PW4ja1GbtX" data-fallback="/images/enamad.png">
</a>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
(function () {
    var img = document.currentScript.previousElementSibling.querySelector('img');
    function useFallback() { if (!img.dataset.used) { img.dataset.used = '1'; img.src = img.dataset.fallback; } }
    img.addEventListener('error', useFallback);
    if (img.complete && !img.naturalWidth) { useFallback(); }
})();
</script>
