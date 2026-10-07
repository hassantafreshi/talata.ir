// Iranian mobiles in free text — mirror of App\Support\Mobile::extractAll (the server re-parses and decides).
// +98 9…, 0098 9…, 98 9…, 09… or 9… with the exact digit count; Persian/Arabic digits; any separators or none:
// «0912123456709351234567» is two numbers. Shared examples: tests/fixtures/mobile-extract-vectors.json.
const PATTERN = /^09(0[0-5]|1\d|2[0-2]|3\d|41|9\d)\d{7}$/;
const FORMS = [['+98', 13], ['0098', 14], ['09', 11], ['98', 12], ['9', 10]];
const FA = '۰۱۲۳۴۵۶۷۸۹';
const AR = '٠١٢٣٤٥٦٧٨٩';

export function extractMobiles(raw) {
  const stream = String(raw ?? '')
    .replace(/[۰-۹]/g, (d) => String(FA.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String(AR.indexOf(d)))
    .replace(/[^0-9+]/g, '');
  const found = [];
  const invalid = [];
  let junk = '';
  let i = 0;
  while (i < stream.length) {
    let match = null;
    for (const [prefix, len] of FORMS) {
      if (!stream.startsWith(prefix, i) || i + len > stream.length) continue;
      const candidate = `0${stream.slice(i + len - 10, i + len)}`;
      if (PATTERN.test(candidate) && !stream.slice(i + 1, i + len).includes('+')) { match = [candidate, len]; break; }
    }
    if (!match) { junk += stream[i]; i += 1; continue; }
    if (junk) { invalid.push(junk); junk = ''; }
    if (!found.includes(match[0])) found.push(match[0]);
    i += match[1];
  }
  if (junk) invalid.push(junk);
  return { mobiles: found, invalid };
}

/** «۰۹۱۲ ۱۲۳ ۴۵۶۷» for display. */
export function displayMobile(m) {
  return `${m.slice(0, 4)} ${m.slice(4, 7)} ${m.slice(7)}`.replace(/[0-9]/g, (d) => FA[Number(d)]);
}
