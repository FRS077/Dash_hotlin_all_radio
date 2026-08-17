<?php
/*
 *   RoLinkX Dashboard v3.7
 *   Copyright (C) 2024 by Razvan Marin YO6NAM / www.xpander.ro
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program; if not, write to the Free Software
 *   Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 */

/*
 * Forms module
 * Note : Some code borrowed from
 * https://github.com/RaspAP/raspap-webgui
 * https://gist.github.com/magicbug/bf27fc2c9908eb114b4a
 */

if (isset($_GET['scan'])) {
    echo scanWifi(1);
}

if (isset($_GET['gpsStatus'])) {
    echo aprsForm(1);
}

/* Wi-Fi form - VERSION AMÉLIORÉE (logique 100% identique) */
function getSSIDs()
{
    $storedSSID = null;
    $storedPwds = null;
    $wpaBuffer  = file_get_contents('/etc/wpa_supplicant/wpa_supplicant.conf');
    
    preg_match_all('/ssid="(.*)"/', $wpaBuffer, $resultSSID);
    preg_match_all('/psk=(".*?"|\S+)/', $wpaBuffer, $resultPWDS);
    
    if (empty($resultSSID) || empty($resultPWDS)) {
        return false;
    }

    foreach ($resultSSID[1] as $key => $ap) {
        if ($key <= 3) {
            $storedSSID[] = $ap;
        }
    }
    foreach ($resultPWDS[1] as $key => $pw) {
        if ($key <= 3) {
            $storedPwds[] = trim($pw, '"');
        }
    }
    return [$storedSSID, $storedPwds];
}

function scanWifi($ext = 0)
{
    $apList = null;
    $networks = []; // ✅ FIX: Initialisé
    
    // ✅ AMÉLIO : Cache + timeout
    $cacheFile = '/tmp/wifi_scan_' . getmypid() . '.txt';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 10) {
        $reply = file($cacheFile, FILE_IGNORE_NEW_LINES);
    } else {
        exec('timeout 10 /usr/bin/sudo wpa_cli -i wlan0 scan >/dev/null 2>&1');
        exec('timeout 5 /usr/bin/sudo wpa_cli -i wlan0 scan_results 2>/dev/null > ' . $cacheFile);
        $reply = file($cacheFile, FILE_IGNORE_NEW_LINES) ?: [];
    }
    
    if (empty($reply)) {
        return '<div class="alert alert-warning">Scan WiFi échoué</div>';
    }

    array_shift($reply);

    foreach ($reply as $network) {
        $arrNetwork = preg_split("/[\t]+/", $network);
        
        // ✅ FIX: Vérifier 5 champs minimum
        if (count($arrNetwork) < 5) {
            continue;
        }
        
        if (!isset($arrNetwork[4])) {
            continue;
        }

        $ssid = trim($arrNetwork[4]);
        // ✅ AMÉLIO: Validation SSID stricte
        if (empty($ssid) || strlen($ssid) > 32 || 
            preg_match('/[\x00-\x1F\x7F]/', $ssid)) {
            continue;
        }
        
        $networks[$ssid]['ssid'] = $ssid;
        $networks[$ssid] = array(
            'rssi'     => $arrNetwork[2],
            'protocol' => authType($arrNetwork[3]),
            'channel'  => freqToChan($arrNetwork[1]),
        );
    }

    if (!empty($networks)) {
        $cnt = 1;
        $apList = '<table class="table table-sm"><thead><tr>
            <th scope="col">#</th>
            <th scope="col">SSID</th>
            <th scope="col">RSSI</th>
            <th scope="col">Auth</th>
            <th scope="col">Ch.</th>
            </tr></thead>
            <tbody>';

        foreach ($networks as $name => $data) {
            if ($data['rssi'] >= -80) {
                $lvlQuality = 'class="table-success"';
            } elseif ($data['rssi'] >= -90) {
                $lvlQuality = 'class="table-warning"';
            } else {
                $lvlQuality = 'class="table-light"';
            }

            $apList .= '<tr ' . $lvlQuality . '><th scope="row">' . $cnt . '</th>
                        <td>' . htmlspecialchars($name, ENT_QUOTES) . '</td> <!-- ✅ XSS -->
                        <td>' . htmlspecialchars($data['rssi'], ENT_QUOTES) . ' dBm</td>
                        <td>' . htmlspecialchars($data['protocol'], ENT_QUOTES) . '</td>
                        <td>' . htmlspecialchars($data['channel'], ENT_QUOTES) . '</td>
                        </tr>';
            ++$cnt;
        }
        $apList .= '</tbody></table>';
    }
    return $apList ?: ''; // ✅ FIX: Vide au lieu de null
}

function authType($type)
{
    $options = array();
    preg_match_all('/\[([^\]]+)\]/s', $type, $matches);

    foreach ($matches[1] as $match) {
        if (preg_match('/^(WPA\d?)/', $match, $protocol_match)) {
            $protocol  = $protocol_match[1];
            $options[] = htmlspecialchars($protocol, ENT_QUOTES);
        }
    }

    return count($options) === 0 ? 'Open' : implode(' / ', $options);
}

function freqToChan($freq)
{
    $freq = (int)$freq; // ✅ FIX: Cast int
    
    if ($freq >= 2412 && $freq <= 2484) {
        $channel = (int)(($freq - 2407) / 5);
    } elseif ($freq >= 4915 && $freq <= 4980) {
        $channel = (int)(($freq - 4910) / 5 + 182);
    } elseif ($freq >= 5035 && $freq <= 5865) {
        $channel = (int)(($freq - 5030) / 5 + 6);
    } else {
        $channel = -1;
    }
    return ($channel >= 1 && $channel <= 196) ? $channel : 'Invalid Channel';
}

