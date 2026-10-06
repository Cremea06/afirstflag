// Builds vectors.json. Base58 cases are generated here with Node's own crypto
// (independent of the three engines under test). Bech32 cases are copied from
// BIP-173 / BIP-350 test vectors plus site-specific cases.
import { createHash } from 'node:crypto';
import { writeFileSync } from 'node:fs';

const B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
const sha = (b) => createHash('sha256').update(b).digest();
function b58check(version, payload) {
  const body = Buffer.concat([Buffer.from([version]), payload]);
  const full = Buffer.concat([body, sha(sha(body)).subarray(0, 4)]);
  let n = BigInt('0x' + full.toString('hex')), s = '';
  while (n > 0n) { s = B58[Number(n % 58n)] + s; n /= 58n; }
  for (const b of full) { if (b === 0) s = '1' + s; else break; }
  return s;
}
const h160 = Buffer.from('751e76e8199196d454941c45d1b3a323f1433bd6', 'hex');
const zero160 = Buffer.alloc(20);

const V = [
  // [input, expected code, note]
  ['bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfnl', 3, 'site donation address (header chip)'],
  ['BC1QCERGRMVAP2RLNX3E7G2NL8E5SEH54MYMRSJFNL', 3, 'site address, all caps'],
  ['bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfnm', -6, 'site address, last char typo'],
  ['bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfln', -6, 'site address, two chars swapped'],
  ['Bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfnl', -8, 'mixed case'],
  ['bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', 3, 'P2WPKH'],
  ['BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4', 3, 'BIP-173/350 valid'],
  ['bc1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3qccfmv3', 4, 'P2WSH (BIP-173)'],
  ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0', 5, 'P2TR (BIP-350 valid)'],
  ['bc1pw508d6qejxtdg4y5r3zarvary0c5xw7kw508d6qejxtdg4y5r3zarvary0c5xw7kt5nd6y', 6, 'v1, 40-byte program (BIP-350 valid)'],
  ['BC1SW50QGDZ25J', 6, 'v16, 2-byte program (BIP-350 valid)'],
  ['bc1zw508d6qejxtdg4y5r3zarvaryvaxxpcs', 6, 'v2, 16-byte program (BIP-350 valid)'],
  ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5', -6, 'BIP-173 invalid checksum'],
  ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqh2y7hd', -6, 'BIP-350: v1 with bech32 checksum'],
  ['BC1S0XLXVLHEMJA6C4DQV22UAPCTQUPFHLXM9H8Z3K2E72Q4K9HCZ7VQ54WELL', -6, 'BIP-350: v16 with bech32 checksum'],
  ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kemeawh', -6, 'BIP-350: v0 with bech32m checksum'],
  ['bc1p38j9r5y49hruaue7wxjce0updqjuyyx0kh56v8s25huc6995vvpql3jow4', -3, "BIP-350: invalid char 'o'"],
  ['BC130XLXVLHEMJA6C4DQV22UAPCTQUPFHLXM9H8Z3K2E72Q4K9HCZ7VQ7ZWS8R', -7, 'BIP-350: witness version 17'],
  ['bc1pw5dgrnzv', -7, 'BIP-350: 1-byte program'],
  ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7v8n0nx0muaewav253zgeav', -7, 'BIP-350: 41-byte program'],
  ['BC1QR508D6QEJXTDG4Y5R3ZARVARYV98GJ9P', -7, 'BIP-350: v0 16-byte program'],
  ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7v07qwwzcrf', -7, 'BIP-350: zero padding over 4 bits'],
  ['bc1gmk9yu', -7, 'BIP-350: empty data section'],
  ['tb1q0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vq24jc47', -5, 'testnet bech32'],
  ['tb1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vq47Zagq', -5, 'testnet, mixed case'],
  ['bcrt1qs758ursh4q9z627kt3pp5yysm78ddny6txaqgw', -5, 'regtest'],
  ['tc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vq5zuyut', -3, 'BIP-350: unknown hrp (has 0 and l, not base58)'],
  ['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', 1, 'genesis P2PKH'],
  ['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNb', -6, 'genesis, last char typo'],
  ['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfN0', -3, "base58 has no '0'"],
  ['3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy', 2, 'P2SH'],
  ['3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLz', -6, 'P2SH typo'],
  [b58check(0x00, h160), 1, 'generated P2PKH'],
  [b58check(0x00, zero160), 1, 'generated P2PKH, all-zero hash (many leading 1s)'],
  [b58check(0x05, h160), 2, 'generated P2SH'],
  [b58check(0x6f, h160), -5, 'generated testnet P2PKH'],
  [b58check(0xc4, h160), -5, 'generated testnet P2SH'],
  [b58check(0x30, h160), -7, 'generated Litecoin P2PKH (version 0x30)'],
  [b58check(0x00, Buffer.alloc(21, 7)), -7, 'generated 26-byte payload'],
  ['1abc', -7, 'too short'],
  ['', -1, 'empty'],
  ['bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfnl bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', -3, 'two addresses'],
  ['bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfnl,', -3, 'trailing comma'],
  ['bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfné', -3, 'non-ASCII'],
  ['x'.repeat(91), -2, '91 chars'],
  ['x'.repeat(1025), -2, 'over buffer'],
  ['hello', -3, "random word ('l' is not base58)"],
  ['abc', -7, 'short base58-only text'],
  ['5HueCGU8rMjxEXxiPuD5BDku4MkFqeZyd4dZ1jvhTVqvbTLvyTJ', -4, 'WIF private key (wiki example)'],
  ['KwdMAjGmerYanjeui5SHS7JkmpZvVipYvB2LJGU1ZxJwYvP98617', -4, 'compressed WIF (wiki example)'],
  ['xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi', -4, 'BIP-32 test xprv'],
  ['ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', -4, '64 hex chars'],
  ['0xBA7816BF8F01CFEA414140DE5DAE2223B00361A396177A9CB410FF61F20015AD', -4, '0x + 64 hex'],
  ['abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about', -4, '12-word seed (BIP-39 test)'],
  ['abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about', -3, '11 words: not flagged as seed, has spaces'],
];
writeFileSync(new URL('./vectors.json', import.meta.url), JSON.stringify(V.map(([input, expect, note]) => ({ input, expect, note })), null, 1));
console.log('wrote', V.length, 'vectors');
