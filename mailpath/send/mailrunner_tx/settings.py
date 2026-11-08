"""Node settings the delivery worker needs, read from /etc/mailrunner/app.env (the same file PHP reads)."""
import ipaddress
import os
import re

APP_ENV = os.environ.get("MR_APP_ENV", "/etc/mailrunner/app.env")


def read_env(path):
    """"key = value" / "KEY=value" lines, quotes stripped; comments and blank lines ignored."""
    out = {}
    try:
        with open(path) as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                v = v.strip()
                if len(v) >= 2 and v[0] == v[-1] and v[0] in "\"'":
                    v = v[1:-1]
                out[k.strip()] = v
    except FileNotFoundError:
        pass
    return out


def ip_list(value, version):
    out = []
    for part in re.split(r"[\s,]+", value or ""):
        try:
            if part and ipaddress.ip_address(part).version == version:
                out.append(part)
        except ValueError:
            continue
    return out


class Settings:
    def __init__(self, env=None):
        env = read_env(APP_ENV) if env is None else env
        self.db = {
            "host": env.get("database.default.hostname", "localhost"),
            "user": env.get("database.default.username", ""),
            "password": env.get("database.default.password", ""),
            "database": env.get("database.default.database", "mailrunner"),
            "port": int(env.get("database.default.port") or 3306),
        }
        self.enabled = env.get("MAILRUNNER_SEND_ENABLED", "").lower() in ("1", "true", "yes", "on")
        self.helo = (env.get("MAILRUNNER_SEND_HELO") or "").strip().lower()
        self.ipv4 = ip_list(env.get("MAILRUNNER_SEND_IPV4"), 4)
        self.ipv6 = ip_list(env.get("MAILRUNNER_SEND_IPV6"), 6)
        self.config_dir = env.get("MAILRUNNER_CONFIG_DIR", "/etc/mailrunner").rstrip("/")
        self.data_dir = env.get("MAILRUNNER_DATA_DIR", "/var/lib/mailrunner").rstrip("/")

    @property
    def node_key_file(self):
        return os.path.join(self.config_dir, "node.key")

    @property
    def store_dir(self):
        return os.path.join(self.data_dir, "send", "store")

    @property
    def webhook_request(self):
        return os.path.join(self.data_dir, "send", "requests", "webhook.request")