function wifiForm()
{
    $ssidList = getSSIDs();
    if ($ssidList === false) { // ✅ FIX: Gérer false
        $ssidList = [[], []];
    }
    
    $apsList  = '<div class="accordion mb-3" id="wifiNetworks">
    <div class="accordion-item">
     <h3 class="accordion-header" id="heading">
        <button class="bg-info text-white accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#availableNetworks" aria-expanded="false" aria-controls="availableNetworks">
            <span role="status" class="spinner-border spinner-border-sm mx-2"></span>Analyse du WiFi
        </button>
     </h3>
     <div id="availableNetworks" class="accordion-collapse collapse" aria-labelledby="heading" data-bs-parent="#wifiNetworks">
        <div id="updateList" class="accordion-body"></div>
    </div>
    </div>
    </div>';
    
    // ✅ AMÉLIO: Fallback iwgetid
    exec('/sbin/iwgetid --raw 2>/dev/null', $con);
    
    $wifiForm = '<h4 class="mt-2 alert alert-info fw-bold">Configuration du Wi-Fi </h4>';
    $wifiForm .= '<div id="wifiScanner">' . $apsList . '</div>';
    $wifiForm .= '<div class="card">
        <div class="card-header">Ajouter / Modifier les réseaux</div>
        <div class="card-body">' . PHP_EOL;
        
    for ($i = 0; $i < 4; $i++) {
        $connected   = isset($con[0]) ? trim($con[0]) : null;
        $active      = (isset($ssidList[0][$i]) && $connected === $ssidList[0][$i]) ? true : false;
        $networkName = empty($ssidList[0][$i]) ? 'empty' : htmlspecialchars($ssidList[0][$i], ENT_QUOTES) . ' (sauvegardé)'; // ✅ Traduit + XSS
        $networkKey  = empty($ssidList[1][$i]) ? 'empty' : '********';
        $count       = ($i + 1);
        $background  = ($active) ? ' bg-success text-white' : null;
        $status      = ($active) ? ' (connecté)' : null;
        
        $wifiForm .= '<h4 class="d-flex justify-content-center badge badge-light fs-6' . $background . '">
            <i class="icon-wifi">&nbsp;</i>Network ' . $count . $status . '
        </h4>
        <div class="input-group input-group-sm mb-2">
          <span class="input-group-text" style="width: 7rem;">Nom du (SSID)</span>
          <input id="wlan_network_' . $count . '" type="text" class="form-control" 
                 placeholder="' . $networkName . '" aria-label="Network Name" aria-describedby="inputGroup-sizing-sm">
        </div>
        <div class="input-group input-group-sm mb-4">
          <span class="input-group-text" style="width: 7rem;">Key (Password)</span>
          <input id="wlan_authkey_' . $count . '" type="text" class="form-control" 
                 placeholder="' . $networkKey . '" aria-label="Network key" aria-describedby="inputGroup-sizing-sm">
        </div>' . PHP_EOL;
    }
    
    $wifiForm .= '<div class="row justify-content-center m-1">
            <div class="col-auto alert alert-info m-2 p-1" role="alert">
                Pour supprimer un réseau, entrez un tiret (-) comme nom du SSID.
            </div>
            <div class="col-auto alert alert-warning m-2 p-1" role="alert">
                Les réseaux ouverts (sans clé) ne sont pas pris en charge
            </div>
        </div>
        <div class="d-flex justify-content-center mt-2">
            <button id="savewifi" class="m-2 btn btn-danger btn-lg">Sauvegarder</button>
            <button id="rewifi" class="m-2 btn btn-info btn-lg">Restart Wi-Fi</button>
        </div>
        </div>
    </div>' . PHP_EOL;
    
    $wifiForm .= '<script>
    var refreshCount = 0;
    var auto_refresh = setInterval(function () {
        if (++refreshCount > 50) { // ✅ AMÉLIO: Anti-boucle (5min max)
            clearInterval(auto_refresh);
            $("#heading button").html("Scan arrêté");
            return;
        }
    	
    	$("#heading button").html("<span role=\"status\" class=\"spinner-border spinner-border-sm mx-2\"></span>Scanning WiFi")
    	                    .removeClass("bg-success").addClass("bg-info");
        $("#updateList").load("includes/forms.php?scan", function() {
            $("#heading button").text("Scan terminé (cliquez pour ouvrir/fermer)")
                               .removeClass("bg-info").addClass("bg-success");
        });
    }, 6000);
    </script>' . PHP_EOL;
    
    return $wifiForm;
}

