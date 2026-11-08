#!/bin/bash
# Gateway-only IPv6 routing for cloud-init netplan configs that declare the whole /64 on-link
# while the provider isolates VMs. Run as root. Reverts automatically after 5 minutes unless
# confirmed with:  systemctl stop netplan-revert.timer
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root (sudo)"; exit 1; }
python3 -c 'import yaml' || { echo "python3-yaml missing: apt install python3-yaml"; exit 1; }
F=/etc/netplan/50-cloud-init.yaml
D=/root/backup/netplan-$(date +%F)
mkdir -p "$D"
cp -a "$F" "$D/50-cloud-init.yaml.orig"
cat > "$D/revert.sh" <<EOF
#!/bin/bash
cp -a $D/50-cloud-init.yaml.orig $F
rm -f /etc/cloud/cloud.cfg.d/99-mailrunner-network.cfg
netplan apply
EOF
chmod 700 "$D/revert.sh"
python3 - "$F" "$D" <<'EOF'
import sys, yaml
p, d = sys.argv[1], sys.argv[2]
doc = yaml.safe_load(open(p))
for name, e in doc['network']['ethernets'].items():
    gws = [r['via'] for r in e.get('routes', []) if r.get('to') == '::/0']
    if not gws:
        continue
    gw = gws[0]
    keep = [r for r in e['routes'] if ':' not in str(r.get('to', ''))]
    e['routes'] = keep + [{'to': gw + '/128', 'scope': 'link'}, {'to': '::/0', 'via': gw, 'on-link': True}]
    print('changed', name)
hdr = ('# MailRunner: cloud-init network config disabled (99-mailrunner-network.cfg).\n'
       '# The provider declares the /64 on-link but isolates VMs; only the gateway is on-link.\n'
       '# Original: ' + d + '/\n')
open(p, 'w').write(hdr + yaml.safe_dump(doc, sort_keys=False))
EOF
echo 'network: {config: disabled}' > /etc/cloud/cloud.cfg.d/99-mailrunner-network.cfg
chmod 600 "$F"
netplan generate
systemctl stop netplan-revert.timer 2>/dev/null || true
systemd-run --unit=netplan-revert --on-active=300 "$D/revert.sh"
netplan apply
echo
ip -6 route
echo
echo "Check now (ssh, https, mail). If everything works:  systemctl stop netplan-revert.timer"
echo "Otherwise it reverts itself in 5 minutes."
