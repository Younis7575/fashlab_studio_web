# CI/CD — GitHub se IONOS par auto deploy

Jaise hi `main` branch par push hoti hai, GitHub Actions tests chalata hai aur phir
saari site files IONOS webspace par SFTP se upload kar deta hai.

Workflow file: [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)

---

## 1. IONOS se SFTP credentials lein

IONOS panel → **Hosting → SFTP & SSH** (ya *Webspace / FTP Access*) par ye 4 cheezein milengi:

| Cheez | Value |
|---|---|
| Server / Host | `access-5020843190.webspace-host.com` |
| Port | `22` (SFTP) |
| Username | `a1667790` |
| Password | jo aapne SFTP user `a1667790` ke liye set kiya |

Ye account `/mytechrcm` directory tak restricted hai (IONOS panel -> **Manage secure FTP accounts**).

> Agar password yaad nahi, IONOS panel se **naya password set** kar lein — GitHub ko wahi dena hai.

---

## 2. GitHub par Secrets add karein

Repo → **Settings → Secrets and variables → Actions → Secrets → New repository secret**

| Secret name | Value |
|---|---|
| `SFTP_HOST` | `access-5020843190.webspace-host.com` |
| `SFTP_USERNAME` | `a1667790` |
| `SFTP_PASSWORD` | us user ka password |
| `SFTP_PORT` | `22` *(optional — na dein to 22 use hoga)* |

Direct link: https://github.com/Younis7575/fashlab_studio_web/settings/secrets/actions

---

## 3. Remote path set karein (optional)

Usi page par **Variables** tab → **New repository variable**:

| Variable | Value |
|---|---|
| `REMOTE_DIR` | `/mytechrcm/tayyabacollective` |

Ye variable na banayein to bhi default yahi path use hota hai.

**Confirmed:** SFTP account `a1667790` `/mytechrcm` par chroot hai, is liye login ke
baad asli path **`/tayyabacollective`** banta hai. Workflow ye khud detect kar leti hai —
aapko `REMOTE_DIR` chhedne ki zaroorat nahi.

---

## 4. Test karein

```bash
git add .github/workflows/deploy.yml DEPLOY.md
git commit -m "Add CI/CD pipeline for IONOS deployment"
git push origin main
```

Phir dekhein: https://github.com/Younis7575/fashlab_studio_web/actions

---

## Pipeline kya karti hai

1. **test** job — `node --test tests/*.test.js` chalata hai. Test fail ho to deploy **nahi** hota.
2. **deploy** job — pehle remote path resolve karta hai (SFTP account `/mytechrcm` par
   scoped hai, is liye `/mytechrcm/tayyabacollective` aur `/tayyabacollective` dono try
   hote hain), phir `lftp` se SFTP mirror. Sirf badli hui files upload hoti hain (fast).

Server par **upload nahi** hone wali cheezein:
`.git/`, `.github/`, `.gitignore`, `node_modules/`, `tests/`, `images/sources/`, `README.md`, `DEPLOY.md`

## Purani files delete karna

Normally deploy sirf files **add/update** karta hai, kuch delete nahi karta (safe default —
server par manually upload ki hui cheezein bachi rehti hain).

Agar server ko repo ka exact copy banana ho:
Actions → **Deploy to IONOS** → **Run workflow** → *"Server par woh files bhi delete karein…"*
ko tick karke chalayein.

## Server par purani files

Deploy se pehle manually upload ki gayi `README.md` aur `tests/` folder server par
mojood hain. Workflow inhein exclude karti hai, is liye ye kabhi update nahi hongi.
Chahein to IONOS Webspace Explorer se delete kar dein.

## Manually deploy

Actions → **Deploy to IONOS** → **Run workflow** → branch `main` → Run.

---

## IONOS host quirks (seedhi baat — waqt bachane ke liye)

**1. mod_rewrite substitution kaam nahi karta.**
Is host par rewrite rules ka *pattern match* to hota hai lekin *substitution* apply
nahi hoti. Proof: `/config/koi-nahi-hai.php` (jo file mojood hi nahi) `403` deta hai —
matlab `RewriteRule ^config(/|$) - [F,L]` chal rahi hai — jabke har permalink `404`
deta hai.

Isi liye `product_url()` / `category_url()` (in [`includes/functions.php`](includes/functions.php))
by default query-string URLs banate hain: `/product.php?slug=...`.
Jis host par rewrites chalti hon, wahan `config/config.php` mein
`define('PRETTY_URLS', true);` laga dein — permalinks wapas on ho jayenge.

`.htaccess` ki deny-only rules (`[F]`) theek chalti hain, is liye `config/`,
`includes/`, `scripts/`, `database.sql` waghera web se **protected** hain.

**2. `DirectoryIndex` zaroori hai.**
`DirectoryIndex index.php index.html` na ho to purani static `index.html`
storefront ko dabaa deti hai. `/admin/` bhi isi se resolve hota hai (rewrite se nahi).

**3. `.htaccess` mein `R=418` jaisa non-standard status na dein** — poora site 500 ho jata hai.

**4. Database — `localhost` NAHI.**

| | |
|---|---|
| Host | `db5021350220.hosting-data.io` |
| Database | `dbs16091691` |
| User | `dbu1721042` |
| Password | sirf server ki `config/config.php` mein (repo mein kabhi nahi) |

`database.sql` ko phpMyAdmin mein **seedha import na karein** — uske shuru ki
`CREATE DATABASE` aur `USE` lines IONOS reject karta hai. Pehle woh do lines
hata dein, phir import karein.

**5. `config/config.php` sirf server par rehti hai** — gitignored hai aur
`--delete` deploy se bhi excluded hai, is liye deploy usay overwrite/delete nahi karti.

---

## Database upgrade: `migration-fashlab-upgrade.sql`

Ye file naye enterprise features (collections, reviews, wishlists, coupons,
inventory logs, order tracking, menus, RBAC, rewards, journal, waghera) ki saari
tables aur existing tables ke naye columns add karti hai — **kuch delete nahi karti**.

- Live database par phpMyAdmin mein ja kar **Import** karein (baqi `database.sql` ki
tarah isme `CREATE DATABASE` / `USE` lines NAHI hain, is liye IONOS par seedha
import ho jayegi).
- File **idempotent** hai — agar ghalti se dobara import ho jaye to koi nuqsan nahi.
- Existing data (products, orders, customers, settings) ko nahi chhedti; existing
settings kabhi overwrite nahi hoti.
- Import ke baad `hero_slides`, `homepage_sections`, `menu_items`, `collections`,
`settings` mein Fashlab Studio ki default configuration seed ho jati hai.
- Jo menu items nayi storefront pages (journal, looks, policies, track-order,
size-guide) par point karte hain wo `status = 0` (disabled) seed hain — jab wo
pages deploy ho jayen to Admin se enable kar dein.
- `.htaccess` is file ko web se protected karta hai (baqi `database.sql` ki tarah).
