"""Encrypted message store: per message id up to two files plus the import hand-off.

  store/YYYY/MM/DD/<id>.analysis.json.enc   always (analysis JSON, SmartMailFlow format)
  store/YYYY/MM/DD/<id>.eml.enc             only if the mail itself is needed (not for profile analysis)
  spool/analyzed/<id>.json                  hand-off to `spark mr:import` (metadata for the database)

Every file is written to a temp name, fsynced, renamed and the directory fsynced; the
hand-off file is written last. Only then the milter answers 250.
"""
import json
import os
import secrets
import time

from . import crypto


def new_id():
    return time.strftime("%Y%m%dT%H%M%SZ", time.gmtime()) + "-" + secrets.token_hex(8)


def _fsync_dir(path):
    fd = os.open(path, os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_atomic(path, data):
    tmp = f"{path}.tmp-{secrets.token_hex(4)}"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
    try:
        view = memoryview(data)
        while view:
            view = view[os.write(fd, view):]
        os.fsync(fd)
    finally:
        os.close(fd)
    os.rename(tmp, path)
    _fsync_dir(os.path.dirname(path))


def day_dir(store_dir, message_id):
    d = os.path.join(store_dir, message_id[0:4], message_id[4:6], message_id[6:8])
    os.makedirs(d, mode=0o750, exist_ok=True)
    return d


def relative(store_dir, path):
    return os.path.relpath(path, store_dir)


def save(store_dir, spool_dir, key, message_id, analysis, meta, raw=None):
    """Writes analysis (+ raw if given) and then the import hand-off. Returns meta incl. paths."""
    d = day_dir(store_dir, message_id)
    analysis_path = os.path.join(d, f"{message_id}.analysis.json.enc")
    write_atomic(analysis_path, crypto.encrypt(key, json.dumps(analysis, ensure_ascii=False).encode(), f"store:{message_id}:analysis"))
    meta = dict(meta, analysis_path=relative(store_dir, analysis_path), raw_path=None)
    if raw is not None:
        raw_path = os.path.join(d, f"{message_id}.eml.enc")
        write_atomic(raw_path, crypto.encrypt(key, raw, f"store:{message_id}:eml"))
        meta["raw_path"] = relative(store_dir, raw_path)
    write_atomic(os.path.join(spool_dir, "analyzed", f"{message_id}.json"),
                 crypto.encrypt(key, json.dumps(meta, ensure_ascii=False).encode(), f"spool:{message_id}:analyzed"))
    return meta
