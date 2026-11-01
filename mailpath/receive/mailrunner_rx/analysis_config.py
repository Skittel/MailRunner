"""Per-project analysis settings incl. secrets, written encrypted by `spark mr:apply`
(generated/current/analysis.json.enc, context "generated:analysis.json")."""
import json
import os

from . import config, crypto

FILE = os.path.join(config.CONFIG_DIR, "generated", "current", "analysis.json.enc")


def load(key, path=FILE):
    """Returns {project_id(str): settings}; empty if the file does not exist."""
    if not os.path.exists(path):
        return {}
    with open(path, "rb") as fh:
        return json.loads(crypto.decrypt(key, fh.read(), "generated:analysis.json")).get("projects", {})
