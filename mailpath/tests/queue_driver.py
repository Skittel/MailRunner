"""Runs mailrunner_tx.queue against a real database for the PHP feature test tests/feature/Send/SendDeliveryTest.php.

stdin: {"db": {...}, "steps": [{"claim": n} | {"record": {"<recipient>": "sent|bounced|deferred"}}
                               | {"due_tests": n} | {"record_test": {"outcome": "passed|failed|unavailable", "key": "<sha>"?, "details": {...}}}]}
stdout: one JSON line per step. Database credentials come on stdin, never on the command line.
"""
import datetime as dt
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "send"))

from mailrunner_tx import queue, smtp, worker  # noqa: E402


def main():
    req = json.load(sys.stdin)
    q = queue.Queue(req["db"])
    claimed = []
    tests = []
    for step in req["steps"]:
        if "claim" in step:
            claimed, changed = q.claim(step["claim"])
            print(json.dumps({"claimed": sorted(m["recipient"] for m in claimed), "changed": sorted(changed), "pings": q.pending_pings}))
        elif "due_tests" in step:
            tests = q.due_tests(step["due_tests"])
            print(json.dumps({"tests": [{"domain": r["domain"], "trigger": r["trigger"]} for r in tests]}))
        elif "record_test" in step:
            spec = step["record_test"]
            out = [q.record_test(r, spec.get("key") or r["key_sha256"], queue.utcnow(), spec["outcome"], spec.get("details") or {}) for r in tests]
            print(json.dumps({"recorded": out}))
        else:
            now = queue.utcnow()
            results = []
            for m in claimed:
                want = step["record"].get(m["recipient"])
                if want is None:
                    continue
                code = {"sent": 250, "bounced": 550, "deferred": 451}[want]
                t = smtp.Try("data" if want == "sent" else "rcpt", want, [m["recipient"]], code, f"{code // 100}.0.0", f"{code} test answer",
                             mx_host="mx.example.net", mx_ip="192.0.2.25", local_ip="192.0.2.1", tls_version="TLSv1.3", duration_ms=12)
                status, reason, nxt = worker.decide(m, t, now)
                results.append({"message": m, "tries": [smtp.Try("connect", "deferred", [m["recipient"]], None, None, "refused", mx_host="mx0.example.net"), t],
                                "status": status, "reason": reason, "next_at": nxt})
            q.record(results)
            print(json.dumps({"recorded": len(results), "pings": q.pending_pings}))


main()
