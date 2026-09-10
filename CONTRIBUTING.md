# Contributing to A First Flag

Thanks for helping. This project is split across two public repos:

- Website: https://github.com/Cremea06/afirstflag
- Chat app: https://github.com/Cremea06/eagles-nest-chat

You do **not** need the GitHub “Download for…” menu (that installs editors / Copilot). Use **Code → clone** or **Code → Download ZIP**.

## First-time setup

### Tools (install once)

1. [Git](https://git-scm.com/downloads)
2. [VS Code](https://code.visualstudio.com/) or any editor
3. For the chat repo only: [Node.js LTS](https://nodejs.org)

Check they work:

```bash
git --version
node -v
npm -v
```

### Folders on your Desktop

Create two folders:

- `afirstflag`
- `eagles-nest-chat`

### Clone the repos

```bash
cd ~/Desktop/afirstflag
git clone https://github.com/Cremea06/afirstflag.git .
```

```bash
cd ~/Desktop/eagles-nest-chat
git clone https://github.com/Cremea06/eagles-nest-chat.git .
```

The `.` clones into the folder you already made. If Git says the folder is not empty, clone without the dot instead:

```bash
cd ~/Desktop
git clone https://github.com/Cremea06/afirstflag.git
git clone https://github.com/Cremea06/eagles-nest-chat.git
```

### ZIP option (no Git yet)

On each repo page: green **Code** button → **Download ZIP** → unzip into the matching Desktop folder.

Use Git clone if you plan to send changes.

## Run locally

### Website (`afirstflag`)

Mostly static HTML/CSS/JS.

1. Open the folder in your editor.
2. Open `index.html` in a browser, or use a Live Server extension.

Some PHP routes (`api.php`) need a local web server. Opening `index.html` is enough for first look and front-end work.

### Chat (`eagles-nest-chat`)

```bash
cd ~/Desktop/eagles-nest-chat
npm install
npm start
```

If there is no `start` script, run:

```bash
node server.js
```

Open the URL printed in the terminal (often `http://localhost:3000`).

## How we take changes

1. Fork the repo on GitHub (your own copy).
2. Clone **your fork**.
3. Create a branch:

```bash
git checkout -b fix/short-description
```

4. Make a small, focused change.
5. Commit:

```bash
git add .
git commit -m "Short description of what changed"
```

6. Push:

```bash
git push -u origin fix/short-description
```

7. Open a Pull Request back to `Cremea06/afirstflag` or `Cremea06/eagles-nest-chat`.

## Guidelines

- One concern per pull request.
- Do not commit secrets, `.env` files, API keys, or passwords.
- Do not commit generated junk if it is already ignored.
- Keep the site and chat repos separate unless a change truly needs both.
- Say what you tested (browser, page, or chat flow).

## Questions

Open an Issue on the matching repo and describe:

- What you were trying to do
- What you expected
- What happened instead
- OS and browser (or Node version for chat)
