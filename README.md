# Ansons Branch Inventory

WordPress plugin for **Ansons** click & collect: branch-level pickup availability via CSV import, integrated with **Order Delivery Date Pro** and **WooCommerce**.

**Author:** [Kristoffer Cheng](https://github.com/kzee06)
**Version:** 1.4.4
**Repository:** https://github.com/kzee06/ansons-branch-inventory

GitHub is the **source of truth** for this plugin. All changes are developed in Cursor, committed, and pushed to GitHub. Staging/production installs are built as a ZIP from the committed code.

## Requirements

- WordPress + WooCommerce
- [Order Delivery Date Pro for WooCommerce](https://www.tychesoftwares.com/store/premium-plugins/order-delivery-date-for-woocommerce-pro-21/) (plugin folder: `order-delivery-date`)

## Features

- CSV import (merge / replace) for SKU × branch availability
- **Direct SAP inventory CSV upload** (Itemcode, WhsCode, Available) — no conversion needed
- Configurable minimum stock threshold on the SAP import form (default 2)
- POS / SAP WhsCode mapping to ORDDD pickup locations
- WooCommerce SKU template export (`available` / `not_available`)
- Product page and checkout availability display
- Pickup branches column on the WooCommerce Products list
- Per-product admin overrides
- Optional checkout blocking when items are unavailable at the selected branch

---

## Development workflow

This project uses two long-lived branches:

| Branch    | Purpose                                                        |
| --------- | ------------------------------------------------------------- |
| `main`    | Stable, release-ready baseline. Builds shipped to production. |
| `develop` | Active development. Day-to-day work lands here first.         |

### 1. Develop in Cursor

Work on the `develop` branch:

```bash
git switch develop
```

Make your changes to the plugin files in Cursor as usual.

### 2. Commit changes

Review what changed, then stage and commit:

```bash
git status
git diff
git add -A
git commit -m "Describe the change (e.g. Fix SAP threshold rounding)"
```

When you bump functionality, also update the plugin header version
(`orddd-branch-pickup-inventory.php` → `Version:` and `BPI_VERSION`)
and add a matching entry at the top of `changelog.txt`.

### 3. Push to GitHub

```bash
git push origin develop
```

When `develop` is stable and ready to release, merge it into `main`:

```bash
git switch main
git merge --no-ff develop
git push origin main
git switch develop
```

### 4. Create a ZIP for WordPress staging testing

Build an installable ZIP straight from the committed code. `git archive`
includes only tracked files and wraps them in the correct plugin folder name
that WordPress expects:

```bash
# Build from the current branch's latest commit (HEAD)
git archive --format=zip --prefix=ansons-branch-inventory/ -o ansons-branch-inventory.zip HEAD
```

To build a ZIP from a specific branch or tag instead of the current checkout:

```bash
# From main (release build)
git archive --format=zip --prefix=ansons-branch-inventory/ -o ansons-branch-inventory.zip main
```

Then install on the staging site:

1. WordPress admin → **Plugins → Add New → Upload Plugin**
2. Choose `ansons-branch-inventory.zip` and click **Install Now**
3. **Activate** (or **Replace current** if updating an existing install)
4. Verify under **Ansons Tools → Branch Inventory**

> The ZIP is a build artifact — do not commit it. Rebuild it from the latest
> commit whenever you need to test on staging.

---

## CSV format

### SAP export (upload directly)

```csv
Itemcode,Itemname,WhsCode,WhsName,Available
005600148218,CS - MABE MEI09VR...,110,Landmark Makati Selling,1
005600167321,CS - FABRIANO FWE18MW...,110,Landmark Makati Selling,2
```

Only SKUs that exist in WooCommerce are updated. Stock **at or above** the
configured minimum (default 2) = Available; below = Not available. Map each
`WhsCode` to an ORDDD branch under **Store code mapping**.

### Manual availability CSV

```csv
sku,store_code,status
117600179586,MANILA,available
117600179586,CEBU,not_available
```

## Install (manual / first time)

1. Copy this folder to `wp-content/plugins/` (e.g. `ansons-branch-inventory`)
2. Activate **Ansons Branch Inventory** in WordPress
3. Configure under **Ansons Tools → Branch Inventory**

See `SETUP-CHECKLIST.txt` for full setup and daily workflow.

## License

GPL v2 or later (compatible with WordPress plugins).
