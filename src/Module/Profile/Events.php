<?php

/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Module\Profile;

use Friendica\App;
use Friendica\Content\Nav;
use Friendica\Content\Widget;
use Friendica\Core\Renderer;
use Friendica\Core\Session;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model;
use Friendica\Model\Event;
use Friendica\Model\Item;
use Friendica\Model\Profile as ProfileModel;
use Friendica\Model\User;
use Friendica\Module\BaseProfile;
use Friendica\Module\Response;
use Friendica\Network\HTTPException;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Temporal;


class Events extends BaseProfile
{
	protected function content(array $request = []): string
	{
		$this->a = DI::app();

		$this->profile = ProfileModel::load($this->a, $this->parameters['nickname']);
		if (empty($this->profile)) {
			throw new HTTPException\NotFoundException(DI::l10n()->t('User not found.'));
		}

		if (!$this->profile['net-publish']) {
			DI::page()['htmlhead'] .= '<meta content="noindex, noarchive" name="robots" />' . "\n";
		}

		$is_owner = local_user() == $this->profile['uid'];

		$o = self::getTabsHTML($this->a, 'events', $is_owner, $this->profile['nickname'], $this->profile['hide-friends']);

		DI::page()->registerStylesheet('view/asset/fullcalendar/dist/fullcalendar.min.css');
		DI::page()->registerStylesheet('view/asset/fullcalendar/dist/fullcalendar.print.min.css', 'print');
		DI::page()->registerFooterScript('view/asset/moment/min/moment-with-locales.min.js');
		DI::page()->registerFooterScript('view/asset/fullcalendar/dist/fullcalendar.min.js');

		$htpl = Renderer::getMarkupTemplate('event_head.tpl');

		$i18n = Event::getStrings();
		$nickname = $this->parameters['nickname'];
		$o .= Renderer::replaceMacros($htpl, [
			'$module_url' => "profile/${nickname}/events",
			'$modparams' => 2,
			'$i18n' => $i18n,
		]);
		$mode = 'view';


		if ($mode == 'view') {
			return $o . $this->view_mode();
		}


		return $o . "<h1>Foo</h1>";
	}

	private function view_mode()
	{
		$this->set_calendar_dates();

		$this->fetch_events();


		$events = $this->fetch_events();
		// Get rid of dashes in key names, Smarty3 can't handle them
		print_r($events);


		// links: array('href', 'text', 'extra css classes', 'title')
		if (!empty($_GET['id'])) {
			$tpl = Renderer::getMarkupTemplate("event.tpl");
		} else {
			$tpl = Renderer::getMarkupTemplate("events_js.tpl");
		}
		$tabs= [];
		$o = Renderer::replaceMacros($tpl, [
			'$tabs' => $tabs,
			'$title' => DI::l10n()->t('Events'),
			'$view' => DI::l10n()->t('View'),
			'$previous' => [DI::baseUrl() . "/events/" . $this->prevyear . "/" . $this->prevmonth, DI::l10n()->t('Previous'), '', ''],
			'$next' => [DI::baseUrl() . "/events/$this->nextyear/$this->nextmonth", DI::l10n()->t('Next'), '', ''],
			'$calendar' => Temporal::getCalendarTable($this->currentYear, $this->currentMonth, $links, ' eventcal'),
			'$events' => $events,
			"today" => DI::l10n()->t("today"),
			"month" => DI::l10n()->t("month"),
			"week" => DI::l10n()->t("week"),
			"day" => DI::l10n()->t("day"),
			"list" => DI::l10n()->t("list"),
		]);

		if (!empty($_GET['id'])) {
			System::httpExit($o);
		}

		return $o;
	}

	private function set_calendar_dates()
	{
		// The view mode part is similiar to /mod/events.php
		$this->currentYear = intval(DateTimeFormat::localNow('Y'));
		$this->currentMonth = intval(DateTimeFormat::localNow('m'));
		$this->ignored = (!empty($_REQUEST['ignored']) ? intval($_REQUEST['ignored']) : 0);

		// Put some limits on dates. The PHP date functions don't seem to do so well before 1900.
		// An upper limit was chosen to keep search engines from exploring links millions of years in the future.

		if ($this->currentYear < 1901) {
			$this->currentYear = 1900;
		}

		if ($this->currentYear > 2099) {
			$this->currentYear = 2100;
		}

		$nextyear = $this->currentYear;
		$nextmonth = $this->currentMonth + 1;
		if ($nextmonth > 12) {
			$nextmonth = 1;
			$nextyear++;
		}

		$this->prevyear = $this->currentYear;
		if ($this->currentMonth > 1) {
			$this->prevmonth = $this->currentMonth - 1;
		} else {
			$this->prevmonth = 12;
			$this->prevyear--;
		}

		$this->dim = Temporal::getDaysInMonth($this->currentYear, $this->currentMonth);
		$this->start = sprintf('%d-%d-%d %d:%d:%d', $this->currentYear, $this->currentMonth, 1, 0, 0, 0);
		$this->finish = sprintf('%d-%d-%d %d:%d:%d', $this->currentYear, $this->currentMonth, $this->dim, 23, 59, 59);

		if (!empty(DI::args()->getArgv()[2]) && (DI::args()->getArgv()[2] === 'json')) {
			if (!empty($_GET['start'])) {
				$this->start = $_GET['start'];
			}

			if (!empty($_GET['end'])) {
				$this->finish = $_GET['end'];
			}
		}

		$this->start = DateTimeFormat::utc($this->start);
		$this->finish = DateTimeFormat::utc($this->finish);
	}

	private function fetch_events(){
		// put the event parametes in an array so we can better transmit them
		$event_params = [
			'event_id'      => intval($_GET['id'] ?? 0),
			'start'         => $this->start,
			'finish'        => $this->finish,
			'ignore'        => $this->ignored,
		];

		$owner_uid = $this->profile['uid'];
		$sql_perms = Item::getPermissionsSQLByUserId($owner_uid);
		$sql_extra = " AND `event`.`cid` = 0 " . $sql_perms;
		$owner = User::getOwnerDataByNick(DI::args()->getArgv()[1]);

		$tabs = BaseProfile::getTabsHTML($this->a, 'cal', false, $owner['nickname'], $owner['hide-friends']);

		// get events by id or by date
		if ($event_params['event_id']) {
			$r = Event::getListById($owner_uid, $event_params['event_id'], $sql_extra);
		} else {
			$r = Event::getListByDate($owner_uid, $event_params, $sql_extra);
		}

		$links = [];

		if (DBA::isResult($r)) {
			$r = Event::sortByDate($r);
			foreach ($r as $rr) {
				$j = DateTimeFormat::local($rr['start'], 'j');
				if (empty($links[$j])) {
					$links[$j] = DI::baseUrl() . '/' . DI::args()->getCommand() . '#link-' . $j;
				}
			}
		}

		// transform the event in a usable array
		$events = Event::prepareListForTemplate($r);

		if (!empty(DI::args()->getArgv()[2]) && (DI::args()->getArgv()[2] === 'json')) {
			System::jsonExit($events);
		}
		foreach ($events as $key => $event) {
			$event_item = [];
			foreach ($event['item'] as $k => $v) {
				$k = str_replace('-', '_', $k);
				$event_item[$k] = $v;
			}
			$events[$key]['item'] = $event_item;
		}
		return $events;
	}
}
