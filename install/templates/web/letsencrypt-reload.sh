#!/bin/sh
# Reload services that use Let's Encrypt certificates (install.sh).
systemctl reload nginx
if systemctl is-active --quiet postfix; then systemctl reload postfix; fi
if systemctl is-active --quiet dovecot; then systemctl reload dovecot; fi