/* SVXLink form */
function svxForm()
{
    $env = checkEnvironment();
    if ($env) {
        return $env;
    }

    global $cfgFile, $config, $pinsArray, $cfgRefFile;
    $svxPinsArray = array();
    //$saDetect     = sa8x8Detect();
    /* Convert pins to both states (normal/inverted) */
    foreach ($pinsArray as $pin) {
        $svxPinsArray[] = 'gpio' . $pin;
        $svxPinsArray[] = '!gpio' . $pin;
    }
    $profileOption = null;
    $voicesPath    = '/opt/rolink/share/sounds';

    /* Get current variables */
    $cfgFileData = file_get_contents($cfgFile);
    /* Host / Reflector */
    preg_match('/(HOST=)(\S+)/', $cfgFileData, $varReflector);
    $reflectorValue = (isset($varReflector[2])) ? 'value=' . $varReflector[2] : '';
    /* Port */
    preg_match('/(PORT=)(\d+)/', $cfgFileData, $varPort);
    $portValue = (isset($varPort[2])) ? 'value=' . $varPort[2] : '';
    /* Callsign for authentification */
    preg_match('/(CALLSIGN=")(\S+)"/', $cfgFileData, $varCallSign);
    $callSignValue = (isset($varCallSign[2])) ? 'value=' . $varCallSign[2] : '';
    /* Key for authentification */
    preg_match('/(AUTH_KEY=)"(\S+)"/', $cfgFileData, $varAuthKey);
    $authKeyValue = (isset($varAuthKey[2])) ? 'value=' . $varAuthKey[2] : '';
    /* Callsign for beacons */
    preg_match('/(CALLSIGN=)(\w\S+)/', $cfgFileData, $varBeacon);
    $beaconValue = (isset($varBeacon[2])) ? 'value=' . $varBeacon[2] : '';
    /* RX GPIO */
    preg_match('/(GPIO_SQL_PIN=)(\S+)/', $cfgFileData, $varRxGPIO);
    $rxGPIOValue = (isset($varRxGPIO[2])) ? $varRxGPIO[2] : '';
    /* TX GPIO */
    preg_match('/(PTT_PIN=)(\S+)/', $cfgFileData, $varTxGPIO);
    $txGPIOValue = (isset($varTxGPIO[2])) ? $varTxGPIO[2] : '';
    /* Roger beep */
    preg_match('/(RGR_SOUND_ALWAYS=)(\d+)/', $cfgFileData, $varRogerBeep);
    $rogerBeepValue = (isset($varRogerBeep[2])) ? $varRogerBeep[2] : '';
    /* Squelch delay */
    preg_match('/(SQL_DELAY=)(\d+)/', $cfgFileData, $varSquelchDelay);
    $sqlDelayValue = (isset($varSquelchDelay[2])) ? 'value=' . $varSquelchDelay[2] : '';
    /* Default TG */
    preg_match('/(DEFAULT_TG=)(\d+)/', $cfgFileData, $varDefaultTg);
    $defaultTgValue = (isset($varDefaultTg[2])) ? 'value=' . $varDefaultTg[2] : '';
    /* Monitor TGs*/
    preg_match('/(MONITOR_TGS=)(.+)/', $cfgFileData, $varMonitorTgs);
    $monitorTgsValue = (isset($varMonitorTgs[2])) ? 'value=' . $varMonitorTgs[2] : '';
    /* TG Select Timeout */
    preg_match('/(TG_SELECT_TIMEOUT=)(\d+)/', $cfgFileData, $varTgSelTimeOut);
    $tgSelTimeOutValue = (isset($varTgSelTimeOut[2])) ? 'value=' . $varTgSelTimeOut[2] : '';
    /* Announce connection status interval */
    preg_match('/(ANNOUNCE_CONNECTION_STATUS=)(\d+)/', $cfgFileData, $varAnnounceConnectionStatus);
    $announceConnectionStatusValue = (isset($varAnnounceConnectionStatus[2])) ? 'value=' . $varAnnounceConnectionStatus[2] : '';
    /* Opus codec bitrate */
    preg_match('/(OPUS_ENC_BITRATE=)(\d+)/', $cfgFileData, $varCodecBitRate);
    $bitrateValue = (isset($varCodecBitRate[2])) ? 'value=' . $varCodecBitRate[2] : '';
    /* Voice Language */
    preg_match('/(DEFAULT_LANG=)(\S+)/', $cfgFileData, $varVoicePack);
    /* Short / Long Intervals */
    preg_match('/(SHORT_IDENT_INTERVAL=)(\d+)/', $cfgFileData, $varShortIdent);
    preg_match('/(LONG_IDENT_INTERVAL=)(\d+)/', $cfgFileData, $varLongIdent);
    /* TimeOut Timer (TX) */
    preg_match('/(TIMEOUT=)(\d+)\nTX/', $cfgFileData, $varTxTimeout);
    $txTimeOutValue = (isset($varTxTimeout[2])) ? 'value=' . $varTxTimeout[2] : '';
    /* DeEmphasis (RX) */
    preg_match('/(DEEMPHASIS=)(\d+)\n/', $cfgFileData, $varDeEmphasis);
    $deEmphasisValue = (isset($varDeEmphasis[2])) ? $varDeEmphasis[2] : '';
    /* PreEmphasis (TX) */
    preg_match('/(PREEMPHASIS=)(\d+)\n/', $cfgFileData, $varPreEmphasis);
    $preEmphasisValue = (isset($varPreEmphasis[2])) ? $varPreEmphasis[2] : '';
    /* MasterGain (TX) */
    preg_match('/(MASTER_GAIN=)(-?\d+(\.\d{1,2})?)\n/', $cfgFileData, $varMasterGain);
    $masterGainValue = (isset($varMasterGain[2])) ? $varMasterGain[2] : '';
    /* Reconnect after (seconds) */
    preg_match('/(RECONNECT_SECONDS=)(\d+)/', $cfgFileData, $varReconnectSeconds);
    $reconnectSecondsValue = (isset($varReconnectSeconds[2])) ? 'value=' . $varReconnectSeconds[2] : '';
    /* Limiter */
    preg_match('/(LIMITER_THRESH=)(-?\d+)\n/', $cfgFileData, $varLimiter);
    $limiterValue = (isset($varLimiter[2])) ? $varLimiter[2] : '';
    /* Fan control */
    preg_match('/(FAN_START=)(\d+)/', $cfgFileData, $varFanStart);
    $fanStartValue = (isset($varFanStart[2])) ? 'value=' . $varFanStart[2] : '';
    /* Modules */
    preg_match('/(#?)MODULES=(\S+)/', $cfgFileData, $varModules);
    $modulesValue   = (isset($varModules[1])) ? $varModules[1] : '';
    $modulesEnabled = (isset($varModules[2])) ? explode(',', strtolower(trim($varModules[2]))) : [];
    /* Tx Delay */
    preg_match('/(TX_DELAY=)(\d+)/', $cfgFileData, $varTxDelay);
    $txDelayValue = (isset($varTxDelay[2])) ? 'value=' . $varTxDelay[2] : '';

    /* Profiles section */
    $profilesPath = dirname(__FILE__) . '/../profiles/';
    $proFiles     = array_slice(scandir($profilesPath), 2);
    $skip         = array('sa818pgm.log', 'index.html');

    /* Configuration info sent to reflector ('tip' only) */
    $cfgRefFile = file_get_contents($cfgRefFile);
    $cfgRefData = json_decode($cfgRefFile, true);

    if (!empty($proFiles)) {
        $profileOption = '<div class="input-group input-group-sm mb-3">
              <label class="input-group-text bg-info text-white" for="svx_spn" style="width: 8rem;">Choisir un profil</label>
              <select id="svx_spn" class="form-select">
                <option value="" selected disabled>Sélectionnez un profil</option>' . PHP_EOL;
        foreach ($proFiles as $profile) {
            if (in_array($profile, $skip)) {
                continue;
            }

            $profileOption .= '<option value="' . $profile . '">' . basename($profile, '.json') . '</option>' . PHP_EOL;
        }
        $profileOption .= '</select>
        <button id="delsvxprofile" class="btn btn-outline-danger" type="button">Delete</button>
        </div>
        <div class="separator">General</div>';
    }

    $svxForm = '<h4 class="mt-2 alert alert-warning fw-bold">SVXLink configuration</h4>';
    $svxForm .= $profileOption;
    $svxForm .= '<div class="input-group input-group-sm mb-1">
          <span class="input-group-text bg-info text-white" style="width: 10rem;">Créer un nouveau profil</span>
          <input id="svx_prn" type="text" class="form-control" placeholder="Donnez un nom à votre profil" aria-label="Profile name" aria-describedby="inputGroup-sizing-sm">
        </div>';
    $svxForm .= '<div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Reflector (IP/DNS)</span>
          <input id="svx_ref" type="text" class="form-control" placeholder="f62dmr.fr" aria-label="Server address" aria-describedby="inputGroup-sizing-sm" ' . $reflectorValue . '>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Port</span>
          <input id="svx_prt" type="text" class="form-control" placeholder="5300" aria-label="Port" aria-describedby="inputGroup-sizing-sm" ' . $portValue . '>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Indicatif</span>
          <input id="svx_cal" type="text" class="form-control" placeholder="FRSXXX" aria-label="Callsign" aria-describedby="inputGroup-sizing-sm" ' . $callSignValue . '>
        </div>
        <div id="auth_key" class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Passwd</span>
          <input id="svx_key" type="password" class="form-control" placeholder="Node_Radio" aria-label="Auth Key" aria-describedby="inputGroup-sizing-sm" ' . $authKeyValue . '>
          <button id="show_hide" class="input-group-text" role="button"><i class="icon-visibility" aria-hidden="true"></i></button>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Indicatif (Balise)</span>
          <input id="svx_clb" type="text" class="form-control" placeholder="FRSXXX" aria-label="Callsign" aria-describedby="inputGroup-sizing-sm" ' . $beaconValue . '>
        </div>';
    $svxForm .= '<div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Roger Beep</span>
          <select id="svx_rgr" class="form-select">
            <option value="0"' . (($rogerBeepValue == 0) ? ' selected' : '') . '>Non</option>
            <option value="1"' . (($rogerBeepValue == 1) ? ' selected' : '') . '>Oui</option>
          </select>
        </div>';
    /* Voice language detection/selection */
    $svxForm .= '<div class="input-group input-group-sm mb-1">
        <span class="input-group-text" style="width: 8rem;">Pack vocal</span>' . PHP_EOL;
    if (is_dir($voicesPath)) {
        $svxForm .= '<select id="svx_vop" class="form-select">' . PHP_EOL;
        foreach (glob($voicesPath . '/*', GLOB_ONLYDIR) as $voiceDir) {
            $availableVoicePacks = str_replace($voicesPath . '/', '', $voiceDir);
            $vsel                = ($availableVoicePacks == $varVoicePack[2]) ? ' selected' : null;
            $svxForm .= '<option value="' . $availableVoicePacks . '"' . $vsel . '>' . $availableVoicePacks . '</option>' . PHP_EOL;
        }
        $svxForm .= '</select>' . PHP_EOL;
        $svxForm .= '<button type="button" id="getVoices" class="btn btn-light btn-lg btn-block">&#128260;</button>' . PHP_EOL;
    } else {
        $svxForm .= '<button type="button" id="getVoices" class="btn btn-primary btn-lg btn-block">Download &amp; install voices</button>' . PHP_EOL;
    }
    $svxForm .= '</div>' . PHP_EOL;
    $svxForm .= '
        <div class="input-group input-group-sm mb-1">
          <label class="input-group-text" for="svx_sid" style="width: 8rem;">Balise courte</label>
          <select id="svx_sid" class="form-select">
             <option value="0">Disabled</option>' . PHP_EOL;
    /* Generate 5 minutes intervals up to 60 & identify stored value on file */
    for ($sid = 5; $sid <= 120; $sid += 5) {
        $sel = ($sid == $varShortIdent[2]) ? ' selected' : null;
        $svxForm .= '<option value="' . $sid . '"' . $sel . '>' . $sid . ' minutes</option>' . PHP_EOL;
    }
    $svxForm .= '</select>
        </div>
        <div class="input-group input-group-sm mb-1">
          <label class="input-group-text" for="svx_lid" style="width: 8rem;">Balise longue</label>
          <select id="svx_lid" class="form-select">
             <option value="0">Disabled</option>' . PHP_EOL;
    /* Generate 5 minutes intervals up to 60 & identify stored value on file */
    for ($lid = 5; $lid <= 300; $lid += 5) {
        $sel = ($lid == $varLongIdent[2]) ? ' selected' : null;
        $svxForm .= '<option value="' . $lid . '"' . $sel . '>' . $lid . ' minutes</option>' . PHP_EOL;
    }
    $svxForm .= '</select>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Type</span>
          <input id="svx_tip" type="text" class="form-control" placeholder="Node SVXLink" aria-label="Description" aria-describedby="inputGroup-sizing-sm" value="' . $cfgRefData['tip'] . '">
        </div>
        <div class="input-group input-group-sm mb-3">
          <span class="input-group-text" style="width: 8rem;">Modules</span>
          <select id="svx_mod" class="form-select">
            <option value="0"' . (($modulesValue === '#') ? ' selected' : '') . '>None</option>
            <option value="1"' . ((empty($modulesValue) && in_array('moduleparrot', $modulesEnabled)) ? ' selected' : '') . '>Parrot</option>
            <option value="2"' . ((empty($modulesValue) && in_array('moduleecholink', $modulesEnabled)) ? ' selected' : '') . '>EchoLink</option>
            <option value="9"' . ((empty($modulesValue) && count($modulesEnabled) > 1) ? ' selected' : '') . '>All</option>
          </select>
        </div>';
    if (empty($modulesValue) && in_array('moduleecholink', $modulesEnabled)) {
        $svxForm .= '<div class="separator mb-1">EchoLink Module</div>';
        $svxForm .= echoLinkForm();
    }
    $svxForm .= '<div class="separator mb-1">Avancée</div>';
    $svxForm .= '<div class="input-group input-group-sm mb-1">
            <label class="input-group-text" for="svx_rxp" style="width: 8rem;">RX GPIO pin</label>
            <select id="svx_rxp" class="form-select">' . PHP_EOL;
    foreach ($svxPinsArray as $rxpin) {
        $inverted     = (strpos($rxpin, '!') !== false) ? ' (inverted)' : null;
        $defaultRxPin = ($rxpin == 'gpio10') ? ' (default)' : null;
        $svxForm .= '<option value="' . $rxpin . '"' . ($rxpin == $rxGPIOValue ? ' selected' : '') . '>' . (int) filter_var($rxpin, FILTER_SANITIZE_NUMBER_INT) . $defaultRxPin . $inverted . '</option>' . PHP_EOL;
    }
    $svxForm .= '</select>
        </div>
        <div class="input-group input-group-sm mb-1">
            <label class="input-group-text" for="svx_txp" style="width: 8rem;">TX GPIO pin</label>
            <select id="svx_txp" class="form-select">' . PHP_EOL;
    foreach ($svxPinsArray as $txpin) {
        $inverted     = (strpos($txpin, '!') !== false) ? ' (inverted)' : null;
        $defaultTxPin = ($txpin == 'gpio7') ? ' (default)' : null;
        $svxForm .= '<option value="' . $txpin . '"' . ($txpin == $txGPIOValue ? ' selected' : '') . '>' . (int) filter_var($txpin, FILTER_SANITIZE_NUMBER_INT) . $defaultTxPin . $inverted . '</option>' . PHP_EOL;
    }
    $svxForm .= '</select>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Délai du Squelch</span>
          <input id="svx_sqd" type="text" class="form-control" placeholder="100" aria-label="Squelch Delay" aria-describedby="inputGroup-sizing-sm" ' . $sqlDelayValue . '>
        </div>';
    if (!is_array($saDetect)) {
        $svxForm .= '<div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">TX Delay</span>
          <input id="svx_txd" type="text" class="form-control" placeholder="875" aria-label="TX Delay" aria-describedby="inputGroup-sizing-sm" ' . $txDelayValue . '>
        </div>';
    }
    $svxForm .= '<div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">TG par défaut</span>
          <input id="svx_dtg" type="text" class="form-control" placeholder="59" aria-label="Default TG" aria-describedby="inputGroup-sizing-sm" ' . $defaultTgValue . '>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Surveiller les TG</span>
          <input id="svx_mtg" type="text" class="form-control" placeholder="59" aria-label="Monitor TGs" aria-describedby="inputGroup-sizing-sm" ' . $monitorTgsValue . '>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Timeout du TG (s)</span>
          <input id="svx_tgt" type="text" class="form-control" placeholder="30" aria-label="TG Timeout" aria-describedby="inputGroup-sizing-sm" ' . $tgSelTimeOutValue . '>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 9rem;">Reconnexion après (s)</span>
          <input id="svx_res" type="text" class="form-control" placeholder="5" aria-label="Reconnect After" aria-describedby="inputGroup-sizing-sm" ' . $reconnectSecondsValue . '>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 9rem;">État de la connexion</span>
          <input id="svx_acs" type="text" class="form-control" placeholder="1" aria-label="Connection Status" aria-describedby="inputGroup-sizing-sm" ' . $announceConnectionStatusValue . '>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">TX Timeout</span>
          <input id="svx_txt" type="text" class="form-control" placeholder="180" aria-label="TX Timeout" aria-describedby="inputGroup-sizing-sm" ' . $txTimeOutValue . '>
        </div>';
    if (!is_array($saDetect)) {
        $svxForm .= '<div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">De-Emphasis (RX)</span>
          <select id="svx_rxe" class="form-select">
            <option value="0"' . (($deEmphasisValue == 0) ? ' selected' : '') . '>No</option>
            <option value="1"' . (($deEmphasisValue == 1) ? ' selected' : '') . '>Yes</option>
          </select>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Pre-Emphasis (TX)</span>
          <select id="svx_txe" class="form-select">
            <option value="0"' . (($preEmphasisValue == 0) ? ' selected' : '') . '>No</option>
            <option value="1"' . (($preEmphasisValue == 1) ? ' selected' : '') . '>Yes</option>
          </select>
        </div>';
    }
    $svxForm .= '<div class="input-group input-group-sm mb-1">
            <label class="input-group-text" for="svx_mag" style="width: 8rem;">Master Gain (TX)</label>
            <select id="svx_mag" class="form-select">' . PHP_EOL;
    for ($gain = 6; $gain >= -6; $gain -= .25) {
        $svxForm .= '<option value="' . $gain . '"' . ($gain == $masterGainValue ? ' selected' : '') . '>' . (($gain > 0) ? '+' . $gain : $gain) . ' dB</option>' . PHP_EOL;
    }
    $svxForm .= '</select>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Compresseur audio</span>
          <select id="svx_lim" class="form-select">
            <option value="-6"' . (($limiterValue != 0) ? ' selected' : '') . '>Normal</option>
            <option value="0"' . (($limiterValue == 0) ? ' selected' : '') . '>Enhanced (defaut)</option>
          </select>
        </div>
        <div class="input-group input-group-sm mb-1">
          <label class="input-group-text" for="svx_cbr" style="width: 8rem;">Codec Bitrate</label>
          <select id="svx_cbr" class="form-select">' . PHP_EOL;
    if (isset($varCodecBitRate[2])) {
        /* Generate codec bitrates */
        for ($cbr = 8000; $cbr <= 32000; $cbr += 2000) {
            $sel       = ($cbr == $varCodecBitRate[2]) ? ' selected' : null;
            $cbrSuffix = ($cbr == 20000) ? '(default)' : null;
            $svxForm .= '<option value="' . $cbr . '"' . $sel . '>' . $cbr / 1000 . ' kb/s ' . $cbrSuffix . '</option>' . PHP_EOL;
        }
    } else {
        $svxForm .= '<option value="" disabled selected>Unavailable</option>' . PHP_EOL;
    }
    $svxForm .= '
          </select>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Fan Start</span>
          <input id="svx_fan" type="text" class="form-control" placeholder="180" aria-label="Fan Start after" aria-describedby="inputGroup-sizing-sm" ' . $fanStartValue . '>
        </div>
        <input type="hidden" id="autoConnect" name="autoConnect" value="' . $config['cfgAutoConnect'] . '" />';
    $svxForm .= '
        <div class="d-flex justify-content-center mt-4">
            <button id="savesvxcfg" type="submit" class="btn btn-danger btn-lg m-2">Sauvegarder</button>
            <button id="restore" type="submit" class="btn btn-info btn-lg m-2">Restore defaults</button>
            </div>' . PHP_EOL;
    return $svxForm;
}

