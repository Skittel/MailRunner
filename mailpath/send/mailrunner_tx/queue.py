"""The send queue in MariaDB (tables of node 0.2.4, E-27 step 4).

claim()  takes due messages (FOR UPDATE SKIP LOCKED, so several workers never take the same message) and applies the
         gates before anything is sent:
           mail file or DKIM key missing  -> failed (mail_missing / dkim_missing)
           past expires_at                -> failed (expired)
           project or customer disabled   -> deferred (project_disabled), checked again in 5 min
           domain test not passed for exactly the current DKIM key -> waiting_test, checked again every 15 s;
             5 min after the submission -> failed (dns_not_verified)   [the test itself comes with step 5]
           otherwise                      -> sending, leased for LEASE seconds
record() writes the tries of a delivery and the new status of each message.
Every status change takes a change_seq from send_sequence inside the same transaction (see SendSequence.php) and
marks the project's webhook pending (event "delivery").
"""
import datetime as dt
import os

import pymysql

LEASE = 1200
SOCKET = os.environ.get("MR_DB_SOCKET", "/run/mysqld/mysqld.sock")
TEST_WAIT = 300        # seconds a mail waits for a passed domain test (E-27: 5 min)
TEST_RECHECK = 15
DISABLED_RECHECK = 300
OPEN = ("queued", "deferred", "waiting_test")


def utcnow():
    return dt.datetime.now(dt.timezone.utc).replace(tzinfo=None)


