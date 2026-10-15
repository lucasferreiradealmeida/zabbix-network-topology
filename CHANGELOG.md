# Mudanças

## 1.4.0

- Primeira versão pública (licença MIT), com README em inglês.
- Nomes neutros: templates `Template Capacity Planning …`, circuitos de exemplo `NET-<UF>-<NNNN>` e
  equipamentos genéricos no laboratório. Ao atualizar, renomeie antes os 4 templates de capacity antigos
  para os nomes novos (veja "Atualizando de uma versão anterior" no README.pt-BR.md): assim o Zabbix atualiza os mesmos
  templates e o histórico continua.

## 1.3.2

- **Simulação de upgrade sem o botão "Simular"**: escolher a capacidade na lista (com o mouse ou com as setas do
  teclado) já simula, então o botão ficou sobrando e saiu.

## 1.3.1

- **Zoom com a roda do mouse** no mapa de topologia: cada clique da roda aproxima ou afasta cerca de 20%, no
  ponto do cursor (antes era menos de 1% por clique). Touchpad continua suave, e a pinça (dois dedos) também
  dá zoom. Sobre os painéis (legenda, detalhes) a roda não mexe no mapa.
- **Simulação de upgrade** sem voltar ao topo: ao trocar a capacidade ou clicar em **Simular**, só a tabela
  da simulação é atualizada, no mesmo lugar da página. O endereço guarda o valor simulado.
- Teste de navegador `tests/ui/interactions.js` para os dois casos.

## 1.3.0

**Capacity planning — visão geral, análises e simulação**

- Quatro abas, uma por pergunta: **Visão geral** (onde prestar atenção agora), **Circuitos**, **Mapa da
  cadeia** e **Análises & upgrades** (o que vai saturar e o que fazer).
- Visão geral: circuitos monitorados, utilização média, circuitos acima de 80%, alertas críticos, previsão
  de saturação e capacidade livre da cadeia; **situação** de cada circuito (crítico, alto, médio, normal) e
  **ranking por risco** com cliente/site, utilização, média de 30 dias e previsão.
- **Capacidade recomendada** por circuito (ex.: 1 Gbps → 2 Gbps, ou ampliar um uplink), pela tendência e por
  uma meta por trecho, com os avisos "verificar o caminho antes" e "porta física menor que a nova banda".
- **Simulação de upgrade** no detalhe do circuito: hoje → depois em cada trecho, no pior caso.
- Trecho a trecho com valores em bits/s, média de 30 dias e crescimento mensal.
- **Mapa da cadeia**: o mapa de topologia com os links coloridos pela utilização (60/80/90%) e os circuitos
  que dependem de cada link (botão de medidor no mapa).
- Os problemas de capacidade trazem um link para o circuito (alerta → circuito).
- Configurações: metas de utilização por trecho e horizonte de planejamento.
- Templates de capacity 1.3: itens "utilização média" e "crescimento do p95 em 30 dias", tag `alias` e URL
  nos triggers.

## 1.2.0

**Mapa de topologia**

- Mapas salvos com nome: a seleção do filtro, as posições, o formato dos links e os desenhos. **Públicos**
  (o padrão de todas as equipes) ou **pessoais**. Mapa inicial para quem ainda não escolheu um.
- Mapa salvo abre **travado**; o cadeado destrava para mexer na própria tela e **Restaurar** volta ao original.
- Sem mapa salvo, o arranjo automático é sempre o mesmo para os mesmos hosts; novo arranjo **Camadas**
  (core no topo, distribuição no meio, CPEs embaixo, vizinhos abaixo de quem os vê).
- **Modo edição**: grade com encaixe, links curvos (arrastar), cotovelo e reta; zonas (retângulo/elipse) que
  levam junto os hosts de dentro, textos e linhas/setas; painel de propriedades; Delete apaga.
- **Publicar como mapa nativo do Zabbix** (hosts, zonas, textos, linhas e os triggers das portas em cada link),
  para usar em dashboards. Portas abreviadas e, onde os rótulos dos links se sobreporiam, só o primeiro; o
  nome do mapa nativo não muda quando alguém de outro idioma publica de novo. Datas no idioma do usuário.
- Quem altera mapas públicos: Admins e Super admins por padrão; configurável (somente Super admins e/ou grupos
  de usuários) na nova página de **Configurações**. Aviso de conflito quando duas pessoas salvam o mesmo mapa.
- **Visual novo**: blocos com selo de problemas, links contínuos, rótulos em etiquetas, barra de ferramentas
  vertical, cabeçalho com seletor de mapas, legenda recolhível, fundo quadriculado. Nomes das portas abreviados
  nos links (`XGE0/0/1`, `Gi0/1`), nomes longos de vizinhos em duas linhas, nome das zonas sempre legível.
- Removido: "Salvar layout" por filtro da 1.1 (use **Salvar como novo mapa**).

**Capacity planning**

- Gargalo escolhido pelos **4 critérios** do documento de planejamento: maior utilização, menor capacidade
  livre, satura primeiro e mais circuitos afetados.
- Tabela **trecho a trecho**: capacidade nominal, capacidade usada, utilização atual, p95, tendência, capacidade
  livre, tempo até saturar, impacto, se comporta a banda contratada e estado.
- **Banda contratada** do circuito e o aviso "sem folga para a banda contratada".
- Pontos do caminho: **CLIENTE → POP → uplink do POP → agregação regional → core (interface LAN) → troncos**.
- **CIRCUIT_ID nos 3 papéis** (CPE, distribuição, backbone) por circuito e no resumo.
- Macro de host `{$CIRCUIT_ID}` / `{$CIRCUIT_ID.IF}` e tag `cid_source` (templates 1.2).
- `tools/capacity_enrich.py`: preenche CIRCUIT_ID e banda contratada pela API a partir de CSV/CMDB,
  inventário, tag ou nome do host.

**Laboratório**: circuito NET-RJ-0002 pela macro de host, NET-RJ-0003 sem folga para o contratado, usuários
`noc` (leitura) e `engenharia` (editor) para testar os mapas públicos.

## 1.1.0

Capacity planning por circuito (templates em 3 camadas, página de planejamento, dashboard por circuito,
destaque do caminho no mapa).

## 1.0.1

Carregamento da biblioteca de gráficos pelo `zabbix.php` quando o servidor web não entrega os arquivos inteiros.

## 1.0.0

Primeira versão: topologia por LLDP/CDP, tags, mapas e dependências.
