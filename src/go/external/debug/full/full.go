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

package main

import (
	"zabbix.com/pkg/conf"
	"zabbix.com/pkg/plugin"
)

type Options struct {
	Interval int
}

// Plugin -
type Plugin struct {
	plugin.Base
	// counter int
	options Options
}

var impl Plugin

func (p *Plugin) Export(key string, params []string, ctx plugin.ContextProvider) (result interface{}, err error) {
	p.Debugf("export %s%v", key, params)

	return "debug full test response", nil
}

func (p *Plugin) Configure(global *plugin.GlobalOptions, private interface{}) {
	p.options.Interval = 10
	if err := conf.Unmarshal(private, &p.options); err != nil {
		p.Warningf("cannot unmarshal configuration options: %s", err)
	}
	p.Debugf("configure: interval=%d", p.options.Interval)
}

func (p *Plugin) Validate(private interface{}) (err error) {
	p.Debugf("executing Validate")
	return
}

func (p *Plugin) Start() {
	p.Debugf("executing Start")
}

func (p *Plugin) Stop() {
	p.Debugf("executing Stop")
}

func init() {
	impl.options.Interval = 1
	plugin.RegisterMetrics(&impl, "DebugExternalFull", "debug.external.full", "Returns test value.")
}
