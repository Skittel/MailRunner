"""Node-wide credentials for the analysis: /etc/mailrunner/secrets.env (root:mailrunner 0640).

KEY=VALUE lines, '#' comments. Same names as SmartMailFlow's secrets.env:
  SPAMHAUS_DQS_KEY  Spamhaus Data Query Service key (DNSBL via zen.dq.spamhaus.net)
  BLM_USER, BLM_PASS  blacklistmaster.com API credentials (both needed)
Empty values count as not set. A missing file means: no credentials.
"""
import os

from . import config


def load(path=None):
    path = path or config.SECRETS_FILE
    values = {}
    if not os.path.exists(path):
        return values
    with open(path) as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            value = value.strip().strip("'\"")
            if value:
                values[key.strip()] = value
    return values
