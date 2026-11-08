"""Retry plan (E-27): the first retry one minute after the first attempt, then gaps growing by a fixed factor so that
the last attempt is at the give-up time. Same algorithm as App\\Core\\Send\\SendProfileService::retrySchedule."""
from datetime import timedelta

FIRST_RETRY_SEC = 60
MIN_GAP_SEC = 30   # never retry sooner than this after an attempt (worker was behind)


def retry_schedule(attempts, seconds):
    """Seconds after the first attempt at which the retries happen (attempts - 1 values, the last = seconds)."""
    gaps = attempts - 1
    if gaps <= 0:
        return []

    def total(f):
        return FIRST_RETRY_SEC * gaps if abs(f - 1.0) < 1e-9 else FIRST_RETRY_SEC * (f ** gaps - 1) / (f - 1)

    lo, hi = 1.0, 10.0
    for _ in range(100):
        mid = (lo + hi) / 2
        if total(mid) < seconds:
            lo = mid
        else:
            hi = mid
    out, t = [], 0.0
    for k in range(gaps):
        t += FIRST_RETRY_SEC * lo ** k
        out.append(seconds if k == gaps - 1 else int(round(t)))
    return out


def next_attempt(created, expires, retry_attempts, attempts_done, now):
    """When to try again after attempts_done attempts, or None if no attempt is left (hardfail)."""
    if attempts_done >= retry_attempts or now >= expires:
        return None
    plan = retry_schedule(retry_attempts, max(1, int(round((expires - created).total_seconds()))))
    due = created + timedelta(seconds=plan[attempts_done - 1])
    due = max(due, now + timedelta(seconds=MIN_GAP_SEC))
    return min(due, expires)
