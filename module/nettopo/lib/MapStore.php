<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Saved maps: a name, the host selection (filter) and the drawing (node positions, link bends, shapes).
**
**  - Public maps ("s" + 12 hex) are the standard for everybody. They live in the module configuration
**    (table "module", column "config") and are written directly, inside a transaction that locks the row,
**    because the module API only accepts Super admins and editors may be Admins or user groups.
**  - Personal maps ("p" + 12 hex) live in the profile of their owner (table "profiles", one row each).
**
** Layouts are stored compressed (gzdeflate + base64) when zlib is available: MySQL TEXT columns hold 64 KB.
**/

namespace Modules\NetTopo\Lib;

use API,
	CProfile,
	CWebUser,
	DB,
	RuntimeException,
	Throwable,
	Zabbix\Core\CModule;

class MapStore {

	public const PROFILE_MAPS = 'web.nettopo.map';
	public const PROFILE_CURRENT = 'web.nettopo.mapid';

	public const ID_PATTERN = '/^[sp][0-9a-f]{12}$/';

	public const SCOPE_SHARED = 'shared';
	public const SCOPE_PERSONAL = 'personal';

	/** Who may change public maps, unless the configuration says otherwise. */
	public const DEFAULT_EDIT_USER_TYPE = USER_TYPE_ZABBIX_ADMIN;

	public const SHAPE_TYPES = ['rect', 'ellipse', 'text', 'line'];
	public const LINE_STYLES = ['solid', 'dashed', 'dotted'];
	public const EDGE_STYLES = ['curve', 'taxi', 'straight'];

	private const MAX_NAME = 128;
	private const MAX_TEXT = 300;
	private const MAX_NODES = 10000;
	private const MAX_EDGES = 10000;
	private const MAX_SHAPES = 500;
	private const MAX_IDS = 2000;
	private const MAX_COORD = 1000000;

	/** One profile value is a TEXT column (65535 characters, also enforced by CProfile). */
	private const MAX_PROFILE_CHARS = 65000;

	private CModule $module;

	/** Configuration read from the database in this request (null = not read yet or changed since). */
	private ?array $config = null;

