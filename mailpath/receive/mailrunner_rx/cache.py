"""Small thread-safe in-memory cache with a fixed lifetime (shared by all messages in mr-milter).

Used for results that must not be fetched for every message (decision 01.11.26: Spamhaus and
blacklistmaster results are kept 1 hour). Lost on restart, which is fine.
"""
import threading
import time


class TTLCache:
    def __init__(self, ttl_seconds, max_entries=50000):
        self.ttl = ttl_seconds
        self.max_entries = max_entries
        self._data = {}
        self._lock = threading.Lock()

    def get(self, key):
        """Returns (value, stored_at) or None."""
        with self._lock:
            entry = self._data.get(key)
            if entry is None:
                return None
            value, stored_at = entry
            if time.time() - stored_at >= self.ttl:
                del self._data[key]
                return None
            return value, stored_at

    def put(self, key, value):
        if self.ttl <= 0:
            return
        with self._lock:
            if len(self._data) >= self.max_entries:
                now = time.time()
                for k in [k for k, (_, t) in self._data.items() if now - t >= self.ttl]:
                    del self._data[k]
                if len(self._data) >= self.max_entries:
                    self._data.pop(next(iter(self._data)))  # oldest insertion
            self._data[key] = (value, time.time())

    def __len__(self):
        with self._lock:
            return len(self._data)
