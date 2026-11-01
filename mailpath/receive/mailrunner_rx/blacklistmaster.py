"""blacklistmaster.com IP check, same behaviour and result format as SmartMailFlow's receive pipe
(debug["blacklistmaster"]): JSON on success, "http_<code>" or "error (<Type>)" otherwise."""


def check(ip, mail_id, user, password, timeout=6):
    if (mail_id or "").lower().startswith("selftest-"):
        return {"status": "skipped", "reason": "selftest"}  # no API quota for self tests
    import requests
    try:
        resp = requests.get(f"https://www.blacklistmaster.com/restapi/v0/blacklistcheck/ip/{ip}",
                            auth=(user, password), timeout=timeout)
        return resp.json() if resp.ok else f"http_{resp.status_code}"
    except Exception as e:
        return f"error ({e.__class__.__name__})"
