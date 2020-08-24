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

  public function preSave() {
    $this->getDetails($this->getInsee());
  }

  public function getInsee() {
    $geoloc = $this->getConfiguration('geoloc', 'none');
    if ($geoloc == 'none') {
      log::add(__CLASS__, 'error', 'Pollen geoloc non configuré.');
      return;
    }
    if ($geoloc == "jeedom") {
      $zip = config::byKey('info::postalCode');
    } else {
      $geotrav = eqLogic::byId($geoloc);
      if (is_object($geotrav) && $geotrav->getEqType_name() == 'geotrav') {
        $geotravCmd = geotravCmd::byEqLogicIdAndLogicalId($geoloc,'location:zip');
        //location:city
        if(is_object($geotravCmd))
          $zip = $geotravCmd->execCmd();
        else {
          log::add(__CLASS__, 'error', 'Pollen geotravCmd object not found');
          return;
        }
      }
      else {
        log::add(__CLASS__, 'error', 'Pollen geotrav object not found');
        return;
      }
    }
    $url = 'https://api-adresse.data.gouv.fr/search/?q=postcode=' . $zip . '&limit=1';
    $return = self::callURL($url);
    log::add(__CLASS__, 'debug', 'Insee ' . $return['features'][0]['properties']['citycode']);
    return $return['features'][0]['properties']['citycode'];
  }

  public function getDetails($_insee) {
    $url = 'http://ws.meteofrance.com/ws/getDetail/france/' . $_insee . '0.json';
    $return = self::callURL($url);
    $this->setConfiguration('bulletinCote', $return['result']['ville']['bulletinCote']);
    $this->setConfiguration('couvertPluie', $return['result']['ville']['couvertPluie']);
    $this->setConfiguration('lat', $return['result']['ville']['latitude']);
    $this->setConfiguration('lon', $return['result']['ville']['longitude']);
    $this->setConfiguration('numDept', $return['result']['ville']['numDept']);
    $this->setConfiguration('insee', $_insee);
  }

  public function getRain() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/nowcast/rain?lat=' . $meteofrance->getConfiguration('lat') . '&lon=' . $meteofrance->getConfiguration('lat');
    $return = self::callMeteoWS($url);
    $i = 0;
    $cumul = 0;
    $next = 0;
    foreach ($return['forecast'] as $rain) {
      $i++;
      $this->checkAndUpdateCmd('Rainrain' . $i, $rain['rain']);
      $this->checkAndUpdateCmd('Raindesc' . $i, $rain['desc']);
      if (($rain['rain'] > 1) && ($next == 0)) {
        $next = $i * 5;
        if ($i > 6) {
          $next += ($i - 6) * 5;
          //after 30 mn, steps are for 10mn
        }
      }
      $cumul += $rain['rain'];
    }
    $this->checkAndUpdateCmd('Raincumul', $cumul);
    $this->checkAndUpdateCmd('Rainnext', $next);
  }

  public function getMarine() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast/marine?lat=' . $meteofrance->getConfiguration('lat') . '&lon=' . $meteofrance->getConfiguration('lat');
    $return = self::callMeteoWS($url);
    foreach ($return['properties']['marine'] as $id => $marine) {
      $this->checkAndUpdateCmd('Marinewind_speed_kt' . $id, $marine['wind_speed_kt']);
      $this->checkAndUpdateCmd('Marinewind_direction' . $id, $marine['wind_direction']);
      $this->checkAndUpdateCmd('Marinebeaufort_scale' . $id, $marine['beaufort_scale']);
      $this->checkAndUpdateCmd('Marinewave_height' . $id, $marine['wave_height']);
      $this->checkAndUpdateCmd('Marinemax_wave_height' . $id, $marine['max_wave_height']);
      $this->checkAndUpdateCmd('Marinewind_waves_height' . $id, $marine['wind_waves_height']);
      $this->checkAndUpdateCmd('Marineprimary_swell_direction' . $id, $marine['primary_swell_direction']);
      $this->checkAndUpdateCmd('Marineprimary_swell_height' . $id, $marine['primary_swell_height']);
      $this->checkAndUpdateCmd('Marineprimary_swell_period' . $id, $marine['primary_swell_period']);
      $this->checkAndUpdateCmd('MarineT_sea' . $id, $marine['T_sea']);
      $this->checkAndUpdateCmd('Marinesea_condition' . $id, $marine['sea_condition']);
      $this->checkAndUpdateCmd('Marinesea_condition_description' . $id, $marine['sea_condition_description']);
    }
  }

  public function getTide() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/tide?id=' . $meteofrance->getConfiguration('insee') . '52&token=' . config::byKey('token', 'meteofrance');
    $return = self::callMeteoWS($url);
    $this->checkAndUpdateCmd('Tidehigh_tide0time', $return['properties']['tide']['high_tide'][0]['time']);
    $this->checkAndUpdateCmd('Tidehigh_tide0tidal_coefficient', $return['properties']['tide']['high_tide'][0]['tidal_coefficient']);
    $this->checkAndUpdateCmd('Tidehigh_tide0tidal_height', $return['properties']['tide']['high_tide'][0]['tidal_height']);
    $this->checkAndUpdateCmd('Tidehigh_tide1time', $return['properties']['tide']['high_tide'][1]['time']);
    $this->checkAndUpdateCmd('Tidehigh_tide1tidal_coefficient', $return['properties']['tide']['high_tide'][1]['tidal_coefficient']);
    $this->checkAndUpdateCmd('Tidehigh_tide1tidal_height', $return['properties']['tide']['high_tide'][1]['tidal_height']);
    $this->checkAndUpdateCmd('Tidelow_tide0time', $return['properties']['tide']['low_tide'][0]['time']);
    $this->checkAndUpdateCmd('Tidelow_tide0tidal_coefficient', $return['properties']['tide']['low_tide'][0]['tidal_coefficient']);
    $this->checkAndUpdateCmd('Tidelow_tide0tidal_height', $return['properties']['tide']['low_tide'][0]['tidal_height']);
    $this->checkAndUpdateCmd('Tidelow_tide1time', $return['properties']['tide']['low_tide'][1]['time']);
    $this->checkAndUpdateCmd('Tidelow_tide1tidal_coefficient', $return['properties']['tide']['low_tide'][1]['tidal_coefficient']);
    $this->checkAndUpdateCmd('Tidelow_tide1tidal_height', $return['properties']['tide']['low_tide'][1]['tidal_height']);
  }

  public function getVigilance() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/warning/full?domain=' . $meteofrance->getConfiguration('numDept');
    $return = self::callMeteoWS($url);
    $this->checkAndUpdateCmd('Vigilancecolor_max', $return['color_max']);
    foreach ($return['timelaps'] as $id => $vigilance) {
      $phase = array();
      foreach ($vigilance['timelaps_items'] as $id2 => $segment) {
        $phase[] = date('H:i', $segment['begin_time']) . ' vigilance niveau ' . $segment['color_id'];
      }
      $this->checkAndUpdateCmd('Vigilancephases' . $vigilance['phenomenon_id'], implode(', ',$phase));
    }
    foreach ($return['phenomenons_items'] as $id => $vigilance) {
      $this->checkAndUpdateCmd('Vigilancephenomenon_max_color_id' . $vigilance['phenomenon_id'], $vigilance['phenomenon_max_color_id']);
    }
  }

  public function getAlerts() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/report?domain=france&report_type=message&report_subtype=infospe&format=';
    $return = self::callMeteoWS($url);
    if (isset($return['Com'][0]['titre'])) {
      $this->checkAndUpdateCmd('Alerttitre', $return['Com'][0]['titre']);
      $this->checkAndUpdateCmd('Alerttexte', $return['Com'][0]['texte']);
      $this->checkAndUpdateCmd('AlertdateDeFin', $return['Com'][0]['dateDeFin']);
      $this->checkAndUpdateCmd('AlertdateProduction', $return['Com'][0]['dateProduction']);
    }
  }

  public static function callMeteoWS($_url) {
    //$token = config::byKey('token', 'meteofrance');
    $token = '';
    $request_http = new com_http($_url . '&token=' . $token);
    $request_http->setNoSslCheck(true);
	  $request_http->setNoReportError(true);
	  $return = $request_http->exec(15,2);
    if ($result === false) {
      log::add(__CLASS__, 'debug', 'Unable to fetch ' . $_url);
      return;
    } else {
      log::add(__CLASS__, 'debug', 'Get ' . $_url);
      log::add(__CLASS__, 'debug', 'Result ' . $return);
    }
    return json_encode($return, true);
  }

  public static function callURL($_url) {
    $request_http = new com_http($_url);
    $request_http->setNoSslCheck(true);
$request_http->setNoReportError(true);
  $return = $request_http->exec(15,2);
    if ($result === false) {
      log::add(__CLASS__, 'debug', 'Unable to fetch ' . $_url);
      return;
    } else {
      log::add(__CLASS__, 'debug', 'Get ' . $_url);
      log::add(__CLASS__, 'debug', 'Result ' . $return);
    }
    return json_encode($return, true);
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