/* EchoLink form */
function echoLinkForm()
{
    global $cfgELFile;
    $echoLinkData = file_get_contents($cfgELFile);
    preg_match('/CALLSIGN=(\S+)/', $echoLinkData, $elCallSign);
    preg_match('/PASSWORD=(\S+)/', $echoLinkData, $elPassword);
    preg_match('/SYSOPNAME=(.*)/', $echoLinkData, $elSysop);
    preg_match('/TIMEOUT=(\d+)/', $echoLinkData, $elTimeout);
    preg_match('/LOCATION=(.*)/', $echoLinkData, $elLocation);
    preg_match('/LINK_IDLE_TIMEOUT=(\d+)/', $echoLinkData, $elLinkIdleTimeout);
    // Proxy options
    preg_match('/(#?)PROXY_SERVER=(\S+)/', $echoLinkData, $elProxyServer);
    preg_match('/(#?)PROXY_PORT=(\d+)/', $echoLinkData, $elProxyPort);
    preg_match('/(#?)PROXY_PASSWORD=(\S+)/', $echoLinkData, $elProxyPassword);
    $echoLinkForm = '<div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Indicatif</span>
          <input id="svx_el_cal" type="text" class="form-control" placeholder="FRS000" aria-label="Callsign" aria-describedby="inputGroup-sizing-sm" value=' . $elCallSign[1] . '>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Password</span>
          <input id="svx_el_pwd" type="password" class="form-control" placeholder="s3cr3t" aria-label="Password" aria-describedby="inputGroup-sizing-sm" value=' . $elPassword[1] . '>
          <button id="show_hide_el" class="input-group-text" role="button"><i class="icon-visibility" aria-hidden="true"></i></button>
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Sysop</span>
          <input id="svx_el_sop" type="text" class="form-control" placeholder="John Doe" aria-label="Sysop Name" aria-describedby="inputGroup-sizing-sm" value="' . $elSysop[1] . '">
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Location</span>
          <input id="svx_el_loc" type="text" class="form-control" placeholder="..." aria-label="Callsign" aria-describedby="inputGroup-sizing-sm" value="' . htmlspecialchars($elLocation[1], ENT_QUOTES, 'UTF-8') . '">
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Timeout</span>
          <input id="svx_el_to" type="text" class="form-control" placeholder="60" aria-label="Timeout" aria-describedby="inputGroup-sizing-sm" value="' . $elTimeout[1] . '">
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Link Idle Timeout</span>
          <input id="svx_el_lit" type="text" class="form-control" placeholder="300" aria-label="Link Idle Timeout" aria-describedby="inputGroup-sizing-sm" value="' . $elLinkIdleTimeout[1] . '">
        </div>
         <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Proxy Server</span>
          <select id="svx_el_pxtype" class="form-select">
             <option value="" selected disabled>- please select-</option>
            <option value="0">Custom</option>
            <option value="1">Public</option>
          </select>
        </div>
        <div class="input-group input-group-sm mb-1" id="proxyInputContainer">
          <span class="input-group-text" style="width: 8rem;">Proxy Address</span>
          <input id="svx_el_pxsrv" type="text" class="form-control" placeholder="the.proxy.server" aria-label="Proxy Server" aria-describedby="inputGroup-sizing-sm" value="' . $elProxyServer[2] . '">
        </div>
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text" style="width: 8rem;">Proxy Port</span>
          <input id="svx_el_pxp" type="text" class="form-control" placeholder="8100" aria-label="Proxy Port" aria-describedby="inputGroup-sizing-sm" value="' . $elProxyPort[2] . '">
        </div>
        <div class="input-group input-group-sm mb-3">
          <span class="input-group-text" style="width: 8rem;">Proxy Password</span>
          <input id="svx_el_pxpw" type="text" class="form-control" placeholder="PUBLIC" aria-label="Proxy Password" aria-describedby="inputGroup-sizing-sm" value="' . $elProxyPassword[2] . '">
        </div>';
    return $echoLinkForm;
}


