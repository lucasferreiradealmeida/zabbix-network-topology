<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Finds the Zabbix host behind a neighbor seen by LLDP/CDP (or named in a host tag):
** by alias tag, technical/visible name, IP address, short name (without domain), loose name or MAC.
**/

namespace Modules\NetTopo\Lib;

use API;

class HostIndex {

	public const TAG_ALIAS = 'topology.alias';

	private bool $loaded = false;

	/** @var array<string, string[]> */
	private array $by_alias = [];
	private array $by_name = [];
	private array $by_short = [];
	private array $by_loose = [];
	private array $by_ip = [];
	private array $by_mac = [];

	/**
	 * Hosts already loaded by the caller (hostid => host with host, name, interfaces, tags, inventory).
	 */
	private array $known_hosts;

	public function __construct(array $known_hosts = []) {
		$this->known_hosts = $known_hosts;
	}

	/**
	 * Loads a light index of all monitored hosts the current user can see.
	 */
	private function load(): void {
		if ($this->loaded) {
			return;
		}

		$this->loaded = true;

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'selectInterfaces' => ['ip'],
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		foreach ($hosts as $hostid => $host) {
			$this->addHost((string) $hostid, $host);
		}

		$alias_hosts = API::Host()->get([
			'output' => ['hostid'],
			'selectTags' => ['tag', 'value'],
			'selectInventory' => ['macaddress_a', 'macaddress_b'],
			'monitored_hosts' => true,
			'tags' => [['tag' => self::TAG_ALIAS, 'operator' => TAG_OPERATOR_EXISTS]],
			'preservekeys' => true
		]);

		foreach ($alias_hosts as $hostid => $host) {
			$this->addAliases((string) $hostid, $host);
		}

		foreach ($this->known_hosts as $hostid => $host) {
			$this->addAliases((string) $hostid, $host);
		}
	}

	private function addHost(string $hostid, array $host): void {
		foreach ([$host['host'], $host['name']] as $name) {
			$name = (string) $name;

			if ($name === '') {
				continue;
			}

			self::push($this->by_name, mb_strtolower($name), $hostid);
			self::push($this->by_short, self::shortName($name), $hostid);
			self::push($this->by_loose, self::looseName($name), $hostid);
		}

		foreach ($host['interfaces'] ?? [] as $interface) {
			if ($interface['ip'] !== '' && $interface['ip'] !== '127.0.0.1') {
				self::push($this->by_ip, $interface['ip'], $hostid);
			}
		}
	}

	private function addAliases(string $hostid, array $host): void {
		foreach ($host['tags'] ?? [] as $tag) {
			if ($tag['tag'] !== self::TAG_ALIAS) {
				continue;
			}

			foreach (preg_split('/[,;]+/', $tag['value']) as $alias) {
				$alias = trim($alias);

				if ($alias === '') {
					continue;
				}

				$mac = PortName::normalizeMac($alias);

				if ($mac !== '') {
					self::push($this->by_mac, $mac, $hostid);
				}

				self::push($this->by_alias, mb_strtolower($alias), $hostid);
			}
		}

		if (is_array($host['inventory'] ?? null)) {
			foreach (['macaddress_a', 'macaddress_b'] as $field) {
				foreach (preg_split('/[\s,;]+/', (string) ($host['inventory'][$field] ?? '')) as $value) {
					$mac = PortName::normalizeMac($value);

					if ($mac !== '') {
						self::push($this->by_mac, $mac, $hostid);
					}
				}
			}
		}
	}

	private static function push(array &$index, string $key, string $hostid): void {
		if ($key === '') {
			return;
		}

		if (!array_key_exists($key, $index)) {
			$index[$key] = [];
		}

		if (!in_array($hostid, $index[$key], true)) {
			$index[$key][] = $hostid;
		}
	}

	/**
	 * Name without domain and without a CDP serial suffix: "sw01.corp.local" / "SW01(FOC123)" => "sw01".
	 */
	public static function shortName(string $name): string {
		$name = mb_strtolower(trim($name));
		$name = preg_replace('/\(.*\)$/', '', $name);

		// Strip a DNS domain only ("sw01.corp.local"), keep IPs and names like "ARC-VR_1.1" intact.
		$dot = strpos($name, '.');

		if ($dot !== false && $dot > 0 && preg_match('/^[a-z][a-z0-9-]*(\.[a-z0-9-]+)*$/', substr($name, $dot + 1))) {
			$name = substr($name, 0, $dot);
		}

		return $name;
	}

	public static function looseName(string $name): string {
		return preg_replace('/[^a-z0-9]/', '', self::shortName($name));
	}

	private static function unique(array $index, string $key): ?string {
		return (array_key_exists($key, $index) && count($index[$key]) == 1) ? $index[$key][0] : null;
	}

	/**
	 * Finds a host for a neighbor. Returns hostid or null.
	 *
	 * @param string   $name     Neighbor name (sysName, CDP device ID, tag value).
	 * @param string[] $ips      Neighbor management addresses.
	 * @param string   $chassis  Neighbor chassis ID (MAC address, name...).
	 */
	public function find(string $name, array $ips = [], string $chassis = ''): ?string {
		$this->load();

		$name = trim($name);
		$lower = mb_strtolower($name);

		if ($name !== '') {
			if (($hostid = self::unique($this->by_alias, $lower)) !== null) {
				return $hostid;
			}

			if (($hostid = self::unique($this->by_name, $lower)) !== null) {
				return $hostid;
			}
		}

		if ($chassis !== '') {
			if (($hostid = self::unique($this->by_alias, mb_strtolower($chassis))) !== null) {
				return $hostid;
			}

			$mac = PortName::normalizeMac($chassis);

			if ($mac !== '' && ($hostid = self::unique($this->by_mac, $mac)) !== null) {
				return $hostid;
			}
		}

		foreach ($ips as $ip) {
			if (($hostid = self::unique($this->by_ip, $ip)) !== null) {
				return $hostid;
			}
		}

		if ($name !== '') {
			if (($hostid = self::unique($this->by_short, self::shortName($name))) !== null) {
				return $hostid;
			}

			$loose = self::looseName($name);

			if (strlen($loose) >= 3 && ($hostid = self::unique($this->by_loose, $loose)) !== null) {
				return $hostid;
			}
		}

		// A name that is an IP address.
		if ($name !== '' && filter_var($name, FILTER_VALIDATE_IP) && ($hostid = self::unique($this->by_ip, $name))) {
			return $hostid;
		}

		return null;
	}
}
