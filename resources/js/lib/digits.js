// Persian/Arabic digit normalization and toman formatting (mirrors app/Support/Digits.php and Money.php).
const FA = '۰۱۲۳۴۵۶۷۸۹';
const AR = '٠١٢٣٤٥٦٧٨٩';

export function toLatin(value) {
  let s = String(value ?? '');
  s = s.replace(/[۰-۹]/g, (d) => String(FA.indexOf(d))).replace(/[٠-٩]/g, (d) => String(AR.indexOf(d)));
  s = s.replace(/[٫/]/g, '.').replace(/[٬,،\s‌ ]/g, '');
  return s.trim();
}

export function toPersian(value) {
  return String(value ?? '').replace(/[0-9]/g, (d) => FA[Number(d)]);
}

export function group(intString) {
  const neg = String(intString).startsWith('-');
  const digits = String(intString).replace('-', '');
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
  return (neg ? '−' : '') + toPersian(grouped);
}

/** IRR integer string -> "۲۱٬۵۶۲٬۰۰۰" toman (keeps a .x fraction if any). */
export function toman(irr) {
  const v = BigInt(String(irr ?? '0'));
  const whole = v / 10n;
  const frac = v % 10n;
  return group(whole.toString()) + (frac !== 0n ? '٫' + toPersian((frac < 0n ? -frac : frac).toString()) : '');
}

/** Toman text in any digits -> IRR integer string, or null if invalid. */
export function parseTomanToIrr(raw, allowZero = false) {
  const s = toLatin(raw);
  if (!/^\d{1,16}(\.\d)?$/.test(s)) return null;
  const [w, f = '0'] = s.split('.');
  const irr = BigInt(w) * 10n + BigInt(f);
  if (irr === 0n && !allowZero) return null;
  return irr.toString();
}

/** Decimal text in any digits -> normalized string with at most `scale` decimals, or null. */
export function parseDecimal(raw, scale) {
  const s = toLatin(raw);
  const re = scale > 0 ? new RegExp(`^\\d{1,20}(\\.\\d{1,${scale}})?$`) : /^\d{1,20}$/;
  if (!re.test(s)) return null;
  return s.replace(/^0+(?=\d)/, '');
}
