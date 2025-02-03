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

require_once __DIR__ .'/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <div class="form-group">
      <label class="col-md-3 control-label">{{APPLICATION_ID Météo France}}
        <a target="blank" href="https://portail-api.meteofrance.fr/web/fr/faq">Plus d'info ICI</a>
        <sup><i class="fas fa-question-circle tooltips" title="{{A copier sur le site MF et à coller ici}}"></i></sup>
      </label>
      <div class="col-md-7">
        <input type="text" class="configKey form-control" data-l1key="credentialApiMeteoFrance"/>
      </div>
    </div>
    <div class="form-group">
			<label class="col-md-3 control-label">{{Utilisation de l'API vigilance Météo France}}</label>
			<div class="col-sm-1" style="width:2%">
				<input type="checkbox" class="configKey tooltips" data-l1key="useVigilanceAPI">
			</div>
      <label class="col-md-6 control-label" style="text-align: left;line-height:normal">{{La récupération des données de vigilances ne nécessite pas encore l'utilisation de l'API <a href="https://portail-api.meteofrance.fr/web/fr/api/DonneesPubliquesVigilance">DonneesPubliquesVigilance</a> de Météo France. Le plugin  peut récupérer les données sur le <a href="http://storage.gra.cloud.ovh.net/v1/AUTH_555bdc85997f4552914346d4550c421e/gra-vigi6-archive_public/">site d'archives de Météo France</a>.}}
      </label>
    </div>
  </fieldset>
</form>
