# Zabbix 7.4 web (nginx) with the Network Topology module inside the image.
# The module is copied, not mounted: on Docker Desktop (Windows/Mac) nginx may deliver files from a
# mounted folder cut short, and the page then cannot load its graph library.
FROM zabbix/zabbix-web-nginx-pgsql:alpine-7.4-latest

COPY nettopo /usr/share/zabbix/modules/nettopo