/* APRS */
function aprsForm($ajax = false)
{
    $cfgFiles = array(
        '/opt/rolink/conf/rolink.conf' => 'HotLink',
        '/etc/direwolf.conf'           => 'DireWolf',
    );
    foreach ($cfgFiles as $path => $name) {
        if (!is_file($path)) {
            return '<div class="alert alert-danger text-center" role="alert">' . $name . ' not installed!</div>';
        }

    }
    if ($ajax) {
        include_once __DIR__ . '/functions.php';
    }

    $callsign = $aprsfiLink = $comment = $server = $symbol = '';
    $report   = 0;
    if (preg_match('/IGLOGIN (\S+)/', file_get_contents('/etc/direwolf.conf'), $matches)) {
        $callsign   = $matches[1];
        $aprsfiLink = (empty($callsign) && $callsign == 'N0CALL-15') ? null : '<span data-bs-toggle="tooltip" title="View ' . $callsign . ' on cbaprs" class="input-group-text">
            <a class="mx-2" href="http://cbaprs.at/#!call=' . $callsign . '" target="_blank"><i class="icon-exit_to_app"></i></a>
        </span>';
    }
    $aprsForm = '<h4 class="mt-2 alert alert-primary fw-bold">APRS</h4>';
    $data     = json_decode(gpsd(), true);
    if ($data['class'] == 'ERROR') {
        $aprsForm .= '<div class="alert alert-danger text-center" role="alert">' . $data['message'] . '</div>';
        return $aprsForm;
    }
    ;

    $svcDirewolf = trim(shell_exec("systemctl is-active direwolf"));
    $svcGPSD     = trim(shell_exec("systemctl is-active gpsd"));

    $aprsForm .= '<div class="accordion mb-3" id="gpsdata">
   <div class="accordion-item">
      <h3 class="accordion-header" id="heading">
         <button class="bg-' . (($svcDirewolf == 'active' && $svcGPSD == 'active') ? 'success' : 'danger') . ' text-white accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#position" aria-expanded="true" aria-controls="position">Statut</button>
      </h3>
      <div id="position" class="accordion-collapse collapse show" aria-labelledby="heading" data-bs-parent="#gpsdata">
    <div id="dynamicData" class="accordion-body">';

    $dynamicData = '<div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 6.5rem;">Direwolf</span>
            <input type="text" class="form-control ' . (($svcDirewolf == 'active') ? 'text-success' : 'text-danger') . '" value="' . $svcDirewolf . '" readonly>
            ' . (($svcDirewolf == 'active') ? $aprsfiLink : null) . '
        </div>
    <div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 6.5rem;">GPSD</span>
            <input type="text" class="form-control ' . (($svcGPSD == 'active') ? 'text-success' : 'text-danger') . '" value="' . $svcGPSD . '" readonly>
    </div>';

    if ($svcGPSD == 'active' && isset($data['tpv'][0])) {
        $fixDescriptions = [0 => "unknown", 1 => "no fix", 2 => "2D", 3 => "3D"];
        $gpsData         = $data['tpv'][0];
        if ($gpsData['mode'] === 0) {
            return '<meta http-equiv="refresh" content="3"><div class="alert alert-warning text-center" role="alert">Status unknown. Reloading...</div>';
        }

        $fixMode     = $fixDescriptions[$gpsData['mode']];
        $coordinates = number_format($gpsData['lat'], 5) . ', ' . number_format($gpsData['lon'], 5);
        $altitude    = (($gpsData['mode'] == 3) ? round($gpsData['alt']) . ' m' : 'N/A');
        $speed       = (($gpsData['mode'] == 3) ? round($gpsData['speed'] * 3.6) . ' km/h' : 'N/A');

        // Convert reported time to selected timezone (Config page)
        $utcTime     = new DateTime($gpsData['time'], new DateTimeZone("UTC"));
        $timezone    = trim(file_get_contents('/etc/timezone'));
        $eetTimeZone = new DateTimeZone($timezone);
        $utcTime->setTimezone($eetTimeZone);
        $time = $utcTime->format("H:i:s d/m/Y");

        // Maidenhead Locator
        $longitude  = $gpsData['lon'] + 180;
        $latitude   = $gpsData['lat'] + 90;
        $letterA    = ord('A');
        $numberZero = ord('0');
        $locator    = chr($letterA + intval($longitude / 20));
        $locator .= chr($letterA + intval($latitude / 10));
        $locator .= chr($numberZero + intval(($longitude % 20) / 2));
        $locator .= chr($numberZero + intval($latitude % 10));
        $locator .= chr($letterA + intval(($longitude - intval($longitude / 2) * 2) / (2 / 24)));
        $locator .= chr($letterA + intval(($latitude - intval($latitude / 1) * 1) / (1 / 24)));

        $dynamicData .= '<div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 6.5rem;">Fix mode</span>
            <input type="text" class="form-control" value="' . $fixMode . '" readonly>
        </div>';
        $dynamicData .= ($gpsData['mode'] < 2) ? null : '<div class="input-group input-group-sm mb-1">
            <div class="input-group-prepend input-group-sm">
                <span class="input-group-text" style="width: 6.5rem;">Lat / Lon</span>
            </div>
            <input type="text" class="form-control" value="' . $coordinates . '" readonly>
        </div>
        <div class="input-group input-group-sm mb-1">
            <div class="input-group-prepend input-group-sm">
                <span class="input-group-text" style="width: 6.5rem;">Grid Square</span>
            </div>
            <input type="text" class="form-control" value="' . $locator . '" readonly>
        </div>
        <div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 6.5rem;">Altitude</span>
            <input type="text" class="form-control" value="' . $altitude . '" readonly>
        </div>
        <div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 6.5rem;">Speed</span>
            <input type="text" class="form-control" value="' . $speed . '" readonly>
        </div>';
        $dynamicData .= '<div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 6.5rem;">Time</span>
            <input type="text" class="form-control" value="' . $time . '" readonly>
        </div>';
        $dynamicData .= ($gpsData['mode'] < 2) ? null : '<div class="col-auto fill">
            <div class="map" id="map"></div>
        </div>
        <script>
            var LonLat = ol.proj.fromLonLat([' . $gpsData['lon'] . ',' . $gpsData['lat'] . '])
            var stroke = new ol.style.Stroke({color: "red", width: 2});
            var feature = new ol.Feature(new ol.geom.Point(LonLat))
            var x = new ol.style.Style({
                image: new ol.style.Icon({
                anchor: [0.5, 1],
                crossOrigin: "anonymous",
                src: "assets/img/pin.png",
                })
            })
            feature.setStyle(x)
            var source = new ol.source.Vector({
                features: [feature]
            });
            var vectorLayer = new ol.layer.Vector({
              source: source
            });
            var map = new ol.Map({
              target: "map",
              layers: [
                new ol.layer.Tile({
                  source: new ol.source.OSM()
                }),
                vectorLayer
              ],
              view: new ol.View({
                center: LonLat,
                zoom: 10
              })
            });
        </script>' . PHP_EOL;
    }
    /* Return updates only */
    if ($ajax) {
        return $dynamicData;
    }

    /* Read config*/
    $aprsConfig = file('/etc/direwolf.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($aprsConfig as $line) {
        if (preg_match('/IGSERVER (\S+)/', $line, $matches)) {
            $server = $matches[1];
        } elseif (preg_match('/TBEACON.*symbol="([^"]+)".*comment="([^"]+)"(?:.*commentcmd="([^"]*)")?/', $line, $matches)) {
            $symbol  = $matches[1];
            $comment = $matches[2];
            $temp    = (isset($matches[3])) ? (preg_match('/tempc/', $matches[3]) ? 2 : 1) : 0;
        } elseif (preg_match('/KISSCOPY (\S+)/', $line, $matches)) {
            $report = $matches[1];
        }
    }
    $aprsForm .= $dynamicData;
    $aprsForm .= '</div>
      </div>
 </div>
 </div>
<div class="alert alert-warning" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Important :</strong> Pour utiliser le système APRS, il faut <strong>absolument un dongle GPS connecté</strong>.
</div>
<div class="card mb-2">
    <h4 class="card-header fs-5">Configuration</h4>
    <div class="card-body">
        <div class="input-group input-group-sm mb-1">
            <span data-bs-toggle="tooltip" title="Gére le service Direwolf, qui s’occupe de l’envoi des données GPS vers APRS-IS." class="input-group-text" style="width: 8rem;">Direwolf</span>
            <select id="aprs_service" class="form-select">
                <option value="0"' . (($svcDirewolf == 'inactive') ? ' selected' : null) . '>Désactivé</option>
                <option value="1"' . (($svcDirewolf == 'active') ? ' selected' : null) . '>Activé</option>
            </select>
        </div>
        <div class="input-group input-group-sm mb-1">
            <span data-bs-toggle="tooltip" title="Utilisez un indicatif valide avec un suffixe approprié. Le mot de passe sera généré automatiquement." class="input-group-text" style="width: 8rem;">Indicatif</span>
            <input id="aprs_callsign" type="text" class="form-control" placeholder="FRXXX-15" aria-label="Callsign" aria-describedby="inputGroup-sizing-sm" value="' . $callsign . '">
        </div>
        <div class="input-group input-group-sm mb-1">
            <span data-bs-toggle="tooltip" title="Commentaire ou son statut." class="input-group-text" style="width: 8rem;">Comment</span>
            <input id="aprs_comment" type="text" class="form-control" placeholder="Node RNFA" aria-label="Comment" aria-describedby="inputGroup-sizing-sm" value="' . $comment . '">
        </div>
        <div class="input-group input-group-sm mb-1">
            <span data-bs-toggle="tooltip" title="Choisissez si vous voulez inclure la lecture de la température du CPU à la fin de votre commentaire. Sélectionnez. <b>Yes (compensated)</b> ajoutera +38°C au résultat, ce qui est requis pour le SoC H2+ de Orange Pi Zero." class="input-group-text" style="width: 8rem;">Temp CPU</span>
            <select id="aprs_temp" class="form-select">
                <option value="0"' . (($temp == 0) ? ' selected' : null) . '>Non</option>
                <option value="1"' . (($temp == 1) ? ' selected' : null) . '>Oui</option>
                <option value="2"' . (($temp == 2) ? ' selected' : null) . '>Oui (compensated)</option>
            </select>
        </div>
        <div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 8rem;">Symbole</span>
            <select id="aprs_symbol" class="form-select">';
        $symbols = array(
        '/['     => 'Person',
        '\b'     => 'Bike',
        '/<'     => 'Motorcycle',
        '/>'     => 'Car',
        '/k'     => 'Truck',
        '\k'     => 'SUV',
        '\j'     => 'Jeep',
		'/b'   => 'Boat',
		'/p'   => 'Plane',
        '/-'     => 'House',
    );
    foreach ($symbols as $sym => $name) {
        $selected = ($symbol == $sym) ? 'selected' : '';
        $aprsForm .= "<option value=\"$sym\" $selected>$name</option>" . PHP_EOL;
    }
    $aprsForm .= '</select>
        </div>
        <div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 8rem;">Serveur</span>
            <select id="aprs_server" class="form-select">';
    $servers = array(
        'FranceAprs'       => 'franceaprs.zapto.org:10151',
		'CbAprs'       => 'cbaprs.at:27235',
		'Worldwide'       => 'rotate.aprs2.net',
        'Europe / Africa' => 'euro.aprs2.net',
        'North America'   => 'noam.aprs2.net',
        'South America'   => 'soam.aprs2.net',
        'Asia'            => 'asia.aprs2.net',
        'Oceania'         => 'aunz.aprs2.net',
    );
    foreach ($servers as $label => $value) {
        $selected = ($server == $value) ? 'selected' : '';
        $aprsForm .= "<option value=\"$value\" $selected>$label</option>" . PHP_EOL;
    }
    $aprsForm .= '</select>
        </div>
        <div class="input-group input-group-sm mb-1">
            <span data-bs-toggle="tooltip" title="Indiquez si vous souhaitez notifier le serveur (réflecteur) de votre utilisation du service GPS." class="input-group-text" style="width: 9rem;">Rapport de position</span>
            <select id="aprs_report" class="form-select">
                <option value="0"' . ((!$report) ? ' selected' : null) . '>Non</option>
                <option value="1"' . (($report) ? ' selected' : null) . '>Oui</option>
            </select>
        </div>
        <div class="d-flex justify-content-center mx-2">
            <button id="saveaprscfg" type="submit" class="btn btn-danger btn-lg m-2">Sauvegarder</button>
        </div>
    </div>
</div>';
    $aprsForm .= '<script>
    var auto_refresh = setInterval( function () {
        $("#dynamicData").load("includes/forms.php?gpsStatus");
    }, 30000);
    </script>' . PHP_EOL;
    return $aprsForm;
}

