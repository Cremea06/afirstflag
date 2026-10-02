# Technology attribution

Public thank-you list for the stack under afirstflag.com and Eagles Nest.
The homepage drawer “Built With Gratitude” is the short version of this file.
Last reviewed: 2026-10-02 (CHG-059), against Shop `main` d3ea766 and Nest `main` 46eeca9.

## Foundational

- HTML, CSS, JavaScript: WHATWG / W3C / browser vendors
- Node.js: OpenJS Foundation, MIT (chat server runtime)
- Web APIs, WebRTC: standards bodies (chat voice/video uses WebRTC)
- PHP: The PHP Group / PHP Foundation, PHP License 3.01 (shop API: visit counter, collaborators, flag stats, Stripe webhook)
- PowerShell: Microsoft + community, MIT for PowerShell 7 (runs `tools/offline-chatroom.ps1`; Windows PowerShell 5.1 ships with Windows)

## Open source (chat / server)

- Express: MIT
- Socket.IO (server + browser client): MIT
- cors: MIT (expressjs/cors; lets the homepage call the chat API)
- jsonwebtoken: MIT (auth0; single-use Nest World pass, `/nest` to `/world`)
- Nodemailer: MIT-0 (sign-in code email)
- dotenv: BSD-2-Clause
- three.js: MIT (r170, vendored as `public/world/three.min.js` for the Nest World courtyard)
- PM2: AGPL-3.0 (ops; keeps the chat server running)
- nginx: BSD-2-Clause (HTTPS front for the chat server)

## Services

- Stripe: payments (Buy Flag Payment Link) and webhook for flag inventory
- mempool.space: public Bitcoin balance data (address search, Confirmed balance chip); explorer code AGPL-3.0
- xAI: Neagle replies in chat (Grok model via the xAI API)
- GitHub: source hosting
- Namecheap: domain, DNS, mail forwarding, and shared web hosting for afirstflag.com
- Let's Encrypt (ISRG): TLS certificate for the chat site
- SMTP provider: delivers sign-in codes through Nodemailer
- Google STUN: WebRTC NAT assist (terms: review open)

Sponsors (money) are separate from this list.
