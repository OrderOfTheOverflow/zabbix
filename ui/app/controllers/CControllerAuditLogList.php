<?php declare(strict_types = 1);
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


class CControllerAuditLogList extends CController {

	protected function checkInput(): bool {
		$fields = [
			'page' =>					'ge 1',
			'filter_action' =>			'in -1,'.implode(',', array_keys(self::getActionsList())),
			'filter_resourcetype' =>	'in -1,'.implode(',', array_keys(self::getResourcesList())),
			'filter_rst' =>				'in 1',
			'filter_set' =>				'in 1',
			'filter_userids' =>			'array_db users.userid',
			'filter_resourceid' =>		'string',
			'filter_recordsetid' =>		'string',
			'from' =>					'range_time',
			'to' =>						'range_time'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_REPORTS_AUDIT);
	}

	protected function doAction(): void {
		if ($this->getInput('filter_set', 0)) {
			$this->updateProfiles();
		}
		elseif ($this->getInput('filter_rst', 0)) {
			$this->deleteProfiles();
		}

		$timeselector_options = [
			'profileIdx' => 'web.auditlog.filter',
			'profileIdx2' => 0,
			'from' => null,
			'to' => null
		];
		$this->getInputs($timeselector_options, ['from', 'to']);
		updateTimeSelectorPeriod($timeselector_options);

		$data = [
			'page' => $this->getInput('page', 1),
			'userids' => CProfile::getArray('web.auditlog.filter.userids', []),
			'resourcetype' => CProfile::get('web.auditlog.filter.resourcetype', -1),
			'auditlog_action' => CProfile::get('web.auditlog.filter.action', -1),
			'resourceid' => CProfile::get('web.auditlog.filter.resourceid', ''),
			'recordsetid' => CProfile::get('web.auditlog.filter.recordsetid', ''),
			'action' => $this->getAction(),
			'actions' => self::getActionsList(),
			'resources' => self::getResourcesList(),
			'timeline' => getTimeSelectorPeriod($timeselector_options),
			'auditlogs' => [],
			'active_tab' => CProfile::get('web.auditlog.filter.active', 1)
		];
		$users = [];
		$usernames = [];
		$filter = [];

		if (array_key_exists((int) $data['auditlog_action'], $data['actions'])) {
			$filter['action'] = $data['auditlog_action'];
		}

		if (array_key_exists((int) $data['resourcetype'], $data['resources'])) {
			$filter['resourcetype'] = $data['resourcetype'];
		}

		if ($data['resourceid'] !== '' && CNewValidator::is_id($data['resourceid'])) {
			$filter['resourceid'] = $data['resourceid'];
		}

		if ($data['recordsetid'] !== '' && CNewValidator::isCuid($data['recordsetid'])) {
			$filter['recordsetid'] = $data['recordsetid'];
		}

		$params = [
			'output' => ['auditid', 'userid', 'username', 'clock', 'action', 'resourcetype', 'ip', 'resourceid',
				'resourcename', 'details', 'recordsetid'
			],
			'filter' => $filter,
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT) + 1
		];

		if ($data['timeline']['from_ts'] !== null) {
			$params['time_from'] = $data['timeline']['from_ts'];
		}

		if ($data['timeline']['to_ts'] !== null) {
			$params['time_till'] = $data['timeline']['to_ts'];
		}

		if ($data['userids']) {
			$users = API::User()->get([
				'output' => ['userid', 'username', 'name', 'surname'],
				'userids' => $data['userids'],
				'preservekeys' => true
			]);

			$data['userids'] = $this->sanitizeUsersForMultiselect($users);

			if ($users) {
				$params['userids'] = array_column($users, 'userid');
				$data['auditlogs'] = API::AuditLog()->get($params);
			}

			$users = array_map(function(array $value): string {
				return $value['username'];
			}, $users);
		}
		else {
			$data['auditlogs'] = API::AuditLog()->get($params);
		}

		$data['paging'] = CPagerHelper::paginate($data['page'], $data['auditlogs'], ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', $this->getAction())
		);

		$data['auditlogs'] = $this->sanitizeDetails($data['auditlogs']);

