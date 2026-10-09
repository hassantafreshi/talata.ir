{{-- E-Namad trust seal. If the image from trustseal.enamad.ir does not load, the copy at public/images/enamad.png is shown. --}}
<a class="enamad-seal" referrerpolicy="origin" target="_blank" rel="noopener" href="https://trustseal.enamad.ir/?id=8111599&amp;Code=gPWRKPX8Bz7IdJSt8suxm9PW4ja1GbtX" aria-label="نماد اعتماد الکترونیکی">
    <img referrerpolicy="origin" src="https://trustseal.enamad.ir/logo.aspx?id=8111599&amp;Code=gPWRKPX8Bz7IdJSt8suxm9PW4ja1GbtX" alt="نماد اعتماد الکترونیکی" width="90" height="90" code="gPWRKPX8Bz7IdJSt8suxm9PW4ja1GbtX" data-fallback="/images/enamad.png">
</a>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
(function () {
    var img = document.currentScript.previousElementSibling.querySelector('img');
    function useFallback() { if (!img.dataset.used) { img.dataset.used = '1'; img.src = img.dataset.fallback; } }
    img.addEventListener('error', useFallback);
    if (img.complete && !img.naturalWidth) { useFallback(); }
    setTimeout(function () { if (!img.naturalWidth) { useFallback(); } }, 6000);
})();
</script>
