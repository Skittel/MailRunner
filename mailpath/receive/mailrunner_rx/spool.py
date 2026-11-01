"""Crash-safe spool: encrypted <id>.eml and <id>.json, written to tmp/, fsynced, renamed
(json last), directory fsynced. A spool entry is complete when its .json exists."""
import json
import os
import secrets
import time

from . import crypto

STAGES = ("tmp", "new", "analyzed", "failed")


def new_spool_id():
    return time.strftime("%Y%m%dT%H%M%SZ", time.gmtime()) + "-" + secrets.token_hex(8)


def _fsync_dir(path):
    fd = os.open(path, os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _write_file(path, data):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
    try:
        view = memoryview(data)
        while view:
            written = os.write(fd, view)
            view = view[written:]
        os.fsync(fd)
    finally:
        os.close(fd)


def write_entry(spool_dir, stage, spool_id, key, meta, raw=None):
    """Writes an entry into <stage>/ via tmp/. raw=None writes only the json (e.g. analysis-only results)."""
    tmp = os.path.join(spool_dir, "tmp")
    dst = os.path.join(spool_dir, stage)
    files = []
    if raw is not None:
        files.append(("eml", crypto.encrypt(key, raw, f"spool:{spool_id}:eml")))
    files.append(("json", crypto.encrypt(key, json.dumps(meta, ensure_ascii=False).encode(), f"spool:{spool_id}:{stage}")))
    for ext, blob in files:
        _write_file(os.path.join(tmp, f"{spool_id}.{ext}"), blob)
    for ext, _ in files:  # json last: the entry becomes visible only when complete
        os.rename(os.path.join(tmp, f"{spool_id}.{ext}"), os.path.join(dst, f"{spool_id}.{ext}"))
    _fsync_dir(tmp)
    _fsync_dir(dst)


def ready_ids(spool_dir, stage):
    path = os.path.join(spool_dir, stage)
    return sorted(f[:-5] for f in os.listdir(path) if f.endswith(".json"))


def read_meta(spool_dir, stage, spool_id, key):
    with open(os.path.join(spool_dir, stage, f"{spool_id}.json"), "rb") as fh:
        return json.loads(crypto.decrypt(key, fh.read(), f"spool:{spool_id}:{stage}"))


def read_raw(spool_dir, stage, spool_id, key):
    with open(os.path.join(spool_dir, stage, f"{spool_id}.eml"), "rb") as fh:
        return crypto.decrypt(key, fh.read(), f"spool:{spool_id}:eml")


def remove(spool_dir, stage, spool_id):
    for ext in ("eml", "json"):
        try:
            os.unlink(os.path.join(spool_dir, stage, f"{spool_id}.{ext}"))
        except FileNotFoundError:
            pass
    _fsync_dir(os.path.join(spool_dir, stage))


def move_to_failed(spool_dir, stage, spool_id, reason):
    """Keeps the encrypted files (never silently deleted) and writes a plain .reason file without content."""
    failed = os.path.join(spool_dir, "failed")
    for ext in ("eml", "json"):
        src = os.path.join(spool_dir, stage, f"{spool_id}.{ext}")
        if os.path.exists(src):
            os.rename(src, os.path.join(failed, f"{spool_id}.{stage}.{ext}"))
    with open(os.path.join(failed, f"{spool_id}.reason"), "w") as fh:
        fh.write(f"{time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())} stage={stage} {reason}\n")
    _fsync_dir(failed)
