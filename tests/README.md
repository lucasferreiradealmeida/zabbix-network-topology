# Testes de desenvolvimento

- `run_preproc.js` — roda um script de pré-processamento dos templates (`templates/src/*.js`) sobre um SNMP walk,
  como o Zabbix server faz, e valida que o código é ES5 (Duktape). Requer `npm install` (acorn).
  `node run_preproc.js ../templates/src/lldp_neighbors.js fixtures/huawei_pe1.walk`
  Com macros e NetBox simulado: `node run_preproc.js ../templates/src/capacity_lld.js fixtures/capacity_dist.walk macros.json netbox-mock.json`
  (`macros.json` = `{"{$CAPACITY.SEGMENT}": "distribuicao"}`; o mock responde `HttpRequest.get()` por trecho de URL).
  Macro de host `{$CIRCUIT_ID}`: `node run_preproc.js ../templates/src/capacity_lld.js fixtures/capacity_dist.walk fixtures/capacity_cpe_macro.json`
  (a interface `XGigabitEthernet0/0/5`, sem CID na descrição, recebe `NET-TO-0042` com `{#CID_SOURCE}` = `macro`).
- `fixtures/*.walk` — saídas de `walk[]` no formato do Zabbix (Huawei, Cisco, CDP), com casos difíceis
  (strings multilinha, aspas escapadas, Hex-STRING quebrado em linhas, nomes UTF-8 em hexa).
  `capacity_dist.walk` traz descrições de interface com CID em vários formatos, vários circuitos na mesma porta,
  acento em Hex-STRING e palavras parecidas que não podem casar (`trunkline`, `PRECID`).
- `seed_test_data.py` — popula um Zabbix 7.x de teste (API + PostgreSQL) com a rede de exemplo, problemas,
  vizinho perdido e um mapa. `NETTOPO_DB=zabbix python3 seed_test_data.py http://127.0.0.1:8080/api_jsonrpc.php`
- `ui/` — testes de interface com Playwright contra o laboratório (`../lab`, grupo LAB):
  - `maps.js` — mapa salvo de ponta a ponta: criar público, travado/destravado, Restaurar, modo edição (zona,
    texto, linha, link curvo), salvar, recarregar e publicar como mapa nativo;
  - `maps_api.js` — permissões (usuário comum não altera mapa público, cópia pessoal), conflito entre dois
    editores, renomear, mapa pessoal invisível para os outros, limites e saneamento dos dados;
  - `selection.js <mapid>` — mapa salvo com o filtro alterado: o aviso no cabeçalho, **Restaurar** e o **Reset**
    do filtro trazem a seleção salva de volta;
  - `map_op.js '<json>'` — executa uma operação de mapa pela página (ex.: `{"operation":"delete","mapid":"s..."}`);
  - `demo_map.js <mapid>` — monta o mapa de demonstração (camadas, zonas, título, curvas) usado nas imagens;
  - `docs_shots.js <pasta> <mapid> [lost]` — gera as imagens de `docs/` a partir do mapa de demonstração
    (`lost`: só a do link perdido, depois de `network.py break`);
  - `interactions.js [usuário] [senha] [circuito]` — zoom com a roda do mouse no mapa (cerca de 20% por clique,
    no ponto do cursor; touchpad, linhas do Firefox e pinça) e a simulação de upgrade atualizada sem recarregar
    nem voltar ao topo da página;
  - `shot.js <caminho> <arquivo.png>` — abre uma página logado e salva uma captura;
  - `settings_set.py` — envia o formulário de configurações (quem edita os mapas públicos; com
    `CAPACITY=80,70,60,2`, as metas por trecho e o horizonte do capacity planning);
  - `zbx.py` — chamadas simples à API (Admin / zabbix).
  `PLAYWRIGHT_MODULE` aponta para o pacote do Playwright e `NETTOPO_BASE` para o frontend (padrão
  `http://127.0.0.1:8080`).

Para testar o módulo com um Zabbix server de verdade, use o laboratório em `../lab`.
