// Solves the server's proof-of-work challenge with WebCrypto in batches (keeps the UI responsive).
function zeroBits(buf) {
  const bytes = new Uint8Array(buf);
  let bits = 0;
  for (const b of bytes) {
    if (b === 0) { bits += 8; continue; }
    bits += Math.clz32(b) - 24;
    break;
  }
  return bits;
}

export async function solve(challenge, bits, onProgress) {
  const enc = new TextEncoder();
  let nonce = 0;
  const batch = 512;
  for (;;) {
    const jobs = [];
    for (let i = 0; i < batch; i++) jobs.push(crypto.subtle.digest('SHA-256', enc.encode(`${challenge}:${nonce + i}`)));
    const hashes = await Promise.all(jobs);
    for (let i = 0; i < hashes.length; i++) {
      if (zeroBits(hashes[i]) >= bits) return String(nonce + i);
    }
    nonce += batch;
    onProgress?.(nonce);
    if (nonce > 50_000_000) throw new Error('pow timeout');
  }
}