		if (!$users && $data['auditlogs']) {
			$db_users = API::User()->get([
				'output' => ['username'],
				'userids' => array_unique(array_column($data['auditlogs'], 'userid')),
				'preservekeys' => true
			]);

			$users = [];
			foreach ($data['auditlogs'] as $auditlog) {
				if (!array_key_exists($auditlog['userid'], $db_users)) {
					$usernames[$auditlog['userid']] = $auditlog['username'];
					continue;
				}

				$users[$auditlog['userid']] = $db_users[$auditlog['userid']]['username'];
			}
		}

		$data['users'] = $users;
		$data['usernames'] = $usernames;

		natsort($data['actions']);
		natsort($data['resources']);

		$data['actions'] = [-1 => _('All')] + $data['actions'];
		$data['resources'] = [-1 => _('All')] + $data['resources'];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Audit log'));
		$this->setResponse($response);
	}

	protected function init(): void {
		$this->disableSIDValidation();
	}

	/**
	 * Return associated list of available actions and labels.
	 *
	 * @return array
	 */
	private static function getActionsList(): array {
		return [
			CAudit::ACTION_LOGIN => _('Login'),
			CAudit::ACTION_LOGOUT => _('Logout'),
			CAudit::ACTION_ADD => _('Add'),
			CAudit::ACTION_UPDATE => _('Update'),
			CAudit::ACTION_DELETE => _('Delete'),
			CAudit::ACTION_EXECUTE => _('Execute')
		];
	}

	/**
	 * Return associated list of available resources and labels.
	 *
	 * @return array
	 */
	private static function getResourcesList(): array {
		return [
			CAudit::RESOURCE_USER => _('User'),
			CAudit::RESOURCE_MEDIA_TYPE => _('Media type'),
			CAudit::RESOURCE_HOST => _('Host'),
			CAudit::RESOURCE_HOST_PROTOTYPE => _('Host prototype'),
			CAudit::RESOURCE_ACTION => _('Action'),
			CAudit::RESOURCE_GRAPH => _('Graph'),
			CAudit::RESOURCE_GRAPH_PROTOTYPE => _('Graph prototype'),
			CAudit::RESOURCE_USER_GROUP => _('User group'),
			CAudit::RESOURCE_TRIGGER => _('Trigger'),
			CAudit::RESOURCE_TRIGGER_PROTOTYPE => _('Trigger prototype'),
			CAudit::RESOURCE_HOST_GROUP => _('Host group'),
			CAudit::RESOURCE_ITEM => _('Item'),
			CAudit::RESOURCE_ITEM_PROTOTYPE => _('Item prototype'),
			CAudit::RESOURCE_IMAGE => _('Image'),
			CAudit::RESOURCE_VALUE_MAP => _('Value map'),
			CAudit::RESOURCE_IT_SERVICE => _('Service'),
			CAudit::RESOURCE_MAP => _('Map'),
			CAudit::RESOURCE_SCENARIO => _('Web scenario'),
			CAudit::RESOURCE_DISCOVERY_RULE => _('Discovery rule'),
			CAudit::RESOURCE_PROXY => _('Proxy'),
			CAudit::RESOURCE_REGEXP => _('Regular expression'),
			CAudit::RESOURCE_MAINTENANCE => _('Maintenance'),
			CAudit::RESOURCE_SCRIPT => _('Script'),
			CAudit::RESOURCE_MACRO => _('Macro'),
			CAudit::RESOURCE_TEMPLATE => _('Template'),
			CAudit::RESOURCE_ICON_MAP => _('Icon mapping'),
			CAudit::RESOURCE_CORRELATION => _('Event correlation'),
			CAudit::RESOURCE_DASHBOARD => _('Dashboard'),
			CAudit::RESOURCE_AUTOREGISTRATION  => _('Autoregistration'),
			CAudit::RESOURCE_MODULE => _('Module'),
			CAudit::RESOURCE_SETTINGS => _('Settings'),
			CAudit::RESOURCE_HOUSEKEEPING => _('Housekeeping'),
			CAudit::RESOURCE_AUTHENTICATION => _('Authentication'),
			CAudit::RESOURCE_TEMPLATE_DASHBOARD => _('Template dashboard'),
			CAudit::RESOURCE_AUTH_TOKEN => _('API token'),
			CAudit::RESOURCE_SCHEDULED_REPORT => _('Scheduled report')
		];
	}

	private function updateProfiles(): void {
		CProfile::updateArray('web.auditlog.filter.userids', $this->getInput('filter_userids', []), PROFILE_TYPE_ID);
		CProfile::update('web.auditlog.filter.action', $this->getInput('filter_action', -1), PROFILE_TYPE_INT);
		CProfile::update('web.auditlog.filter.resourcetype', $this->getInput('filter_resourcetype', -1),
			PROFILE_TYPE_INT
		);
		CProfile::update('web.auditlog.filter.resourceid', $this->getInput('filter_resourceid', ''), PROFILE_TYPE_STR);
		CProfile::update('web.auditlog.filter.recordsetid', $this->getInput('filter_recordsetid', ''),
			PROFILE_TYPE_STR
		);
	}

	private function deleteProfiles(): void {
		CProfile::deleteIdx('web.auditlog.filter.userids');
		CProfile::delete('web.auditlog.filter.action');
		CProfile::delete('web.auditlog.filter.resourcetype');
		CProfile::delete('web.auditlog.filter.resourceid');
		CProfile::delete('web.auditlog.filter.recordsetid');
	}

	private function sanitizeUsersForMultiselect(array $users): array {
		$users = array_map(function(array $value): array {
			return ['id' => $value['userid'], 'name' => getUserFullname($value)];
		}, $users);

		CArrayHelper::sort($users, ['name']);

		return $users;
	}

	private function sanitizeDetails(array $auditlogs): array {
		foreach ($auditlogs as &$auditlog) {
			$auditlog['short_details'] = '';
			$auditlog['show_more_button'] = '0';

			if (!in_array($auditlog['action'], [CAudit::ACTION_ADD, CAudit::ACTION_UPDATE, CAudit::ACTION_EXECUTE])) {
				continue;
			}

			$details = json_decode($auditlog['details'], true);

			if (!$details) {
				$auditlog['details'] = '';
				continue;
			}

			$details = $this->formatDetails($details, $auditlog['action']);

			$auditlog['details'] = implode("\n", $details);
			$auditlog['short_details'] = implode("\n", array_slice($details, 0, 2));

			if (count($details) > 2) {
				$auditlog['show_more_button'] = '1';
			}
		}
		unset($auditlog);

		return $auditlogs;
	}

	private function formatDetails(array $details, string $action): array {
		$new_details = [];
		foreach ($details as $key => $detail) {
			switch ($action) {
				case CAudit::ACTION_ADD:
				case CAudit::ACTION_UPDATE:
					$new_details[] = $this->makeDetailString($key, $detail);
					break;
				case CAudit::ACTION_EXECUTE:
					$new_details[] = sprintf('%s: %s', $key, $detail[1]);
					break;
			}
		}

		sort($new_details);

		return $new_details;
	}

	private function makeDetailString(string $key, array $detail) {
		switch ($detail[0]) {
			case CAudit::METHOD_ADD:
				return array_key_exists(1, $detail)
					? sprintf('%s: %s (%s)', $key, $detail[1], _('Added'))
					: sprintf('%s: %s', $key, _('Added'));
			case CAudit::METHOD_ATTACH:
				return array_key_exists(1, $detail)
					? sprintf('%s: %s (%s)', $key, $detail[1], _('Attached'))
					: sprintf('%s: %s', $key, _('Attached'));
			case CAudit::METHOD_DETACH:
				return array_key_exists(1, $detail)
					? sprintf('%s: %s (%s)', $key, $detail[1], _('Detached'))
					: sprintf('%s: %s', $key, _('Detached'));
			case CAudit::METHOD_DELETE:
				return array_key_exists(1, $detail)
					? sprintf('%s: %s (%s)', $key, $detail[1], _('Deleted'))
					: sprintf('%s: %s', $key, _('Deleted'));
			case CAudit::METHOD_UPDATE:
				return array_key_exists(1, $detail)
					? sprintf('%s: %s => %s', $key, $detail[2], $detail[1])
					: sprintf('%s: %s', $key, _('Updated'));
		}
	}
}
