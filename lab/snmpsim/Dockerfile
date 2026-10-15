# SNMP simulator (snmpsim) serving the lab devices from /data/*.snmprec on UDP 1161.
FROM python:3.12-alpine

# growth.py: lab-only variation module (traffic that grows over time, for the capacity forecast demo),
# installed where snmpsim looks for variation modules (<sys.prefix>/share/snmpsim/variation).
COPY growth.py /tmp/growth.py

RUN pip install --no-cache-dir snmpsim==1.2.2 pysnmp==7.1.30 pysmi==2.0.0 \
    && dir="$(python -c 'import os, sys; print(os.path.join(sys.prefix, "share", "snmpsim", "variation"))')" \
    && mkdir -p "$dir" && mv /tmp/growth.py "$dir/growth.py" \
    && adduser -D -H -u 10001 snmpsim

USER snmpsim

EXPOSE 1161/udp

CMD ["snmpsim-command-responder", "--data-dir=/data", "--cache-dir=/tmp/snmpsim-cache", \
     "--agent-udpv4-endpoint=0.0.0.0:1161"]