	public function __construct(CModule $module) {
		$this->module = $module;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Permissions
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Public maps: Super admins always; Admins (or only Super admins, as configured); members of the
	 * configured user groups.
	 */
	public function canEditShared(): bool {
		$type = (int) CWebUser::getType();

		if ($type == USER_TYPE_SUPER_ADMIN) {
			return true;
		}

		if (CWebUser::isGuest() || $type < USER_TYPE_ZABBIX_USER) {
			return false;
		}

		$settings = self::editors($this->config());

		if ($type >= $settings['user_type']) {
			return true;
		}

		if ($settings['usrgrpids']) {
			$groups = API::UserGroup()->get([
				'output' => [],
				'usrgrpids' => $settings['usrgrpids'],
				'userids' => [CWebUser::$data['userid']],
				'preservekeys' => true
			]);

			return (bool) $groups;
		}

		return false;
	}

	public static function canHavePersonal(): bool {
		return !CWebUser::isGuest() && CWebUser::getType() >= USER_TYPE_ZABBIX_USER;
	}

	/**
	 * @return array  ['user_type' => int, 'usrgrpids' => string[]]
	 */
	public static function editors(array $config): array {
		$editors = is_array($config['editors'] ?? null) ? $config['editors'] : [];
		$type = (int) ($editors['user_type'] ?? self::DEFAULT_EDIT_USER_TYPE);

		return [
			'user_type' => in_array($type, [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN], true)
				? $type
				: self::DEFAULT_EDIT_USER_TYPE,
			'usrgrpids' => array_values(array_filter(array_map('strval', (array) ($editors['usrgrpids'] ?? [])),
				'ctype_digit'
			))
		];
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Reading
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Configuration as stored in the database (the copy loaded with the module may be older within this request).
	 */
	public function config(): array {
		if ($this->config === null) {
			$row = DBfetch(DBselect('SELECT config FROM module WHERE moduleid='.zbx_dbstr($this->moduleId())));
			$config = $row ? json_decode($row['config'], true) : null;

			$this->config = is_array($config) ? $config : $this->module->getConfig();
		}

		return $this->config;
	}

	/**
	 * Maps the current user can open, without their layouts, sorted: public maps first, then by name.
	 */
	public function listMaps(): array {
		$config = $this->config();
		$can_edit_shared = $this->canEditShared();
		$default = (string) ($config['default_map'] ?? '');
		$userid = (string) CWebUser::$data['userid'];
		$maps = [];

		foreach ($config['maps'] ?? [] as $id => $map) {
			if (is_array($map) && preg_match(self::ID_PATTERN, (string) $id)) {
				$maps[$id] = self::meta((string) $id, $map, self::SCOPE_SHARED, $can_edit_shared, $id === $default);
			}
		}

		foreach ($this->personalRows() as $id => $map) {
			$maps[$id] = ['owner' => $userid] + self::meta($id, $map, self::SCOPE_PERSONAL, true, false);
			$maps[$id]['owner'] = $userid;
		}

		uasort($maps, static fn(array $a, array $b): int =>
			[$a['scope'] !== self::SCOPE_SHARED, mb_strtolower($a['name'])]
				<=> [$b['scope'] !== self::SCOPE_SHARED, mb_strtolower($b['name'])]
		);

		return $maps;
	}

	/**
	 * Full map (filter and layout) or null when it does not exist or is not visible to the user.
	 */
	public function get(string $id): ?array {
		if (!preg_match(self::ID_PATTERN, $id)) {
			return null;
		}

		if ($id[0] === 's') {
			$config = $this->config();
			$map = $config['maps'][$id] ?? null;

			if (!is_array($map)) {
				return null;
			}

			return self::meta($id, $map, self::SCOPE_SHARED, $this->canEditShared(),
				$id === (string) ($config['default_map'] ?? '')
			) + [
				'filter' => self::sanitizeFilter($map['filter'] ?? []),
				'layout' => self::unpackLayout($map)
			];
		}

		$map = $this->personalRows($id)[$id] ?? null;

		if ($map === null) {
			return null;
		}

		return self::meta($id, $map, self::SCOPE_PERSONAL, true, false) + [
			'filter' => self::sanitizeFilter($map['filter'] ?? []),
			'layout' => self::unpackLayout($map)
		];
	}

	public function defaultMapId(): string {
		$id = (string) ($this->config()['default_map'] ?? '');

		return preg_match(self::ID_PATTERN, $id) ? $id : '';
	}

	private static function meta(string $id, array $map, string $scope, bool $can_edit, bool $default): array {
		return [
			'id' => $id,
			'name' => (string) ($map['name'] ?? $id),
			'scope' => $scope,
			'owner' => (string) ($map['owner'] ?? ''),
			'owner_name' => (string) ($map['owner_name'] ?? ''),
			'updated' => (int) ($map['updated'] ?? 0),
			'updated_by' => (string) ($map['updated_by'] ?? ''),
			'rev' => (int) ($map['rev'] ?? 1),
			'sysmapid' => (string) ($map['sysmapid'] ?? ''),
			'can_edit' => $can_edit,
			'default' => $default
		];
	}

	/**
	 * Personal maps of the current user: id => stored map.
	 */
	private function personalRows(?string $only = null): array {
		if (!self::canHavePersonal()) {
			return [];
		}

		$sql = 'SELECT idx2,value_str FROM profiles'.
			' WHERE userid='.zbx_dbstr(CWebUser::$data['userid']).
				' AND idx='.zbx_dbstr(self::PROFILE_MAPS);

		if ($only !== null) {
			$sql .= ' AND idx2='.zbx_dbstr(self::profileIdx2($only));
		}

		$rows = [];
		$result = DBselect($sql);

		while ($row = DBfetch($result)) {
			$map = json_decode($row['value_str'], true);

			if (is_array($map)) {
				$rows[sprintf('p%012x', (int) $row['idx2'])] = $map;
			}
		}

		return $rows;
	}

	private static function profileIdx2(string $id): int {
		return (int) hexdec(substr($id, 1));
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Writing
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Creates a map (no "id") or replaces the selection and drawing of an existing one.
	 *
	 * @param array $data  id?, name (new map), scope (new map), rev (existing map: the revision that was opened),
	 *                     filter, layout
	 *
	 * @throws RuntimeException
	 *
	 * @return array  map metadata (see meta())
	 */
	public function save(array $data): array {
		$now = time();
		$user = self::userName();
		$filter = self::sanitizeFilter($data['filter'] ?? []);
		$layout = self::sanitizeLayout($data['layout'] ?? []);
		$id = (string) ($data['id'] ?? '');

		if ($id === '') {
			$name = self::cleanName($data['name'] ?? '');
			$scope = ($data['scope'] ?? '') === self::SCOPE_SHARED ? self::SCOPE_SHARED : self::SCOPE_PERSONAL;

			if ($scope === self::SCOPE_SHARED && !$this->canEditShared()) {
				throw new RuntimeException(I18n::t('You are not allowed to change public maps.'));
			}

			if ($scope === self::SCOPE_PERSONAL && !self::canHavePersonal()) {
				throw new RuntimeException(I18n::t('Guests cannot save maps.'));
			}

			$this->assertFreeName($name, $scope);

			$id = ($scope === self::SCOPE_SHARED ? 's' : 'p').bin2hex(random_bytes(6));
			$map = [
				'name' => $name,
				'owner' => (string) CWebUser::$data['userid'],
				'owner_name' => $user,
				'created' => $now,
				'updated' => $now,
				'updated_by' => $user,
				'rev' => 1,
				'filter' => $filter
			] + self::packLayout($layout);

			$this->write($id, static function(?array $current) use ($map): array {
				if ($current !== null) {
					throw new RuntimeException('Map ID collision, try again.');
				}

				return $map;
			}, true);

			return $this->get($id);
		}

		$this->assertCanEdit($id);
		$expected = array_key_exists('rev', $data) ? (int) $data['rev'] : null;

		$this->write($id, static function(?array $current) use ($filter, $layout, $now, $user, $expected): array {
			if ($current === null) {
				throw new RuntimeException(I18n::t('The map no longer exists.'));
			}

			$rev = (int) ($current['rev'] ?? 1);

			if ($expected !== null && $expected !== $rev) {
				throw new RuntimeException(I18n::t('This map was changed by %1$s at %2$s. Reload the page to see the new version before saving.',
					(string) ($current['updated_by'] ?? '?'), date('H:i', (int) ($current['updated'] ?? 0))
				));
			}

			unset($current['layout'], $current['lz']);

			return [
				'updated' => $now,
				'updated_by' => $user,
				'rev' => $rev + 1,
				'filter' => $filter
			] + self::packLayout($layout) + $current;
		});

		return $this->get($id);
	}

	public function rename(string $id, string $name): array {
		$this->assertCanEdit($id);
		$name = self::cleanName($name);
		$this->assertFreeName($name, $id[0] === 's' ? self::SCOPE_SHARED : self::SCOPE_PERSONAL, $id);
		$user = self::userName();

		$this->write($id, static function(?array $current) use ($name, $user): array {
			if ($current === null) {
				throw new RuntimeException(I18n::t('The map no longer exists.'));
			}

			return ['name' => $name, 'updated' => time(), 'updated_by' => $user,
				'rev' => (int) ($current['rev'] ?? 1) + 1
			] + $current;
		});

		return $this->get($id);
	}

	public function delete(string $id): void {
		$this->assertCanEdit($id);

		if (CProfile::get(self::PROFILE_CURRENT, '') === $id) {
			CProfile::update(self::PROFILE_CURRENT, '', PROFILE_TYPE_STR);
		}

		if ($id[0] === 'p') {
			CProfile::delete(self::PROFILE_MAPS, self::profileIdx2($id));
			CProfile::flush();

			return;
		}

		$this->updateConfig(static function(array $config) use ($id): array {
			unset($config['maps'][$id]);

			if (($config['default_map'] ?? '') === $id) {
				unset($config['default_map']);
			}

			return $config;
		});
	}

	/**
	 * Native Zabbix map that mirrors this map ("Publish").
	 */
	public function setSysmapId(string $id, string $sysmapid): void {
		$this->write($id, static function(?array $current) use ($sysmapid): array {
			if ($current === null) {
				throw new RuntimeException(I18n::t('The map no longer exists.'));
			}

			return ['sysmapid' => $sysmapid] + $current;
		});
	}

	/**
	 * @param string $id  public map opened for users that have not chosen a map yet, '' for none.
	 */
	public function setDefault(string $id): void {
		if (!$this->canEditShared()) {
			throw new RuntimeException(I18n::t('You are not allowed to change public maps.'));
		}

		$this->updateConfig(static function(array $config) use ($id): array {
			if ($id === '') {
				unset($config['default_map']);
			}
			elseif (!array_key_exists($id, $config['maps'] ?? [])) {
				throw new RuntimeException(I18n::t('Only public maps can be the initial map.'));
			}
			else {
				$config['default_map'] = $id;
			}

			return $config;
		});
	}

	/**
	 * Module settings (Super admin).
	 */
	public function saveSettings(array $settings): void {
		if (CWebUser::getType() != USER_TYPE_SUPER_ADMIN) {
			throw new RuntimeException(I18n::t('Only Super admins can change these settings.'));
		}

		$this->updateConfig(static function(array $config) use ($settings): array {
			$config['editors'] = self::editors(['editors' => $settings['editors'] ?? []]);
			$config['max_hosts'] = max(10, min(100000, (int) ($settings['max_hosts'] ?? 2000)));

			if (array_key_exists('capacity', $settings)) {
				$config['capacity'] = CapacityBuilder::planning($settings['capacity']);
			}

			$default = (string) ($settings['default_map'] ?? '');

			if ($default !== '' && array_key_exists($default, $config['maps'] ?? [])) {
				$config['default_map'] = $default;
			}
			else {
				unset($config['default_map']);
			}

			return $config;
		});
	}

	/**
	 * Storage used by public maps: ['used' => bytes, 'limit' => bytes].
	 */
	public function usage(): array {
		$row = DBfetch(DBselect('SELECT config FROM module WHERE moduleid='.zbx_dbstr($this->moduleId())));

		return ['used' => $row ? strlen($row['config']) : 0, 'limit' => self::maxConfigBytes()];
	}

	/**
	 * Two public maps (or two personal maps of the same user) cannot have the same name.
	 */
	private function assertFreeName(string $name, string $scope, string $except = ''): void {
		foreach ($this->listMaps() as $map) {
			if ($map['scope'] === $scope && $map['id'] !== $except
					&& mb_strtolower($map['name']) === mb_strtolower($name)) {
				throw new RuntimeException(I18n::t('There is already a map named "%1$s".', $name));
			}
		}
	}

	private function assertCanEdit(string $id): void {
		if (!preg_match(self::ID_PATTERN, $id)) {
			throw new RuntimeException(I18n::t('The map no longer exists.'));
		}

		if ($id[0] === 's' && !$this->canEditShared()) {
			throw new RuntimeException(I18n::t('You are not allowed to change public maps.'));
		}

		if ($id[0] === 'p' && !self::canHavePersonal()) {
			throw new RuntimeException(I18n::t('Guests cannot save maps.'));
		}
	}

	/**
	 * Read-modify-write of one map.
	 *
	 * @param callable $change  function(?array $current): array  the new stored map
	 */
	private function write(string $id, callable $change, bool $create = false): void {
		if ($id[0] === 'p') {
			$idx2 = self::profileIdx2($id);
			$current = $create ? null : ($this->personalRows($id)[$id] ?? null);
			$json = json_encode($change($current), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			if (mb_strlen($json) > self::MAX_PROFILE_CHARS) {
				throw new RuntimeException(I18n::t('The map is too large to be saved (%1$s KB, limit %2$s KB). Remove drawings or split the map.',
					round(strlen($json) / 1024), round(self::MAX_PROFILE_CHARS / 1024)
				));
			}

			CProfile::update(self::PROFILE_MAPS, $json, PROFILE_TYPE_STR, $idx2);

			// Written now: the answer is sent before the end of the request would save it.
			if (!CProfile::flush()) {
				throw new RuntimeException(I18n::t('Cannot save the map.'));
			}

			return;
		}

		$this->updateConfig(static function(array $config) use ($id, $change): array {
			$current = $config['maps'][$id] ?? null;
			$config['maps'][$id] = $change(is_array($current) ? $current : null);

			return $config;
		});
	}

	/**
	 * Changes the module configuration with the row locked, so two editors saving at the same time do not
	 * overwrite each other.
	 */
	private function updateConfig(callable $change): void {
		DBstart();

		try {
			$row = DBfetch(DBselect('SELECT config FROM module WHERE moduleid='.zbx_dbstr($this->moduleId()).
				' FOR UPDATE'
			));

			if (!$row) {
				throw new RuntimeException('The module is not registered.');
			}

			$config = json_decode($row['config'], true);
			$config = $change(is_array($config) ? $config : []);

			// Old per-selection layouts (1.1) are replaced by saved maps.
			unset($config['layouts']);

			$json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			if ($json === false) {
				throw new RuntimeException('Cannot encode the module configuration.');
			}

			if (strlen($json) > self::maxConfigBytes()) {
				throw new RuntimeException(I18n::t('There is no room for this map: public maps use %1$s KB of %2$s KB. Delete old maps or remove drawings.',
					round(strlen($row['config']) / 1024), round(self::maxConfigBytes() / 1024)
				));
			}

			DB::update('module', [
				'values' => ['config' => $json],
				'where' => ['moduleid' => $this->moduleId()]
			]);

			DBend(true);
			$this->config = $config;
		}
		catch (Throwable $e) {
			DBend(false);
			$this->config = null;

			throw $e;
		}
	}

	private function moduleId(): string {
		if (method_exists($this->module, 'getModuleId')) {
			return (string) $this->module->getModuleId();
		}

		$row = DBfetch(DBselect('SELECT moduleid FROM module WHERE id='.zbx_dbstr($this->module->getId())));

		return $row ? (string) $row['moduleid'] : '0';
	}

	/**
	 * MySQL TEXT holds 65535 bytes; PostgreSQL has no practical limit.
	 */
	private static function maxConfigBytes(): int {
		global $DB;

		return ($DB['TYPE'] ?? '') === ZBX_DB_POSTGRESQL ? 16 * 1024 * 1024 : 65000;
	}

	private static function userName(): string {
		$data = CWebUser::$data;
		$full = trim(($data['name'] ?? '').' '.($data['surname'] ?? ''));

		return $full !== '' ? $full : (string) ($data['username'] ?? $data['alias'] ?? '');
	}

	private static function cleanName($name): string {
		$name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $name) ?? '');

		if ($name === '') {
			throw new RuntimeException(I18n::t('Enter a name for the map.'));
		}

		return mb_substr($name, 0, self::MAX_NAME);
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Validation and encoding
	 * ---------------------------------------------------------------------------------------------
	 */

	public static function sanitizeFilter($filter): array {
		if (is_string($filter)) {
			$filter = json_decode($filter, true);
		}

		$filter = is_array($filter) ? $filter : [];
		$ids = static function($list): array {
			$result = [];

			foreach (is_array($list) ? $list : [] as $id) {
				$id = (string) $id;

				if (ctype_digit($id) && strlen($id) <= 20) {
					$result[$id] = $id;
				}
			}

			return array_values(array_slice($result, 0, self::MAX_IDS));
		};

		$sources = is_array($filter['sources'] ?? null) ? $filter['sources'] : ['lldp', 'cdp', 'tags'];

		return [
			'groupids' => $ids($filter['groupids'] ?? []),
			'hostids' => $ids($filter['hostids'] ?? []),
			'mapids' => $ids($filter['mapids'] ?? []),
			'sources' => array_values(array_intersect(TopologyBuilder::SOURCES, array_map('strval', $sources))),
			'traffic' => (int) (bool) ($filter['traffic'] ?? 1),
			'isolated' => (int) (bool) ($filter['isolated'] ?? 1)
		];
	}

	/**
	 * @param array|string $layout  ['nodes' => {id: [x, y]}, 'edges' => {id: {s, d, w, t}}, 'shapes' => [...]]
	 */
	public static function sanitizeLayout($layout): array {
		if (is_string($layout)) {
			$layout = json_decode($layout, true);
		}

		$layout = is_array($layout) ? $layout : [];
		$result = ['nodes' => [], 'edges' => [], 'shapes' => []];

		foreach (is_array($layout['nodes'] ?? null) ? $layout['nodes'] : [] as $id => $xy) {
			if (count($result['nodes']) >= self::MAX_NODES) {
				break;
			}

			if (is_string($id) && preg_match('/^[hx][0-9a-f]{1,32}$/', $id) && is_array($xy) && count($xy) == 2
					&& self::isCoord($xy[0] ?? null) && self::isCoord($xy[1] ?? null)) {
				$result['nodes'][$id] = [round((float) $xy[0], 1), round((float) $xy[1], 1)];
			}
		}

		foreach (is_array($layout['edges'] ?? null) ? $layout['edges'] : [] as $id => $bend) {
			if (count($result['edges']) >= self::MAX_EDGES) {
				break;
			}

			if (!is_string($id) || !preg_match('/^e[0-9a-f]{16}$/', $id) || !is_array($bend)
					|| !in_array($bend['s'] ?? '', self::EDGE_STYLES, true)) {
				continue;
			}

			$clean = ['s' => $bend['s']];

			if ($bend['s'] === 'curve') {
				if (!self::isCoord($bend['d'] ?? null) || !is_numeric($bend['w'] ?? null)) {
					continue;
				}

				$clean['d'] = round((float) $bend['d'], 1);
				$clean['w'] = round(max(0.0, min(1.0, (float) $bend['w'])), 3);
			}
			elseif ($bend['s'] === 'taxi') {
				$clean['t'] = round(max(0.0, min(100.0, (float) ($bend['t'] ?? 50))), 1);
				$clean['dir'] = in_array($bend['dir'] ?? '', ['auto', 'vertical', 'horizontal'], true)
					? $bend['dir']
					: 'auto';
			}

			$result['edges'][$id] = $clean;
		}

		$seen = [];

		foreach (is_array($layout['shapes'] ?? null) ? $layout['shapes'] : [] as $shape) {
			if (count($result['shapes']) >= self::MAX_SHAPES) {
				break;
			}

			if (!is_array($shape) || !in_array($shape['t'] ?? '', self::SHAPE_TYPES, true)
					|| !preg_match('/^d[0-9a-f]{6,16}$/', (string) ($shape['id'] ?? ''))
					|| array_key_exists($shape['id'], $seen)
					|| !self::isCoord($shape['x'] ?? null) || !self::isCoord($shape['y'] ?? null)) {
				continue;
			}

			$seen[$shape['id']] = true;

			$clean = [
				'id' => $shape['id'],
				't' => $shape['t'],
				'x' => round((float) $shape['x'], 1),
				'y' => round((float) $shape['y'], 1),
				'text' => mb_substr(trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', (string) ($shape['text'] ?? '')) ?? ''),
					0, self::MAX_TEXT
				),
				// "auto" = text color of the viewer's theme.
				'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($shape['color'] ?? ''))
					? strtolower($shape['color'])
					: (($shape['color'] ?? '') === 'auto' ? 'auto' : '#4f8fd6'),
				'fs' => max(8, min(48, (int) ($shape['fs'] ?? 14))),
				'ls' => in_array($shape['ls'] ?? '', self::LINE_STYLES, true) ? $shape['ls'] : 'solid'
			];

			if ($shape['t'] === 'line') {
				if (!self::isCoord($shape['x2'] ?? null) || !self::isCoord($shape['y2'] ?? null)) {
					continue;
				}

				$clean['x2'] = round((float) $shape['x2'], 1);
				$clean['y2'] = round((float) $shape['y2'], 1);
				$clean['lw'] = max(1, min(8, (int) ($shape['lw'] ?? 2)));
				$clean['arrow'] = max(0, min(2, (int) ($shape['arrow'] ?? 0)));
			}
			else {
				$clean['w'] = round(max(10.0, min(20000.0, (float) ($shape['w'] ?? 200))), 1);
				$clean['h'] = round(max(10.0, min(20000.0, (float) ($shape['h'] ?? 120))), 1);
				$clean['fill'] = (int) (bool) ($shape['fill'] ?? ($shape['t'] !== 'text'));
			}

			$result['shapes'][] = $clean;
		}

		return $result;
	}

	private static function isCoord($value): bool {
		return is_numeric($value) && is_finite((float) $value) && abs((float) $value) <= self::MAX_COORD;
	}

	private static function packLayout(array $layout): array {
		$json = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if (function_exists('gzdeflate') && strlen($json) > 512) {
			$packed = gzdeflate($json, 6);

			if ($packed !== false) {
				return ['lz' => base64_encode($packed)];
			}
		}

		return ['layout' => $layout];
	}

	private static function unpackLayout(array $map): array {
		if (isset($map['lz']) && is_string($map['lz']) && function_exists('gzinflate')) {
			$json = @gzinflate((string) base64_decode($map['lz'], true));

			return self::sanitizeLayout($json !== false ? $json : []);
		}

		return self::sanitizeLayout($map['layout'] ?? []);
	}
}
