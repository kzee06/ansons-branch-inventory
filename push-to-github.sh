#!/bin/bash
cd "/Users/kzee/Downloads/orddd-branch-pickup-inventory" || exit 1
echo "Plugin folder: $(pwd)"
echo "GitHub repo:   https://github.com/kzee06/ansons-branch-inventory"
echo ""
git remote remove origin 2>/dev/null || true
git remote add origin https://github.com/kzee06/ansons-branch-inventory.git
echo "Uploading... sign in if prompted."
git push -u origin main
echo ""
echo "Done: https://github.com/kzee06/ansons-branch-inventory"
