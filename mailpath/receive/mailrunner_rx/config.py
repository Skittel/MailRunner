"""Paths of a MailRunner node. Overridable via environment for tests."""
import os

CONFIG_DIR = os.environ.get("MR_CONFIG_DIR", "/etc/mailrunner")
DATA_DIR = os.environ.get("MR_DATA_DIR", "/var/lib/mailrunner")

NODE_KEY_FILE = os.path.join(CONFIG_DIR, "node.key")
SECRETS_FILE = os.path.join(CONFIG_DIR, "secrets.env")
ROUTES_FILE = os.path.join(CONFIG_DIR, "generated", "current", "routes.json")
SPOOL_DIR = os.path.join(DATA_DIR, "receive", "spool")
STORE_DIR = os.path.join(DATA_DIR, "receive", "store")

# Time budget per message at end of data (decision E-11/E-12: 90 s)
ANALYSIS_LIMIT_SECONDS = float(os.environ.get("MR_ANALYSIS_LIMIT", "90"))
MILTER_SOCKET = os.environ.get("MR_MILTER_SOCKET", "inet:8899@127.0.0.1")
RESOLVER = os.environ.get("MR_RESOLVER", "127.0.0.1")
