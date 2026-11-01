"""Paths of a MailRunner node. Overridable via environment for tests."""
import os

CONFIG_DIR = os.environ.get("MR_CONFIG_DIR", "/etc/mailrunner")
DATA_DIR = os.environ.get("MR_DATA_DIR", "/var/lib/mailrunner")

NODE_KEY_FILE = os.path.join(CONFIG_DIR, "node.key")
SECRETS_FILE = os.path.join(CONFIG_DIR, "secrets.env")
ROUTES_FILE = os.path.join(CONFIG_DIR, "generated", "current", "routes.json")
SPOOL_DIR = os.path.join(DATA_DIR, "receive", "spool")
