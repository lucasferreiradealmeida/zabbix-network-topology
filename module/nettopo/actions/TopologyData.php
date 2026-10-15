<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** JSON endpoint with the graph (nodes, edges, statuses). Read-only.
**/

namespace Modules\NetTopo\Actions;

use APP,
	CController,
	CControllerResponseData,
	CMessageHelper,
	Throwable;

use Modules\NetTopo\Lib\CapacityBuilder,
	Modules\NetTopo\Lib\TopologyBuilder;

class TopologyData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'groupids' =>	'array_id',
			'hostids' =>	'array_id',
			'mapids' =>		'array_id',
			'sources' =>	'array',
			'traffic' =>	'in 0,1',
			'circuit' =>	'string',
			'capacity' =>	'in 0,1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$messages = array_column(CMessageHelper::getMessages(), 'message');
			CMessageHelper::clear();

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => $messages ?: ['Invalid request.']]
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$module = APP::ModuleManager()->getActionModule();
		$config = $module !== null ? $module->getConfig() : [];

		$sources = array_values(array_intersect(TopologyBuilder::SOURCES,
			array_filter($this->getInput('sources', []), 'is_string')
		));

		try {
			$output = (new TopologyBuilder([
				'groupids' => $this->getInput('groupids', []),
				'hostids' => $this->getInput('hostids', []),
				'mapids' => $this->getInput('mapids', []),
				'sources' => $sources,
				'traffic' => (bool) $this->getInput('traffic', 1),
				'max_hosts' => (int) ($config['max_hosts'] ?? 2000)
			]))->build();

			// Capacity planning: highlight the path of a circuit.
			$cid = strtoupper(trim($this->getInput('circuit', '')));

			if ($cid !== '' && CapacityBuilder::isValidId($cid)) {
				$output['circuit'] = CapacityBuilder::onMap($cid, $output, (int) ($config['max_hosts'] ?? 2000));
			}

			// Chain map: utilization band and dependent circuits of each link.
			if ($this->getInput('capacity', 0)) {
				$output['capacity'] = CapacityBuilder::onMapAll($output, (int) ($config['max_hosts'] ?? 2000),
					$config['capacity'] ?? []
				);
			}
		}
		catch (Throwable $e) {
			$output = ['error' => ['messages' => [$e->getMessage()]]];
		}

		// Messages raised by the frontend or API (missing locale, permissions...) are shown as warnings.
		$messages = [];

		foreach (CMessageHelper::getMessages() as $message) {
			if ($message['type'] !== CMessageHelper::MESSAGE_TYPE_SUCCESS) {
				$messages[] = $message['message'];
			}
		}

		CMessageHelper::clear();

		if ($messages) {
			if (array_key_exists('error', $output)) {
				$output['error']['messages'] = array_merge($output['error']['messages'], $messages);
			}
			else {
				$output['warnings'] = array_values(array_unique(array_merge($output['warnings'], $messages)));
			}
		}

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($output, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
		]));
	}
}
