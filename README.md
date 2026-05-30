# Ansons Branch Inventory

WordPress plugin for **Ansons** click & collect: branch-level pickup availability via CSV import, integrated with **Order Delivery Date Pro** and **WooCommerce**.

**Author:** KC Cheng  
**Version:** 1.2.3

## Requirements

- WordPress + WooCommerce
- [Order Delivery Date Pro for WooCommerce](https://www.tychesoftwares.com/store/premium-plugins/order-delivery-date-for-woocommerce-pro-21/) (plugin folder: `order-delivery-date`)

## Features

- CSV import (merge / replace) for SKU × branch availability
- POS store code mapping to ORDDD pickup locations
- WooCommerce SKU template export (`available` / `not_available`)
- Product page and checkout availability display
- Per-product admin overrides
- Optional checkout blocking when items unavailable at selected branch

## CSV format

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
