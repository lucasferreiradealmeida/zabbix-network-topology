<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Plan B for the page scripts: the page normally loads them as static files from "assets", but when
** the web server does not deliver them intact (path blocked by a rule, file cut short by the file
** sharing of Docker Desktop on Windows/Mac...) the page asks for them here and PHP reads them instead.
**/

namespace Modules\NetTopo\Actions;

use CController,
	CControllerResponseData;

class TopologyLib extends CController {

	/**
	 * Page scripts in loading order: key => [file inside "assets", global variable it defines].
	 * Only these files can be requested.
	 */
	public const SCRIPTS = [
		'cytoscape' => ['lib/cytoscape.min.js', 'cytoscape'],
		'layout-base' => ['lib/layout-base.min.js', 'layoutBase'],
		'cose-base' => ['lib/cose-base.min.js', 'coseBase'],
		'cytoscape-fcose' => ['lib/cytoscape-fcose.min.js', 'cytoscapeFcose'],
		'nettopo' => ['js/nettopo.js', 'NetTopo']
	];

	public static function assetsDir(): string {
		return dirname(__DIR__).'/assets/';
	}

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['files' => 'array']);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => 'Invalid request.'
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$wanted = array_filter($this->getInput('files', array_keys(self::SCRIPTS)), 'is_string');
		$scripts = [];

		foreach (self::SCRIPTS as $key => [$file]) {
			if (in_array($key, $wanted, true)) {
				$code = @file_get_contents(self::assetsDir().$file);
				$scripts[] = ['key' => $key, 'code' => $code === false ? null : $code];
			}
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['scripts' => $scripts],
			JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		)]));
	}
}
