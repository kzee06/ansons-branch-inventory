# Ansons Branch Inventory

WordPress plugin for **Ansons** click & collect: branch-level pickup availability via CSV import, integrated with **Order Delivery Date Pro** and **WooCommerce**.

**Author:** [Kristoffer Cheng](https://github.com/kzee06)  
**Version:** 1.3.5

## Requirements

- WordPress + WooCommerce
- [Order Delivery Date Pro for WooCommerce](https://www.tychesoftwares.com/store/premium-plugins/order-delivery-date-for-woocommerce-pro-21/) (plugin folder: `order-delivery-date`)

## Features

- CSV import (merge / replace) for SKU × branch availability
- **Direct SAP inventory CSV upload** — no conversion needed
- POS / SAP WhsCode mapping to ORDDD pickup locations
- WooCommerce SKU template export (`available` / `not_available`)
- Product page and checkout availability display
- Per-product admin overrides
- Optional checkout blocking when items unavailable at selected branch

## CSV format

### SAP export (upload directly)

```csv
Itemcode,Itemname,WhsCode,WhsName,Available
005600148218,CS - MABE MEI09VR...,110,Landmark Makati Selling,1
005600167321,CS - FABRIANO FWE18MW...,110,Landmark Makati Selling,2
```

Only SKUs that exist in WooCommerce are updated. Stock **2 or more** = Available; **less than 2** = Not available. Map each `WhsCode` to an ORDDD branch under **Store code mapping**.

### Manual availability CSV

```csv
sku,store_code,status
117600179586,MANILA,available
117600179586,CEBU,not_available
```

## Install

1. Copy this folder to `wp-content/plugins/` (e.g. `ansons-branch-inventory`)
2. Activate **Ansons Branch Inventory** in WordPress
3. Configure under **WooCommerce → Ansons Branch Inventory**

See `SETUP-CHECKLIST.txt` for full setup and daily workflow.

## License

GPL v2 or later (compatible with WordPress plugins).
