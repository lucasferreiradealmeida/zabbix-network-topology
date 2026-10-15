# Network Topology for Zabbix 7.x

**English** · [Português](README.pt-BR.md)

A Zabbix frontend module that adds two pages to **Monitoring**:

- **Network topology**: a live map of the network built from the **LLDP/CDP** neighbors of the devices.
  Hosts are colored by problem severity, links by port status. Maps can be saved with fixed positions,
  curved links and drawings (zones, texts, lines), shared as the team's standard map, and published as
  native Zabbix maps for dashboards.
- **Capacity planning**: capacity planning **per circuit** (circuit ID). It follows each circuit from the
  customer to the backbone, finds the **bottleneck**, forecasts when it saturates, recommends the next
  capacity and **simulates the upgrade** on every segment of the path.

Tested on **Zabbix 7.4** and **7.0** with real frontends and servers, and devices simulated over SNMP with
the official Huawei VRP, Cisco IOS and MikroTik templates.

![Topology map with zones and curved links](docs/tema-escuro.png)

## Topology map

- **Nodes are Zabbix hosts**, drawn as tiles with a device icon (router, switch, firewall, OLT, CPE, AP…).
  The border takes the color of the highest active problem and a badge counts the problems.
- **Neighbors that are not Zabbix hosts** appear smaller and dashed, so the map also shows what is not
  monitored yet.
- **Links** show the port name on each end, shortened like on the device (`XGE0/0/1`, `Gi0/1`, `Te1/1/1`):
  green = up, thick red = down, dotted red = **lost neighbor** (gone from LLDP), orange/yellow = a problem
  on the port. Traffic and utilization appear on the link; the width follows the link speed.
- **Link sources**: LLDP, CDP, host tags, links of existing Zabbix maps and trigger dependencies.
- **Saved maps**: public (the team's standard) or personal, opened locked; an edit mode to move hosts,
  bend links and draw zones, texts and arrows; publish as a native Zabbix map.
- **Navigation**: mouse wheel zooms around the cursor, click a host for the native Zabbix menu,
  double-click to highlight its neighborhood, search, automatic layouts (layers, forces, circle, grid),
  PNG export and kiosk mode.
- The interface follows the user's language (English or Portuguese).

![Lost neighbor detected on the map](docs/lab-link-perdido.png)

## Capacity planning

The same circuit ID is written on each point of the path (customer premises equipment, distribution
and backbone). The module collects those interfaces, completes the path with LLDP/CDP and answers:

| Tab | Question | What it shows |
|---|---|---|
| **Overview** | Where should I look now? | Circuits, average utilization, circuits above 80%, critical alerts, forecast of saturation, free capacity of the chain, and a **risk ranking** |
| **Circuits** | How is each customer doing? | Load of each segment (last mile, distribution, backbone), bottleneck, free capacity, forecast |
| **Chain map** | Where is the bottleneck? | The topology map with links colored by utilization and, on click, the circuits that depend on each link |
| **Analysis & upgrades** | What will saturate and what should I do? | Current use → monthly growth → forecast → contracted → **recommended capacity** (e.g. 1 Gbps → 2 Gbps), with a warning when the next bottleneck is upstream |

The bottleneck is the segment that is worse on most of **4 criteria**: highest utilization (95th
percentile), least free capacity, earliest saturation and most circuits affected.

The **upgrade simulation** answers "if this customer goes from 1G to 2G, what happens on each segment?"
in the worst case (the customer uses all of the new capacity).

![Capacity planning overview](docs/capacidade-visao-geral.png)

![Chain map: links colored by utilization](docs/capacidade-mapa.png)

![Analysis and upgrade simulation](docs/capacidade-simulacao.png)

How it is collected (SNMP templates in three layers, circuit ID by interface description, host macro,
NetBox or CSV, and every formula) is described in [docs/capacity-planning.md](docs/capacity-planning.md)
(in Portuguese).

## Try it in 2 commands (Docker lab)

The lab brings up Zabbix 7.4, PostgreSQL and an SNMP simulator with 6 devices, external neighbors and
6 sample circuits. Inside the `lab` folder:

```bash
docker compose up -d --build
docker compose run --rm tools python setup_lab.py
```

Open <http://localhost:8080> (Admin / zabbix) → **Monitoring → Network topology** and filter by the
group **LAB**. Neighbors appear in 1–3 minutes; capacity data in 5–10 minutes.

To break a link and watch the map react:

```bash
docker compose run --rm tools python network.py break   # PE1-VR <-> PE12-VARJAO goes down
docker compose restart snmpsim
docker compose run --rm tools python network.py fix
docker compose restart snmpsim
```

## Install on your Zabbix

1. Copy `module/nettopo` to the `modules` folder of the frontend
   (`/usr/share/zabbix/ui/modules/` on 7.2/7.4 packages, `/usr/share/zabbix/modules/` on 7.0 and Docker).
2. *Administration → General → Modules → Scan directory* and enable **Network Topology**.
3. Import `templates/nettopo_lldp_snmp.yaml` (and `nettopo_cdp_snmp.yaml` for Cisco) and link
   **Network Topology LLDP by SNMP** to your routers and switches. Keep the interface templates you already
   use: port status and traffic come from them.
4. For capacity planning, import `templates/capacity_planning.yaml` and link the role profiles
   (`Template Capacity Planning - Backbone`, `- Distribuicao`, `- CPE`).

Devices need LLDP enabled and the LLDP-MIB exposed over SNMP. Full details, macros and troubleshooting
are in [README.pt-BR.md](README.pt-BR.md).

## Repository layout

| Folder | Contents |
|---|---|
| `module/nettopo/` | The frontend module (PHP views and controllers, JavaScript map) |
| `templates/` | LLDP, CDP and capacity planning templates (Zabbix 7.0 format) and the script that builds them |
| `tools/` | `capacity_enrich.py`: fills circuit IDs and contracted bandwidth from a CSV/CMDB, inventory, tags or host names |
| `lab/` | Docker lab with a simulated network |
| `tests/` | SNMP walk fixtures, preprocessing tests and browser tests (Playwright) |
| `docs/` | Capacity planning guide and screenshots |

## How it is built

- **Zabbix module API** (PHP controllers and views) with no change to the Zabbix database schema: public
  maps live in the module configuration, personal maps in the user profile.
- **Map** drawn with [Cytoscape.js](https://js.cytoscape.org/) and the fCoSE layout, bundled locally (no
  internet access needed).
- **Templates** generated by a Python script: SNMP walks, JavaScript preprocessing (ES5, as run by the
  Zabbix server), low-level discovery, calculated items for the 95th percentile, average, growth and
  forecast.
- **Tests**: preprocessing scripts run against SNMP walk fixtures; Playwright scripts check the map,
  saved maps, zoom and the capacity pages on Zabbix 7.4 and 7.0.

## License

[MIT](LICENSE). Cytoscape.js and fCoSE are MIT licensed; their licenses are in
`module/nettopo/assets/lib/LICENSES.txt`.
