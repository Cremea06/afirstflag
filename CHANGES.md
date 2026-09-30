# CHG-050 — Shop homepage: Live-state role chip shows the signed-in member name

Surface: Shop only (afirstflag.com, repo Cremea06/afirstflag). No Nest changes.
Base: live https://afirstflag.com/ re-fetched 2026-09-30, md5 5dbb5d0a80db32dc16df7d6b8cbe25ae (CHG-046 baseline, LF line endings).

## What changed
`index.html` only: +25 lines, 0 removed (see index.diff).

1. **One scoped CSS rule** (after the existing `.live-cmd-row #live-role-chip` rule, 7 lines + 1 blank). Long names stay on one line with an ellipsis:
   ```css
   /* CHG-050: long member names stay on one line; the full text is in the chip's title */
   .live-cmd-row #live-role-chip {
     max-width: min(22rem, 100%);
     overflow: hidden;
     text-overflow: ellipsis;
     white-space: nowrap;
   }
   ```
   On desktop the chip is capped at 352px (22rem). On a phone it is capped at the row width (324px at 390px wide).
2. **One small inline IIFE** (right after the CHG-041 live-cmd script, 16 lines + 1 blank). It is the "Contract for CHG-050" from CHG-049 with ROLE_CHIP_ID = 'live-role-chip':
   - It runs once on page load: `fetch('https://chat.afirstflag.com/api/member/me', { credentials: 'include', cache: 'no-store', signal })`. There is a 3s AbortController timeout and no custom headers.
   - It shows the name only for an OK response with `{member:true, name:<non-empty string>}`. Anything else, including a timeout, a network error or a non-2xx response, leaves "Current role: Guest User".
   - It writes with `textContent` only, never innerHTML.
   - **The one addition to the contract:** `setRole` also sets `chip.title` to the full chip text every time it sets the text, including for the Guest fallback. This lets you read a long name that has been cut off.

## What did not change
- The static chip markup still says `Current role: Guest User`. With JS off or a failed fetch, the page is exactly as before.
- The /login command (`LOGIN_URL = "https://chat.afirstflag.com/?login=1"`) and the "unknown command." hint are unchanged.
- The drawer order is unchanged (Live-state, How it works, windows, …). The aria-controls sequence is identical.
- The Buy Flag stand-in label "Buy now-1-click-and-done-don't-make-me-do-extra", the BTC chip, the images, the PHP API references and every other script are unchanged.
- **Byte-identity proof:** removing the added CSS block and the added script block from out/index.html gives a file byte-identical to base (md5 5dbb5d0a80db32dc16df7d6b8cbe25ae).
- There is no CSP, neither a header nor a meta tag, and no connect-src restriction, so nothing blocks the fetch to chat.afirstflag.com. The page already fetches chat.afirstflag.com/api/heartbeat and /api/online.

## Paste steps
1. Copy `index.html` over the repo's `index.html` (Cremea06/afirstflag). Keep LF line endings.
2. `git push origin main`
3. `git push server main`

## Andy's live test
**Before you start:**
- CHG-049 (`GET /api/member/me` with CORS for https://afirstflag.com and https://www.afirstflag.com) must already be live on chat.afirstflag.com. Otherwise the chip just stays "Guest User".
- The homepage must be opened over **https**. http:// already redirects to https.

**Steps:**
1. Sign in on https://chat.afirstflag.com with /login, then enter your email, then type /auth CODE.
2. Open https://afirstflag.com in the same browser, expand **Live-state**, and check that it says "Current role: <your name>". Hover the chip: the tooltip shows the same text.
3. Do the same on https://www.afirstflag.com.
4. Open a private window with https://afirstflag.com. The chip shows "Current role: Guest User".
5. Optional: repeat in Safari, which is untested here (Safari's tracking prevention should treat chat.afirstflag.com as same-site).

## md5
- base (live) index.html: 5dbb5d0a80db32dc16df7d6b8cbe25ae
- out index.html: 1969218b96c32664546968621fe6dd5c
