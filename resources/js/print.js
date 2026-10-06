// Tiny print helper (no app bundle on the A4 page).
import '../css/invoice.css';

document.addEventListener('DOMContentLoaded', () => {
  document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
  if (new URLSearchParams(location.search).has('auto')) setTimeout(() => window.print(), 400);
});
