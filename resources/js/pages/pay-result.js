import { get } from '../lib/http.js';

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  if (!boot.pending) return;
  let delay = 3000, tries = 0;
  const tick = async () => {
    tries += 1;
    const res = await get(boot.status_url);
    if (res.ok && res.data.final) { location.reload(); return; }
    delay = Math.min(delay * 1.5, 30000);
    if (tries < 40) setTimeout(tick, delay);
  };
  setTimeout(tick, delay);
}