/* Logs */
function logsForm()
{
    $env = checkEnvironment();
    if ($env) {
        return $env;
    }

    $logData = '<h4 class="mt-2 alert alert-dark fw-bold">Logs</h4>';
    $logData .= '<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-12">
            <div class="card bg-light shadow border-0">
                <div class="card-header bg-white">
                    <img id="new_log_line" src="assets/img/new.svg" alt="received" style="display:none;">
                    <div id="log_selector">
                        <select id="log">
                            <option value="" disabled>-Log file-</option>
							<option value="1" selected>Syslog</option>
                            <option value="2">RNFA</option>
							
                        </select>
                    </div>
                </div>
                <div class="card-body px-lg-3 py-lg-2 scrolog">
                    <div class="small" id="log_data" style="height:65vh;overflow:auto"></div>
                </div>
            </div>
        </div>
    </div>
</div>';
    return $logData;
}

/* Terminal */
function ttyForm()
{
    $env = checkEnvironment();
    if ($env) {
        return $env;
    }

    $ttydService = '/lib/systemd/system/ttyd.service';
    if (!is_file($ttydService)) {
        return '<div class="alert alert-danger text-center" role="alert">ttyd package not installed</div>';
    }

    $host     = parse_url($_SERVER['HTTP_HOST']);
    $host     = (empty($host['host']) ? $_SERVER['HTTP_HOST'] : $host['host']);
    $ttyFrame = '<h4 class="mt-2 alert alert-primary fw-bold">Terminal</h4>';
    
    // ✅ Ajout du style CSS pour l'animation clignotante (texte uniquement)
    $ttyFrame .= '<style>
        @keyframes blink-text-red-green {
            0%   { color: #dc3545; }
            50%  { color: #198754; }
            100% { color: #dc3545; }
        }
        .blink-text {
            animation: blink-text-red-green 1s infinite;
        }
    </style>';
    
    $ttyFrame .= '<div class="alert alert-secondary py-2 mb-2">';
    $ttyFrame .= 'Login: <strong class="blink-text">root</strong> | ';
    $ttyFrame .= 'Passwd: <strong class="blink-text">1234</strong> ';
    $ttyFrame .= '<span class="badge bg-warning text-dark ms-2" style="cursor: help;" ';
    $ttyFrame .= 'data-bs-toggle="tooltip" data-bs-placement="top" ';
    $ttyFrame .= 'title="Pour changer le mot de passe, exécutez :<br><code>passwd</code><br>dans le terminal ci-dessous">';
    $ttyFrame .= '⚠️ Changez le mot de passe !</span>';
    $ttyFrame .= '</div>';
    
    $ttyFrame .= '<div class="row">
        <div class="col-lg-12">
            <div class="card bg-light shadow border-0">
                <div class="card-body px-lg-3 py-lg-2">
                    <iframe style="height:65vh;overflow:auto" class="col-lg-12 col-md-12 col-sm-12 embed-responsive-item" src="//' . $host . ':8080"></iframe>
                </div>
            </div>
        </div>
    </div>';
    return $ttyFrame;
}

/* Node info */
function nodForm()
{
    $env = checkEnvironment();
    if ($env) return $env;

    $nodData = '<h4 class="mt-2 alert alert-dark fw-bold">Node info</h4>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-12">
                <iframe id="nodFrame" 
                        src="ajax/nod.php" 
                        style="width:100%; height:75vh; border:none; overflow:auto; background:#f8f9fa;"
                        frameborder="0"
                        onload="this.style.opacity=\'1\'"
                        onerror="this.contentDocument.body.innerHTML=\'<div class=\\"alert alert-danger p-4 text-center\\"><strong>Erreur ajax/nod.php</strong><br>Vérifiez les logs serveur</div>\'">
                </iframe>
            </div>
        </div>
    </div>';
    return $nodData;
}


/* Config */
function cfgForm()
{
    $env = checkEnvironment();
    if ($env) {
        return $env;
    }

    global $pinsArray, $config;
    $ttysArray = array(1, 2, 3);
    $version   = version();
    if ($version && $version['date'] >= 20230126) {
        $saDetect = sa8x8Detect();
    }

    $statusPageItems = array(
        'cfgHostname'   => 'Hostname',
        'cfgUptime'     => 'Online depuis',
        'cfgCpuStats'   => 'CPU Stats',
        'cfgNetworking' => 'Networking',
        'cfgSsid'       => 'Info Wi-Fi',
        'cfgPublicIp'   => 'IP externe',
        'cfgSvxStatus'  => 'Statut SVXLink',
        'cfgRefNodes'   => 'Nodes connectés',
       // 'cfgCallsign'   => 'Indicatif',
        'cfgDTMF'       => 'DTMF Sender',
        'cfgKernel'     => 'Kernel version',
      // 'cfgDetectSa'   => 'Détection du SA818',
        'cfgFreeSpace'  => 'Espace libre',
        'cfgTempOffset' => 'Température du processeur',
    );

    // Get mixer's current values
    exec('/usr/bin/sudo /usr/bin/amixer get \'Line Out\' | grep -Po \'(?<=(\[)).*(?=\%\])\' | head -1', $mixerGetLineOut);
    exec('/usr/bin/sudo /usr/bin/amixer get \'DAC\' | grep -Po \'(?<=(\[)).*(?=\%\])\' | head -1', $mixerGetDAC);
    exec('/usr/bin/sudo /usr/bin/amixer get \'Mic1 Boost\' | grep -Po \'(?<=(\[)).*(?=\%\])\' | head -1', $mixerGetMic1Boost);
    exec('/usr/bin/sudo /usr/bin/amixer get \'ADC Gain\' | grep -Po \'(?<=(\[)).*(?=\%\])\' | head -1', $mixerGetADCGain);

    $configData = '<h4 class="mt-2 alert alert-warning fw-bold">Configuration</h4>
    <div class="card m-1">
        <h4 class="m-2">Série et GPIO</h4>
        <div class="form-floating m-2">
            <select id="cfgPttPin" class="form-select" aria-label="Pin GPIO (PTT)">' . PHP_EOL;
    foreach ($pinsArray as $pin) {
        $configData .= '<option value="' . $pin . '"' . ($pin == $config['cfgPttPin'] ? ' selected' : '') . '>' . $pin . '</option>' . PHP_EOL;
    }
    $configData .= '</select>
        <label for="cfgPttPin">Pin GPIO (PTT)</label>
        </div>' . PHP_EOL;
    $configData .= '<div class="form-floating m-2">
                <select id="cfgTty" class="form-select" aria-label="Port série (ttyS)">' . PHP_EOL;
    foreach ($ttysArray as $tty) {
        $ttyDetails = null;
        if ($saDetect != null && (int) $tty == (int) $saDetect['port']) {
            $ttyDetails = ' (found ' . $saDetect['version'] . ')';
        }
        $configData .= '<option value="' . $tty . '"' . ($tty == $config['cfgTty'] ? ' selected' : '') . '>' . $tty . $ttyDetails . '</option>' . PHP_EOL;
    }
    $configData .= '</select>
        <label for="cfgTty">Port série (ttyS)</label>
    </div>
    <h4 class="m-2">Système</h4>
    <div class="form-floating m-2">
        <select id="timezone" class="form-select" aria-label="Time Zone">' . PHP_EOL;
    $tz = file('./assets/timezones.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($tz as $timezone) {
        $configData .= '<option value="' . $timezone . '"' . ($timezone == trim(file_get_contents('/etc/timezone')) ? ' selected' : '') . '>' . $timezone . '</option>' . PHP_EOL;
    }
    $configData .= '</select>
        <label for="timezone">Fuseau horaire</label>
    </div>
    <div class="form-floating m-2">
        <select id="cfgAutoConnect" name="cfgAutoConnect" class="form-select" aria-label="Auto connect">' . PHP_EOL;
    $configData .= '<option ' . (($config['cfgAutoConnect'] == 'true') ? 'selected' : null) . ' value="true">Oui</option>
                        <option ' . (($config['cfgAutoConnect'] == 'false') ? 'selected' : null) . ' value="false">Non</option>';
    $configData .= '</select>
        <label for="cfgAutoConnect">Connexion automatique lors du changement de profil</label>
    </div>
    <div class="form-floating m-2">
        <input id="accessPassword" type="text" class="form-control" aria-label="Password"';
    $label = null;
    $password = dashPassword("get");
    if (empty($password)) {
        $configData .= ' placeholder=""';
        $label = ' (n’a pas été défini)';
    } else {
        $configData .= ' value="' . $password . '"';
    }
    $configData .= '>
        <label for="accessPassword">Mot de passe du Dashboard' . $label . '</label>
    </div>
    <h4 class="m-2">Etat de la page</h4>
    <div class="row form-floating m-2">' . PHP_EOL;
    foreach ($statusPageItems as $cfgName => $cfgTitle) {
        $configData .= '<div class="form-check col col-lg-2 m-3">
            <input class="form-check-input" type="checkbox" id="' . $cfgName . '"' . ($config[$cfgName] == 'true' ? ' checked' : '') . '>
            <label class="form-check-label" for="' . $cfgName . '">' . $cfgTitle . '</label>
        </div>' . PHP_EOL;
    }
    $configData .= '</div>
<h4 class="m-2">Contrôle audio</h4>
<div class="row m-3">
    <p class="lead">Sortie</p>
    <div class="col-sm-3">
        <div class="d-flex flex-column">
            <label for="vac_out">Sortie du volume<span class="mx-2" id="vac_outcv">(' . $mixerGetLineOut[0] . '%)</span></label>
            <input type="range" min="6" max="100" step="3" class="form-control-range" id="vac_out" value="' . $mixerGetLineOut[0] . '">
        </div>
    </div>
    <div class="col-sm-3">
        <div class="d-flex flex-column">
            <label for="vac_dac">DAC<span class="mx-2" id="vac_daccv">(' . $mixerGetDAC[0] . '%)</span></label>
            <input type="range" min="0" max="100" step="2" class="form-control-range" id="vac_dac" value="' . $mixerGetDAC[0] . '">
        </div>
    </div>
</div>
<div class="row m-3">
    <p class="lead">Entrer</p>
    <div class="col-sm-3">
        <div class="d-flex flex-column">
            <label for="vac_mb">Mic1 Boost<span class="mx-2" id="vac_mbcv">(' . $mixerGetMic1Boost[0] . '%)</span></label>
            <input type="range" min="0" max="100" step="10" class="form-control-range" id="vac_mb" value="' . $mixerGetMic1Boost[0] . '" disabled>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="d-flex flex-column">
            <label for="vac_adc">ADC Gain<span class="mx-2" id="vac_adccv">(' . $mixerGetADCGain[0] . '%)</span></label>
            <input type="range" min="0" max="100" step="10" class="form-control-range" id="vac_adc" value="' . $mixerGetADCGain[0] . '">
        </div>
    </div>
</div>
        <div class="alert alert-info m-2 p-1" role="alert">Remarque : Ajuster les curseurs a un effet immédiat !</div>
</div>
    <div class="d-flex justify-content-center mt-4">
        <button id="cfgSave" type="button" class="btn btn-danger btn-lg mx-2">Sauvegarder</button>';
    if ($version) {
        $isOnline = checkdnsrr('google.com');
        // Check if RoLink version is capable of updates and if we're connected to the internet
        //if ($version['date'] > 20211204 && $isOnline) {
  //          $configData .= '<button id="updateDash" type="button" class="btn btn-primary btn-lg mx-2"> Mettre à jour le Dashboard</button>';
 //$configData .= '<a href="/includes/update-dash.php" class="btn btn-primary btn-lg mx-2" onclick="return confirm(\'Confirmez-vous la mise à jour du Dashboard ?\');">Mettre à jour le Dashboard</a>';
 //           $configData .= '<button id="updateRoLink" type="button" class="btn btn-warning btn-lg mx-2">RNFA update</button>';
 //       }
        $configData .= ($isOnline) ? null : '<button type="button" class="btn btn-dark btn-lg mx-2">Pas d’accès à Internet</button>';
    }
	

    // Show "Make Read-only" button
    if (!preg_match('/ro,ro/', file_get_contents('/etc/fstab'))) {
        $configData .= '</div><div class="d-flex justify-content-center m-2"><button id="makeRO" type="button" class="btn btn-dark btn-lg">Make FS Read-Only</button>';
    }
    $configData .= '</div>' . PHP_EOL;
    return $configData;
}

