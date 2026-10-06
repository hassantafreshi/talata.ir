// GOLD_IR_V1 / MANUAL_LINE_V1 browser preview. Exact rational arithmetic with BigInt,
// HALF_UP once per posted component (IRR_LINE_HALF_UP_V1). Mirrors app/Domain/Pricing/GoldIrV1.php.
// The server always recomputes on save and issue; this is a labelled local preview.

const SCOPES = ['TAXABLE_COMPONENTS', 'WAGE', 'PROFIT'];

class Rat {
  constructor(n, d = 1n) {
    if (d < 0n) { n = -n; d = -d; }
    this.n = n; this.d = d;
  }
  static of(dec) {
    const s = String(dec);
    const [w, f = ''] = s.split('.');
    return new Rat(BigInt(w + f), 10n ** BigInt(f.length));
  }
  mul(o) { return new Rat(this.n * o.n, this.d * o.d); }
  div(o) { return new Rat(this.n * o.d, this.d * o.n); }
  add(o) { return new Rat(this.n * o.d + o.n * this.d, this.d * o.d); }
  roundHalfUp() { // values are non-negative here
    return (2n * this.n + this.d) / (2n * this.d);
  }
  toFixed(scale) {
    const f = 10n ** BigInt(scale);
    const v = (2n * this.n * f + this.d) / (2n * this.d);
    const s = v.toString().padStart(scale + 1, '0');
    const out = scale ? `${s.slice(0, -scale)}.${s.slice(-scale)}` : s;
    return scale ? out.replace(/\.?0+$/, '') : out;
  }
}

export class PricingError extends Error {
  constructor(code, field = '', context = {}) { super(code); this.code = code; this.field = field; this.context = context; }
}

function dec(raw, field, { positive, max, maxScale }) {
  if (raw === null || raw === undefined || raw === '') throw new PricingError('REQUIRED', field);
  const s = String(raw);
  const re = maxScale > 0 ? new RegExp(`^\\d{1,20}(\\.\\d{1,${maxScale}})?$`) : /^\d{1,20}$/;
  if (!re.test(s)) throw new PricingError('INVALID_NUMBER', field);
  const r = Rat.of(s);
  if (positive && r.n === 0n) throw new PricingError('MUST_BE_POSITIVE', field);
  const m = Rat.of(max);
  if (r.n * m.d > m.n * r.d) throw new PricingError('OUT_OF_RANGE', field, { max });
  return r;
}

export function priceGold(input, limits = {}) {
  const weight = dec(input.net_weight_g, 'net_weight_g', { positive: true, max: limits.max_weight_g ?? '100000', maxScale: 6 });
  const purity = dec(input.purity_ppt, 'purity_ppt', { positive: true, max: '1000', maxScale: 3 });
  const price = dec(input.price18_irr_per_g, 'price18_irr_per_g', { positive: true, max: limits.max_amount_irr ?? '1000000000000000', maxScale: 0 });
  const wage = dec(input.wage_percent ?? '0', 'wage_percent', { positive: false, max: limits.max_percent ?? '1000', maxScale: 4 });
  const profit = dec(input.profit_percent ?? '0', 'profit_percent', { positive: false, max: limits.max_percent ?? '1000', maxScale: 4 });
  const vat = dec(input.vat_rate_percent, 'vat_rate_percent', { positive: false, max: '100', maxScale: 4 });

  const hundred = new Rat(100n);
  const eff = price.mul(purity).div(new Rat(750n));
  const mExact = weight.mul(eff);
  const w0Exact = mExact.mul(wage).div(hundred);
  const p0Exact = mExact.add(w0Exact).mul(profit).div(hundred);
  const M = mExact.roundHalfUp();
  const W0 = w0Exact.roundHalfUp();
  const P0 = p0Exact.roundHalfUp();
  const C0 = 0n;

  let aW = 0n, aP = 0n, aC = 0n, scope = null, amount = 0n;
  const d = input.discount;
  if (d && d.amount_irr && d.amount_irr !== '0') {
    scope = d.scope || 'TAXABLE_COMPONENTS';
    if (!SCOPES.includes(scope)) throw new PricingError('DISCOUNT_SCOPE_UNSUPPORTED', 'discount');
    amount = dec(d.amount_irr, 'discount', { positive: false, max: limits.max_amount_irr ?? '1000000000000000', maxScale: 0 }).n;
    const eligible = scope === 'WAGE' ? W0 : scope === 'PROFIT' ? P0 : W0 + P0 + C0;
    if (amount > eligible) throw new PricingError('DISCOUNT_EXCEEDS_ELIGIBLE', 'discount', { eligible_irr: eligible.toString() });
    if (scope === 'WAGE') aW = amount;
    else if (scope === 'PROFIT') aP = amount;
    else {
      const comps = [W0, P0, C0];
      const floors = comps.map((c) => (eligible === 0n ? 0n : (amount * c) / eligible));
      const rems = comps.map((c) => (eligible === 0n ? 0n : (amount * c) % eligible));
      let left = amount - floors[0] - floors[1] - floors[2];
      const order = [0, 1, 2].sort((a, b) => (rems[b] > rems[a] ? 1 : rems[b] < rems[a] ? -1 : a - b));
      for (const i of order) { if (left === 0n) break; floors[i] += 1n; left -= 1n; }
      [aW, aP, aC] = floors;
    }
  }
  const W = W0 - aW, P = P0 - aP, C = C0 - aC;
  const B = W + P + C;
  const V = new Rat(B).mul(vat).div(hundred).roundHalfUp();
  const T = M + B + V;
  const s = (x) => x.toString();
  return {
    formula_version: 'GOLD_IR_V1', rounding_policy: 'IRR_LINE_HALF_UP_V1',
    effective_rate_irr_per_g: eff.toFixed(18),
    M: s(M), W0: s(W0), P0: s(P0), C0: s(C0), discount_scope: scope, discount_irr: s(amount),
    allocation_wage: s(aW), allocation_profit: s(aP), allocation_commission: s(aC),
    W: s(W), P: s(P), C: s(C), B: s(B), V: s(V), T: s(T),
  };
}

export function priceManual(input, limits = {}) {
  const raw = String(input.manual_total_irr ?? '');
  if (!/^\d{1,20}$/.test(raw)) throw new PricingError('REQUIRED', 'manual_total_irr');
  const v = BigInt(raw);
  if (v === 0n) throw new PricingError('MUST_BE_POSITIVE', 'manual_total_irr');
  if (v > BigInt(limits.max_amount_irr ?? '1000000000000000')) throw new PricingError('OUT_OF_RANGE', 'manual_total_irr');
  return { formula_version: 'MANUAL_LINE_V1', price_basis: 'ROW_TOTAL', T: v.toString() };
}
