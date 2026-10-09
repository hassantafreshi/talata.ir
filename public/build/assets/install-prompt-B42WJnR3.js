var e=`pwa-install-snooze`;function t({standalone:e,snoozedUntil:t,now:n,ua:r,hasBip:i}){return e||t&&n<t?`none`:/iphone|ipad|ipod/i.test(r)||/Macintosh/i.test(r)&&/Mobile|Touch/i.test(r)?`ios`:i?`android`:`none`}function n(){try{return+(localStorage.getItem(e)||0)||0}catch{return 0}}function r(){try{localStorage.setItem(e,String(Date.now()+12096e5))}catch{}}function i(e){let t=document.createElement(`div`);return t.className=`install-banner`,t.setAttribute(`role`,`dialog`),t.setAttribute(`aria-label`,`نصب برنامه زرلیو`),t.innerHTML=e,document.body.appendChild(t),t.querySelector(`[data-close]`)?.addEventListener(`click`,()=>{r(),t.remove()}),t}function a(e){let t=i(`
    <div class="install-body">
      <strong>نصب زرلیو روی گوشی</strong>
      <span class="small">سریع‌تر باز می‌شود و مثل یک برنامه کار می‌کند.</span>
    </div>
    <div class="install-actions">
      <button class="btn btn-gold sm" type="button" data-install>نصب</button>
      <button class="btn btn-link sm" type="button" data-close>بعداً</button>
    </div>`);t.querySelector(`[data-install]`)?.addEventListener(`click`,async()=>{try{e.prompt(),await e.userChoice}catch{}window.__bip=null,t.remove(),r()})}function o(){i(`
    <div class="install-body">
      <strong>افزودن زرلیو به صفحه اصلی</strong>
      <ol class="install-steps small">
        <li>در نوار پایین مرورگر، دکمه «اشتراک‌گذاری» را بزنید
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M5 12v7a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7"/></svg>
        </li>
        <li>گزینه «Add to Home Screen / افزودن به صفحه اصلی» را انتخاب کنید.</li>
        <li>روی «افزودن» بزنید؛ آیکون زرلیو روی صفحه اصلی ساخته می‌شود.</li>
      </ol>
    </div>
    <div class="install-actions">
      <button class="btn btn-link sm" type="button" data-close>متوجه شدم</button>
    </div>`)}function s(){let e=window.matchMedia&&window.matchMedia(`(display-mode: standalone)`).matches||window.navigator.standalone===!0,r=r=>t({standalone:e,snoozedUntil:n(),now:Date.now(),ua:navigator.userAgent||``,hasBip:r}),i=r(!!window.__bip);if(i===`ios`){o();return}if(i===`android`){a(window.__bip);return}!e&&n()<=Date.now()&&window.addEventListener(`beforeinstallprompt`,e=>{e.preventDefault(),window.__bip=e,r(!0)===`android`&&a(e)},{once:!0})}export{s as default};