class Queue:
    def __init__(self, db_settings, connect=pymysql.connect):
        self.db_settings = db_settings
        self.connect = connect
        self.conn = None
        self.pending_pings = 0   # projects with a pending webhook after the last write (worker wakes the sender)

    def _conn(self):
        if self.conn is None:
            params = dict(host=self.db_settings["host"], port=self.db_settings["port"], user=self.db_settings["user"],
                          password=self.db_settings["password"], database=self.db_settings["database"],
                          charset="utf8mb4", autocommit=False, cursorclass=pymysql.cursors.DictCursor)
            if params["host"] in ("localhost", "") and os.path.exists(SOCKET):
                params["unix_socket"] = SOCKET   # like PHP mysqli: "localhost" means the local socket
            self.conn = self.connect(**params)
        else:
            self.conn.ping(reconnect=True)
        return self.conn

    def _tx(self, fn):
        conn = self._conn()
        try:
            with conn.cursor() as cur:
                cur.execute("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED")
                result = fn(cur)
            conn.commit()
            return result
        except Exception:
            conn.rollback()
            raise

    @staticmethod
    def take_seq(cur, n=1):
        cur.execute("UPDATE send_sequence SET seq = LAST_INSERT_ID(seq + %s) WHERE id = 1", (n,))
        cur.execute("SELECT LAST_INSERT_ID() AS s")
        return int(cur.fetchone()["s"]) - n + 1

    def claim(self, limit, now=None):
        """Due messages that may be sent now, leased to this worker. Returns (claimed rows, project ids changed)."""
        def run(cur):
            t = now or utcnow()
            # leases of a crashed worker: back to deferred (the delivery may or may not have happened)
            cur.execute("SELECT id, project_id FROM send_messages WHERE status = 'sending' AND lease_until < %s FOR UPDATE SKIP LOCKED", (t,))
            stale = cur.fetchall()
            changed = set()
            for r in stale:
                self._set(cur, r["id"], "deferred", "worker_lease_expired", t, next_at=t)
                changed.add(r["project_id"])
            # candidates without locks, then lock only the message rows (never projects: submissions lock those)
            cur.execute("SELECT id FROM send_messages WHERE status IN ('queued', 'deferred', 'waiting_test') AND next_attempt_at <= %s "
                        "ORDER BY next_attempt_at LIMIT %s", (t, limit))
            ids = [r["id"] for r in cur.fetchall()]
            if not ids:
                self._mark_webhooks(cur, changed, t)
                return [], changed
            marks = ",".join(["%s"] * len(ids))
            cur.execute(f"SELECT id FROM send_messages WHERE id IN ({marks}) AND status IN ('queued', 'deferred', 'waiting_test') "
                        f"AND next_attempt_at <= %s FOR UPDATE SKIP LOCKED", (*ids, t))
            locked = [r["id"] for r in cur.fetchall()]
            if not locked:
                self._mark_webhooks(cur, changed, t)
                return [], changed
            marks = ",".join(["%s"] * len(locked))
            cur.execute(
                f"""SELECT m.*, s.submission_id, s.envelope_from, s.from_domain, s.raw_path,
                          d.id AS domain_row, d.dkim_selector, d.dkim_algorithm, d.key_sha256, d.test_status, d.test_key_sha256,
                          p.enabled AS project_enabled, c.enabled AS customer_enabled
                   FROM send_messages m
                   JOIN send_submissions s ON s.id = m.submission_row_id
                   JOIN projects p ON p.id = m.project_id JOIN customers c ON c.id = p.customer_id
                   LEFT JOIN send_domains d ON d.project_id = s.project_id AND d.domain = s.from_domain
                   WHERE m.id IN ({marks}) ORDER BY m.next_attempt_at""", locked)
            claimed = []
            for m in cur.fetchall():
                decision = self.gate(m, t)
                status, reason, next_at = decision
                if status == "sending":
                    cur.execute("UPDATE send_messages SET status = 'sending', status_reason = NULL, lease_until = %s, updated_at = %s, change_seq = %s WHERE id = %s",
                                (t + dt.timedelta(seconds=LEASE), t, self.take_seq(cur), m["id"]))
                    claimed.append(m)
                    continue
                if status != m["status"] or reason != m["status_reason"]:
                    self._set(cur, m["id"], status, reason, t, next_at=next_at)
                    changed.add(m["project_id"])
                else:   # same state: only the next check
                    cur.execute("UPDATE send_messages SET next_attempt_at = %s WHERE id = %s", (next_at, m["id"]))
            self._mark_webhooks(cur, changed, t)
            return claimed, changed
        return self._tx(run)

    @staticmethod
    def gate(m, t):
        """(status, reason, next_attempt_at) for a due message; status "sending" = deliver now."""
        if m["raw_path"] is None:
            return "failed", "mail_missing", None
        if m["domain_row"] is None:
            return "failed", "dkim_missing", None
        if t >= m["expires_at"]:
            return "failed", "expired", None
        if not m["project_enabled"] or not m["customer_enabled"]:
            return "deferred", "project_disabled", min(t + dt.timedelta(seconds=DISABLED_RECHECK), m["expires_at"])
        if m["test_status"] != "passed" or m["test_key_sha256"] != m["key_sha256"]:
            if t >= m["created_at"] + dt.timedelta(seconds=TEST_WAIT) and m["attempts"] == 0:
                return "failed", "dns_not_verified", None
            if m["attempts"] > 0:   # the test failed later (interval test): stop sending, keep retry timing
                return "deferred", "dns_not_verified", min(t + dt.timedelta(seconds=DISABLED_RECHECK), m["expires_at"])
            return "waiting_test", "domain_not_verified", t + dt.timedelta(seconds=TEST_RECHECK)
        return "sending", None, None

    def record(self, results, now=None):
        """results: list of dicts {message, tries: [Try], status, reason, next_at}. One transaction."""
        def run(cur):
            t = now or utcnow()
            changed = set()
            for r in results:
                m = r["message"]
                attempt_no = int(m["attempts"]) + 1
                for tr in r["tries"]:
                    cur.execute(
                        """INSERT INTO send_attempts (message_row_id, attempt_no, started_at, duration_ms, local_ip, mx_host, mx_ip, stage, result,
                               smtp_code, enhanced_code, response, tls_version, tls_cipher)
                           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                        (m["id"], attempt_no, dt.datetime.fromtimestamp(tr.started, dt.timezone.utc).replace(tzinfo=None), tr.duration_ms,
                         tr.local_ip, (tr.mx_host or "")[:253] or None, tr.mx_ip, tr.stage, tr.result, tr.code, tr.enhanced,
                         (tr.response or "")[:512] or None, tr.tls_version, (tr.tls_cipher or "")[:64] or None))
                last = r["tries"][-1] if r["tries"] else None
                cur.execute(
                    """UPDATE send_messages SET status = %s, status_reason = %s, attempts = %s, next_attempt_at = %s, lease_until = NULL,
                           last_code = %s, last_enhanced = %s, last_response = %s, sent_at = %s, updated_at = %s, change_seq = %s
                       WHERE id = %s AND status = 'sending'""",
                    (r["status"], r["reason"], attempt_no, r["next_at"], last.code if last else None, last.enhanced if last else None,
                     ((last.response or "")[:512] or None) if last else None, t if r["status"] == "sent" else None, t,
                     self.take_seq(cur), m["id"]))
                changed.add(m["project_id"])
            self._mark_webhooks(cur, changed, t)
            return changed
        return self._tx(run)

    def dkim_key(self, project_id, domain):
        def run(cur):
            cur.execute("SELECT dkim_selector, dkim_algorithm, dkim_private_enc, key_sha256 FROM send_domains WHERE project_id = %s AND domain = %s",
                        (project_id, domain))
            return cur.fetchone()
        return self._tx(run)

    def _set(self, cur, row_id, status, reason, t, next_at=None):
        cur.execute("UPDATE send_messages SET status = %s, status_reason = %s, next_attempt_at = %s, lease_until = NULL, updated_at = %s, change_seq = %s WHERE id = %s",
                    (status, reason, next_at, t, self.take_seq(cur), row_id))

    def _mark_webhooks(self, cur, project_ids, t):
        self.pending_pings = 0
        if not project_ids:
            return 0
        ids = sorted(project_ids)
        marks = ",".join(["%s"] * len(ids))
        cur.execute(f"UPDATE project_webhooks SET pending_at = %s WHERE pending_at IS NULL AND project_id IN ({marks})", (t, *ids))
        cur.execute(f"SELECT COUNT(*) AS n FROM project_webhooks WHERE pending_at IS NOT NULL AND project_id IN ({marks})", ids)
        self.pending_pings = int(cur.fetchone()["n"])
        return self.pending_pings
