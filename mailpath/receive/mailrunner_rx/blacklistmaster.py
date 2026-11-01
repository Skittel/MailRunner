"""blacklistmaster.com IP check, same behaviour and result format as SmartMailFlow's receive pipe
(debug["blacklistmaster"]): JSON on success, "http_<code>" or "error (<Type>)" otherwise."""


def check(ip, mail_id, user, password, timeout=6):
    import ipaddress
    if (mail_id or "").lower().startswith("selftest-"):
        return {"status": "skipped", "reason": "selftest"}  # no API quota for self tests
    try:
        if not ipaddress.ip_address(ip).is_global:
            return {"status": "skipped", "reason": "not a public address"}  # loopback/private: no API quota
    except ValueError:
        return {"status": "skipped", "reason": "invalid address"}
    import requests
    try:
        resp = requests.get(f"https://www.blacklistmaster.com/restapi/v0/blacklistcheck/ip/{ip}",
                            auth=(user, password), timeout=timeout)
        return resp.json() if resp.ok else f"http_{resp.status_code}"
    except Exception as e:
        return f"error ({e.__class__.__name__})"
