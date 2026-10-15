<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Interface name helpers: different devices (and LLDP/CDP) spell the same port differently,
** e.g. "Gi1/0/1", "GigabitEthernet1/0/1" or "GE1/0/1". Names are reduced to a canonical form.
**/

namespace Modules\NetTopo\Lib;

class PortName {

	/**
	 * Abbreviation => canonical prefix. Longest prefixes are tried first.
	 */
	private const PREFIXES = [
		'hundredgigabitethernet' => 'hundredgige',
		'hundredgige' => 'hundredgige',
		'hu' => 'hundredgige',
		'100ge' => 'hundredgige',
		'fortygigabitethernet' => 'fortygige',
		'fortygige' => 'fortygige',
		'fo' => 'fortygige',
		'40ge' => 'fortygige',
		'twentyfivegige' => 'twentyfivegige',
		'twe' => 'twentyfivegige',
		'25ge' => 'twentyfivegige',
		'tengigabitethernet' => 'tengige',
		'tengige' => 'tengige',
		'te' => 'tengige',
		'xgigabitethernet' => 'tengige',
		'xge' => 'tengige',
		'10ge' => 'tengige',
		'gigabitethernet' => 'gige',
		'gige' => 'gige',
		'gi' => 'gige',
		'ge' => 'gige',
		'fastethernet' => 'fe',
		'fa' => 'fe',
		'fe' => 'fe',
		'ethernet' => 'eth',
		'eth' => 'eth',
		'et' => 'eth',
		'port-channel' => 'po',
		'portchannel' => 'po',
		'po' => 'po',
		'eth-trunk' => 'trunk',
		'bundle-ether' => 'be',
		'be' => 'be',
		'ae' => 'ae',
		'vlanif' => 'vlan',
		'vlan' => 'vlan',
		'loopback' => 'lo',
		'lo' => 'lo'
	];

	/**
	 * Usual short forms for display (labels on links); the full name stays in tooltips and tables.
	 */
	private const SHORT = [
		'/^HundredGig(?:abit)?E(?:thernet)?/i' => 'Hu',
		'/^FortyGig(?:abit)?E(?:thernet)?/i' => 'Fo',
		'/^TwentyFiveGig(?:abit)?E(?:thernet)?/i' => 'Twe',
		'/^TenGig(?:abit)?E(?:thernet)?/i' => 'Te',
		'/^XGigabitEthernet/i' => 'XGE',
		'/^GigabitEthernet/i' => 'Gi',
		'/^FastEthernet/i' => 'Fa',
		'/^Ethernet(?=\\s*\\d)/i' => 'Eth',
		'/^Port-channel/i' => 'Po',
		'/^Bundle-Ether/i' => 'BE'
	];

	private static ?array $sorted_prefixes = null;

	/**
	 * Canonical form of an interface name, used only for comparisons.
	 */
	public static function normalize(?string $name): string {
		if ($name === null) {
			return '';
		}

		$name = strtolower(trim($name));
		$name = preg_replace('/\s+/', '', $name);

		if ($name === '') {
			return '';
		}

		if (self::$sorted_prefixes === null) {
			self::$sorted_prefixes = array_keys(self::PREFIXES);
			usort(self::$sorted_prefixes, static fn($a, $b) => strlen($b) <=> strlen($a));
		}

		// Only treat it as an abbreviation when a digit follows the prefix (e.g. "gi0/1", not "general").
		foreach (self::$sorted_prefixes as $prefix) {
			if (strncmp($name, $prefix, strlen($prefix)) == 0) {
				$rest = substr($name, strlen($prefix));

				if ($rest !== '' && ctype_digit($rest[0])) {
					return self::PREFIXES[$prefix].$rest;
				}
			}
		}

		return $name;
	}

	/**
	 * Short form of an interface name for display: "XGigabitEthernet0/0/1" -> "XGE0/0/1".
	 */
	public static function short(string $name): string {
		foreach (self::SHORT as $pattern => $short) {
			if (preg_match($pattern, $name)) {
				return preg_replace($pattern, $short, $name, 1);
			}
		}

		return $name;
	}

	public static function equals(?string $a, ?string $b): bool {
		$a = self::normalize($a);

		return $a !== '' && $a === self::normalize($b);
	}

	public static function isMac(?string $value): bool {
		return $value !== null && preg_match('/^([0-9a-f]{2}[:\-]){5}[0-9a-f]{2}$/i', trim($value)) === 1;
	}

	/**
	 * MAC address reduced to 12 lowercase hex digits, or '' if the value is not a MAC address.
	 */
	public static function normalizeMac(?string $value): string {
		if ($value === null) {
			return '';
		}

		$hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $value));

		return strlen($hex) == 12 ? $hex : '';
	}
}
