"""ipregistry.co lookup of the client IP (https://ipregistry.co/docs).

Only the fields MailRunner uses are requested (fields filter): AS (number, organization, domain,
route, type), country, city, continent, carrier name, security.is_threat. Loopback/private addresses
are not sent (ipregistry answers RESERVED_IP_ADDRESS for them anyway).

Result (debug["ipregistry"]):
  {"status": "ok", "ip", "asn", "as_organization", "as_domain", "as_route", "connection_type",
   "country_code", "country_name", "city", "continent_code", "continent_name", "carrier_name", "is_threat"}
  {"status": "skipped", "reason": ...} | {"status": "error", "error": "<ipregistry code or exception type>"}
The API key never appears in results or logs.
"""
import ipaddress

API = "https://api.ipregistry.co/"
FIELDS = ("ip,type,connection,location.country.code,location.country.name,location.city,"
          "location.continent.code,location.continent.name,carrier.name,security.is_threat")


def parse(data):
    conn = data.get("connection") or {}
    loc = data.get("location") or {}
    country = loc.get("country") or {}
    continent = loc.get("continent") or {}
    return {
        "status": "ok",
        "ip": data.get("ip"),
        "asn": conn.get("asn"),
        "as_organization": conn.get("organization"),
        "as_domain": conn.get("domain"),
        "as_route": conn.get("route"),
        "connection_type": conn.get("type"),
        "country_code": country.get("code"),
        "country_name": country.get("name"),
        "city": loc.get("city"),
        "continent_code": continent.get("code"),
        "continent_name": continent.get("name"),
        "carrier_name": (data.get("carrier") or {}).get("name"),
        "is_threat": (data.get("security") or {}).get("is_threat"),
    }


def lookup(ip, api_key, timeout=4):
    try:
        if not ipaddress.ip_address(ip).is_global:
            return {"status": "skipped", "reason": "not a public address"}
    except ValueError:
        return {"status": "skipped", "reason": "invalid address"}
    import requests
    try:
        resp = requests.get(API + ip, params={"key": api_key, "fields": FIELDS}, timeout=timeout,
                            headers={"User-Agent": "MailRunner"})
        body = resp.json() if resp.headers.get("content-type", "").startswith("application/json") else {}
        if resp.ok:
            return parse(body)
        return {"status": "error", "error": body.get("code") or f"http_{resp.status_code}"}
    except Exception as e:  # never include the exception text: it may contain the URL with the key
        return {"status": "error", "error": e.__class__.__name__}
