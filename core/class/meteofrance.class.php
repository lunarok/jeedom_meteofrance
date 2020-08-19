<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class meteofrance extends eqLogic {

  public static function cron5() {
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      if ($meteofrance->getConfiguration('couvertPluie')) {
        $meteofrance->getRain();
      }
    }
  }

  public static function cronHourly() {
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getVigilance();
      if ($meteofrance->getConfiguration('bulletinCote')) {
        $meteofrance->getMarine();
        $meteofrance->getTide();
      }
    }
  }

  public function getDetails() {
    $url = 'http://ws.meteofrance.com/ws/getDetail/france/' . $meteofrance->getConfiguration('insee') . '0.json';
    $request_http = new com_http($url);
    $request_http->setNoSslCheck(true);
	  $request_http->setNoReportError(true);
	  $return = $request_http->exec(15,2);
    $this->setConfiguration('bulletinCote', $return['result']['ville']['bulletinCote']);
    $this->setConfiguration('couvertPluie', $return['result']['ville']['couvertPluie']);
    $this->setConfiguration('lat', $return['result']['ville']['latitude']);
    $this->setConfiguration('lon', $return['result']['ville']['longitude']);
  }

  public function getRain() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/nowcast/rain?lat=' . $meteofrance->getConfiguration('lat') . '&lon=' . $meteofrance->getConfiguration('lat') . '&token=' . config::byKey('token', 'meteofrance');
    $request_http = new com_http($url);
    $request_http->setNoSslCheck(true);
	  $request_http->setNoReportError(true);
	  $return = $request_http->exec(15,2);
    $i = 0;
    $cumul = 0;
    $next = 0;
    foreach ($return['forecast'] as $rain) {
      i++;
      $this->checkAndUpdateCmd('rain' . $i, $rain['rain']);
      $this->checkAndUpdateCmd('desc' . $i, $rain['desc']);
      if (($rain['rain'] > 1) && ($next == 0)) {
        $next = $i * 5;
        if ($i > 6) {
          $next += ($i - 6) * 5;
          //after 30 mn, steps are for 10mn
        }
      }
      $cumul += $rain['rain'];
    }
    $this->checkAndUpdateCmd('cumul', $cumul);
    $this->checkAndUpdateCmd('next', $next);
  }

  public function getMarine() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/nowcast/forecast/marine?lat=' . $meteofrance->getConfiguration('lat') . '&lon=' . $meteofrance->getConfiguration('lat') . '&token=' . config::byKey('token', 'meteofrance');
    $request_http = new com_http($url);
    $request_http->setNoSslCheck(true);
	  $request_http->setNoReportError(true);
	  $return = $request_http->exec(15,2);

  }

  public function getTide() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/nowcast/tide?id=' . $meteofrance->getConfiguration('insee') . '52&token=' . config::byKey('token', 'meteofrance');
    $request_http = new com_http($url);
    $request_http->setNoSslCheck(true);
	  $request_http->setNoReportError(true);
	  $return = $request_http->exec(15,2);

  }

  public function getVigilance() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/nowcast/warning/currentphenomenons?domain=' . $meteofrance->getConfiguration('departement') . '52&token=' . config::byKey('token', 'meteofrance');
    $request_http = new com_http($url);
    $request_http->setNoSslCheck(true);
	  $request_http->setNoReportError(true);
	  $return = $request_http->exec(15,2);

  }

  public function getAlerts() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/nowcast/report?domain=france&report_type=message&report_subtype=infospe&format=&token=' . config::byKey('token', 'meteofrance');
    $request_http = new com_http($url);
    $request_http->setNoSslCheck(true);
	  $request_http->setNoReportError(true);
	  $return = $request_http->exec(15,2);

  }

}

class meteofranceCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getLogicalId() == 'refresh') {
      $this->getEqLogic()->getInformations();
    }
  }
}

?>
