<?php declare(strict_types = 1);

/*
** Zabbix
** Copyright (C) 2001-2020 Zabbix SIA
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


class CControllerHostView extends CControllerHost {

	protected function init(): void {
		$this->disableSIDValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'sort' =>					'in name,status',
			'sortorder' =>				'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			'page' =>					'ge 1',
			'filter_set' =>				'in 1',
			'filter_rst' =>				'in 1',
			'name' =>					'string',
			'groupids' =>				'array_id',
			'ip' =>						'string',
			'dns' =>					'string',
			'port' =>					'string',
			'status' =>					'in -1,'.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED,
			'evaltype' =>				'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'tags' =>					'array',
			'severities' =>				'array',
			'show_suppressed' =>		'in '.ZBX_PROBLEM_SUPPRESSED_FALSE.','.ZBX_PROBLEM_SUPPRESSED_TRUE,
			'maintenance_status' =>		'in '.HOST_MAINTENANCE_STATUS_OFF.','.HOST_MAINTENANCE_STATUS_ON
		];

		$ret = $this->validateInput($fields);

		// Validate tags filter.
		if ($ret && $this->hasInput('tags')) {
			foreach ($this->getInput('tags') as $filter_tag) {
				if (count($filter_tag) != 3
						|| !array_key_exists('tag', $filter_tag) || !is_string($filter_tag['tag'])
						|| !array_key_exists('value', $filter_tag) || !is_string($filter_tag['value'])
						|| !array_key_exists('operator', $filter_tag) || !is_string($filter_tag['operator'])) {
					$ret = false;
					break;
				}
			}
		}

		// Validate severity checkbox filter.
		if ($ret && $this->hasInput('severities')) {
			foreach ($this->getInput('severities') as $severity) {
				if (!in_array($severity, range(TRIGGER_SEVERITY_NOT_CLASSIFIED, TRIGGER_SEVERITY_COUNT - 1))) {
					$ret = false;
					break;
				}
			}
		}

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
	}

	protected function doAction(): void {
		$data['filter_defaults'] = [
			'name' => '',
			'groupids' => [],
			'ip' => '',
			'dns' => '',
			'port' => '',
			'status' => '',
			'evaltype' => TAG_EVAL_TYPE_AND_OR,
			'tags' => [],
			'severities' => [],
			'show_suppressed' => ZBX_PROBLEM_SUPPRESSED_FALSE,
			'maintenance_status' => HOST_MAINTENANCE_STATUS_ON,
			'from' => 'now-1d',
			'to' => 'now',
			'page' => null,
			'sort' => 'name',
			'sortorder' => ZBX_SORT_UP
		];
		$profile = (new CTabFilterProfile('web.monitoringhosts'))->read();
		$profile->setFilterDefaults($data['filter_defaults']);
		$filter = $profile->getTabFilter($profile->selected);

		$refresh_curl = (new CUrl('zabbix.php'))
			->setArgument('action', 'host.view.refresh')
			->setArgument('filter_name', $filter['name'])
			->setArgument('filter_groupids', $filter['groupids'])
			->setArgument('filter_ip', $filter['ip'])
			->setArgument('filter_dns', $filter['dns'])
			->setArgument('filter_status', $filter['status'])
			->setArgument('filter_evaltype', $filter['evaltype'])
			->setArgument('filter_tags', $filter['tags'])
			->setArgument('filter_severities', $filter['severities'])
			->setArgument('filter_show_suppressed', $filter['show_suppressed'])
			->setArgument('filter_maintenance_status', $filter['maintenance_status'])
			->setArgument('sort', $filter['sort'])
			->setArgument('sortorder', $filter['sortorder'])
			->setArgument('page', $filter['page']);

		$prepared_data = $this->prepareData($filter, $filter['sort'], $filter['sortorder']);

		$data = [
			'filter' => $filter,
			// 'sort' => $sort,
			// 'sortorder' => $sortorder,
			'refresh_url' => $refresh_curl->getUrl(),
			'refresh_interval' => CWebUser::getRefresh() * 1000,

			'filter_template' => 'monitoring.host.filter',
			'filter_defaults' => $data['filter_defaults'],
			'from' => $filter['from'],
			'to' => $filter['to'],
			'filter_tabs' => $profile->getTabsWithDefaults(),
			'tab_selected' => $profile->selected,
			'tab_expanded' => $profile->expanded
		] + $prepared_data;

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Hosts'));
		$this->setResponse($response);
	}
}
