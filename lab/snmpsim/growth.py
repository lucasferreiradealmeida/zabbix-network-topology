# snmpsim variation module (lab only): a Counter64 traffic counter whose rate grows over time,
# so the capacity forecast ("days until saturation") has a trend to show.
#
# .snmprec value: "t0=<epoch>,start=<bit/s>,growth=<bit/s per hour>,cap=<bit/s>"
# The rate starts at "start" at time t0, grows linearly and stops at "cap". The counter is a pure
# function of the clock, so restarting the simulator does not reset it.
import time


def init(**context):
    pass


def variate(oid, tag, value, **context):
    if not context["nextFlag"] and not context["exactMatch"]:
        return context["origOid"], tag, context["errorStatus"]

    if context["setFlag"]:
        return context["origOid"], tag, context["errorStatus"]

    # Parsed on every request (no cache): "setup_lab.py" rewrites t0 and the new value applies at once.
    s = {}
    for part in value.split(","):
        key, _, number = part.partition("=")
        s[key.strip()] = float(number)

    t = max(0.0, time.time() - s["t0"])
    start = s["start"]
    growth = s.get("growth", 0.0) / 3600.0
    cap = s.get("cap", 1e18)
    t_cap = (cap - start) / growth if growth > 0 else float("inf")

    if t <= t_cap:
        bits = start * t + growth * t * t / 2
    else:
        bits = start * t_cap + growth * t_cap * t_cap / 2 + cap * (t - t_cap)

    return oid, tag, int(bits / 8) % (2 ** 64)


def shutdown(**context):
    pass
