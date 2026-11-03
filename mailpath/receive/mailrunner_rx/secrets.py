"""Node-wide credentials for the analysis: /etc/mailrunner/secrets.env (root:mailrunner 0640).

KEY=VALUE lines, '#' comments. Same names as SmartMailFlow's secrets.env:
  SPAMHAUS_DQS_KEY  Spamhaus Data Query Service key (DNSBL via zen.dq.spamhaus.net)
  BLM_USER, BLM_PASS  blacklistmaster.com API credentials (both needed)
Empty values count as not set. A missing file means: no credentials.

Common secrets distributed by MailRunner Admin (<data dir>/secrets/common.env) are loaded first; every
non-empty value in the local secrets.env wins.
"""
import os

from . import config


def load(path=None, common=None):
    if path is None:  # node default: common secrets from the admin, then local overrides
        values = _read(common or config.COMMON_SECRETS_FILE)
        values.update(_read(config.SECRETS_FILE))
        return values
    return _read(path)


def _read(path):
    values = {}
    if not os.access(path, os.R_OK):  # missing (or not ours): no credentials
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
