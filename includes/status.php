<?php
/*
 *   RoLinkX Dashboard v3.68
 *   Copyright (C) 2025 by Razvan Marin YO6NAM / www.xpander.ro
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
 * Status reporting module
 */

if (isset($_GET['svxStatus'])) {
    echo getSVXLinkStatus();
}

if (isset($_GET['svxReflector'])) {
    echo getReflector(1);
}

if (isset($_GET['cpuData'])) {
    echo getCpuStats(1);
}

if (isset($_GET['gpio'])) {
    echo gpioStatus(1);
}

if (isset($_GET['room'])) {
    echo roomForm();
}

/* GPS dongle */
function getGPSDongle()
{
    $vidPidList      = array("1546:01a6", "1546:01a7", "067b:aaa0");
    $usbDevices      = shell_exec('lsusb');
    $usbDevicesArray = explode("\n", $usbDevices);
    $detected        = false;
    $status          = null;
    $gps             = 'Pas de dongle';
    $toggle          = 'class="input-group-text"';
    foreach ($vidPidList as $vidPid) {
        foreach ($usbDevicesArray as $line) {
            if (strpos($line, $vidPid) !== false) {
                if (preg_match('/' . preg_quote($vidPid, '/') . ' (.+)/', $line, $matches)) {
                    $detected = true;
                    $gpsName  = trim($matches[1]);
                }
            }
        }
    }
    if ($detected) {
        $status = 'background:lightgreen';
        $toggle = 'class="input-group-text collapsed dropdown-toggle" role="button"';
        $gps    = 'Dongle connecté';
    }
    $data = '<div class="input-group mb-2">
        <span ' . $toggle . ' data-bs-toggle="collapse" data-bs-target="#gps" aria-expanded="false" aria-controls="gps" style="width: 6.5rem;' . $status . '">GPS</span>
        <input type="text" class="form-control" placeholder="' . $gps . '" readonly>
    </div>';
    $data .= ($detected) ? '<div id="gps" class="accordion-collapse collapse">
            <div class="accordion-body">
                <div class="input-group mb-1">
                <span class="input-group-text">Device</span>
                <span class="input-group-text">' . $gpsName . '</span>
            </div>
        </div>
    </div>' : null;
    return $data;
}

/* GPIO Status(es)*/
function gpioStatus($ajax = 0)
{
    $rxGPIO  = 'gpio10';
    $txGPIO  = 'gpio7';
    $cfgFile = '/opt/rolink/conf/rolink.conf';
    if (is_file($cfgFile)) {
        $data = file_get_contents($cfgFile);
        preg_match('/(GPIO_SQL_PIN=)(\S+)/', $data, $rx);
        preg_match('/(PTT_PIN=)(\S+)/', $data, $tx);
		
        // Check if the PCB board is supported
        if ($rx[2] != $rxGPIO || $tx[2] != $txGPIO) {
            return;
        }

    } else {
        return;
    }
    $gpioPaths = array(
        'rx'  => '/sys/class/gpio/' . $rxGPIO . '/value',
        'tx'  => '/sys/class/gpio/' . $txGPIO . '/value',
        'fan' => '/sys/class/gpio/gpio6/value',
    );
    $data = array();
    foreach ($gpioPaths as $key => $path) {
        $value      = trim(exec('/usr/bin/cat ' . $path));
        $data[$key] = ($value === '0' || $value === '1') ? $value : '0';
    }
    $jsonData = json_encode($data);
    if ($ajax) {
        return $jsonData;
    }
    return '<div class="input-group mb-2">
        <span class="input-group-text" style="width: 6.5rem;">Statut GPIO</span>
        <input id="gpioRx" type="text" class="form-control text-center" placeholder="..." readonly>
        <input id="gpioTx" type="text" class="form-control text-center" placeholder="..." readonly>
        <input id="gpioFan" type="text" class="form-control text-center" placeholder="..." readonly>
    </div>';
}

/* Get IP(s) */
function networking()
{
    $interfaces = array(
        'eth0'  => 'LAN',
        'wlan0' => 'WLAN',
        'usb0'  => '4G/LTE',
        'ppp0'  => 'PPTP',
    );
    $returnData = '';
    foreach ($interfaces as $interface => $name) {
        $data = [];
        exec("/usr/bin/ip addr show dev $interface 2>/dev/null| /usr/bin/grep 'inet' | /usr/bin/grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' | head -n 1", $data);
        $ip = (empty($data) || preg_match('/^169\.254/', $data[0]) !== 0) ? '' : $data[0];
        if (!empty($ip)) {
            $returnData .= '<div class="input-group mb-2">
                <span class="input-group-text" style="width: 6.5rem;">' . $name . ' IP</span>
                <input type="text" class="form-control" placeholder="' . $ip . '" readonly>
            </div>';
        }
    }
    return $returnData;
}



/* Get Hostname */
function hostName()
{
    return '<div class="input-group mb-2">
        <button data-bs-toggle="tooltip" title="Cliquez pour changer le nom d’hôte actuel en la valeur déclarée sur la page SVXLink sous <b>Indicatif (Balise)</b>﻿" class="btn btn-dark" style="width: 6.5rem;" type="button" id="switchHostName">Host Name</button>
        <input type="text" class="form-control" placeholder="' . gethostname() . '" readonly>
    </div>';
}

/* Uptime */
function getUpTime()
{
    exec("/usr/bin/uptime -p", $reply);
    if (empty($reply)) {
        $result = 'Indisponible';
    } else {
        // Retire le "up " au début
        $uptime = preg_replace('/^up\s*/', '', $reply[0]);

        // Traduction rapide anglais -> français
        $trads = [
            'hour' => 'heure',
            'hours' => 'heures',
            'minute' => 'minute',
            'minutes' => 'minutes',
            'day' => 'jour',
            'days' => 'jours',
            'week' => 'semaine',
            'weeks' => 'semaines'
        ];

        // Pour gérer la casse, on remplace en insensible à la casse
        $uptime_fr = str_ireplace(array_keys($trads), array_values($trads), $uptime);

        $result = $uptime_fr;
    }

    return '<div class="input-group mb-2">
        <span class="input-group-text" style="width: 6.5rem;">Connecté<br>depuis</span>
        <input type="text" class="form-control" placeholder="' . htmlspecialchars($result, ENT_QUOTES) . '" readonly>
    </div>';
}

/* CPU Load & Temp */
function getCpuStats($ajax = 0)
{
    $config      = include __DIR__ . '/../config.php';
    $avgLoad     = $cpuTemp     = '...';
    $tempWarning = null;
    if ($ajax) {
        $cpuLoad     = getServerLoad();
        $avgLoad     = (is_null($cpuLoad)) ? 'N/A' : number_format($cpuLoad, 2) . "%";
        $thermalZone = file('/sys/devices/virtual/thermal/thermal_zone0/temp', FILE_IGNORE_NEW_LINES);
        $rawTemp     = ($config['cfgTempOffset'] == 'true') ? $thermalZone[0] + 38000 : $thermalZone[0];
        $cpuTemp     = substr($rawTemp, 0, -3);
        $tempWarning = ($cpuTemp > 70) ? 'bg-warning text-dark' : '';
        $svxState    = getSVXLinkStatus(1);
        return json_encode(array($avgLoad, $cpuTemp . '℃', $tempWarning, $svxState));
    }
    return '<div class="input-group mb-2">
        <span class="input-group-text" style="width: 6.5rem;">CPU</span>
        <input id="cpuLoad" type="text" class="form-control text-center" placeholder="' . $avgLoad . '" readonly>
        <input id="cpuTemp" type="text" class="form-control text-center ' . $tempWarning . '" placeholder="' . $cpuTemp . '" readonly>
    </div>';
}

function getServerLoad()
{
    if (is_readable("/proc/stat")) {
        $statData1 = $statData2 = null;
        for ($i = 0; $i < 2; $i++) {
            $stats = file_get_contents("/proc/stat");
            if ($stats !== false) {
                $stats = preg_replace("/[[:blank:]]+/", " ", $stats);
                $stats = str_replace(["\r\n", "\n\r", "\r"], "\n", $stats);
                $stats = explode("\n", $stats);
                foreach ($stats as $statLine) {
                    $statLineData = explode(" ", trim($statLine));
                    if (count($statLineData) >= 5 && $statLineData[0] === "cpu") {
                        if ($i === 0) {
                            $statData1 = array_slice($statLineData, 1, 4);
                        } else {
                            $statData2 = array_slice($statLineData, 1, 4);
                        }
                        break;
                    }
                }
            }
            sleep(1);
        }
        if (!is_null($statData1) && !is_null($statData2)) {
            $delta = array_map(function ($a, $b) {
                return $b - $a;
            }, $statData1, $statData2);
            $cpuTime = array_sum($delta);
            return 100 - ($delta[3] * 100 / $cpuTime);
        }
    }
    return null;
}

/* Retreive SSID (if connected) */
function getSSID()
{
    exec('/sbin/iwgetid --raw', $reply);
    if (isset($reply[0])) {
        $wifiStatus = $reply[0];
        $wifiMode   = 'WLAN SSID';
    } else {
        #exec('/usr/bin/systemctl is-active hostapd', $mode);
        #$wifiStatus = ($mode[0] == 'active') ? 'Hotspot' : 'Not associated';
        # ToDo : AutoHotspot
        $wifiStatus = 'Non associé';
        $wifiMode   = 'Mode Wi-Fi';
    }
    return '<div class="input-group mb-2">
        <span class="input-group-text" style="width: 6.5rem;">' . $wifiMode . '</span>
        <input type="text" class="form-control" placeholder="' . $wifiStatus . '" readonly>
    </div>';
}

/* Get Public IP */
function getPublicIP()
{
    $ip     = 'Not available';
    $status = 'color:white;background:red';
    $gotIP  = getExtIp();
    if ($gotIP) {
        $ip     = $gotIP;
        $status = 'background:lightgreen';
    }

    $data = '<div class="input-group mb-2">
        <span class="input-group-text" style="width: 6.5rem;' . $status . '">IP externe</span>
        <input type="text" class="form-control" placeholder="' . $ip . '" readonly>
    </div>';

    return $data;
}

/* Get SVXLink status */
function getSVXLinkStatus($ext = 0)
{
    $config = include __DIR__ . '/../config.php';
    exec("/usr/bin/pgrep svxlink", $reply);
    if ($ext == 1) {
        return ((empty($reply)) ? false : $reply[0]);
    }

    $result      = (empty($reply)) ? 'Not running' : 'Running (' . $reply[0] . ')';
    $status      = (empty($reply)) ? 'width:6.5rem;' : 'width:6.5rem;background:lightgreen;';
    $dtmfTrigger = ($config['cfgDTMF'] == 'true' && $result != 'Not running') ? '<span data-bs-toggle="tooltip" title="Cliquez pour envoyer les données depuis le <b>Outil d envoi DTMF</b> et envoyer des commandes à SVXLink. Utile lorsque vous\'Je n ai pas de radio avec la fonction DTMF."><button id="dtmf" data-bs-toggle="modal" data-bs-target="#dtmfModal" class="input-group-text btn btn-secondary" type="button">#</button></span>' : null;
    return '<div class="input-group mb-2">
        <span class="input-group-text" style="' . $status . '">SVXLink</span>
        <input id="svxStatus" type="text" class="form-control" placeholder="' . $result . '" readonly>'
        . $dtmfTrigger .
        '</div>';
}

/* Obtenir l'adresse du Reflecteur */
function getReflector($ext = 0)
{
    $config  = include __DIR__ . '/../config.php';
    $cfgFile = '/opt/rolink/conf/rolink.conf';
    
    if (is_file($cfgFile)) {
        preg_match('/HOST=(\S+)/', file_get_contents($cfgFile), $reply);
    }
    $refHost = (!empty($reply)) ? $reply[1] : 'Non disponible';
    
    // ✅ VÉRIFICATION RÉELLE : Est-ce qu'il y a une connexion TCP ESTABLISHED vers le reflector ?
    $refIp = $refHost;
    exec("/usr/bin/netstat -an 2>/dev/null | /usr/bin/grep ':5300' | /usr/bin/grep -c 'ESTABLISHED'", $reply);
    $isConnected = (!empty($reply[0]) && $reply[0] > 0);
    
    // Alternative avec ss (plus moderne)
    if (!$isConnected) {
        exec("/usr/bin/ss -tn 2>/dev/null | /usr/bin/grep -c 'ESTAB.*:5300'", $reply);
        $isConnected = (!empty($reply[0]) && $reply[0] > 0);
    }
    
    $stateColor = $isConnected ? 'background:lightgreen;' : 'background:tomato;';
    $refHost    = $isConnected ? 'Connecté' : 'Déconnecté';
    
    $showNodes = ($config['cfgRefNodes'] == 'true' && $isConnected) ? 
        ' collapsed dropdown-toggle" role="button" data-bs-toggle="collapse" data-bs-target="#refStations" aria-expanded="false" aria-controls="refStations"' : '"';
    
    return '<div class="input-group mb-2">
        <span class="input-group-text' . $showNodes . ' style="width: 6.5rem;' . $stateColor . '">Reflecteur</span>
        <input type="text" class="form-control" placeholder="' . htmlspecialchars($refHost) . '" readonly>
    </div>';
}

/* Get Reflector connected nodes */
function getRefNodes()
{
    $status = getSVXLinkStatus(1);
    if (!$status) {
        return false;
    }

    $logFilePath       = '/tmp/svxlink.log';
    $baseCacheFileName = '/tmp/nodes_cache_' . $status . '.txt';
    if (file_exists($baseCacheFileName)) {
        return file_get_contents($baseCacheFileName);
    }
    // Delete other cache files
    foreach (glob('/tmp/nodes_cache_*.txt') as $file) {
        if ($file !== $baseCacheFileName) {
            unlink($file);
        }
    }
    $logLines = file($logFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($logLines)) {
        return false;
    }
    $stationHTML = '<div id="refStations" class="accordion-collapse collapse">
        <div class="accordion-body">
            <div class="row">' . PHP_EOL;
    foreach ($logLines as $line) {
        if (preg_match('/Connected nodes:\s(.*)/', $line, $matches)) {
            $nodes = explode(', ', $matches[1]);
            natsort($nodes);
            foreach ($nodes as $node) {
                $typeBackground = 'danger';
                if (strpos($node, '-P') !== false) {
                    $typeBackground = 'primary';
                }

                if (strpos($node, '-M') !== false) {
                    $typeBackground = 'warning';
                }

                $stationHTML .= '<div class="col col-lg-2 badge badge-' . $typeBackground . ' m-1" style="font-weight: 400;">' . $node . '</div>' . PHP_EOL;
            }
            break;
        }
    }
    $stationHTML .= '</div>
        </div>
        </div>' . PHP_EOL;
    file_put_contents($baseCacheFileName, $stationHTML);
    return $stationHTML;
}

/* Get SVX Callsign    */
function getCallSign()
{
    global $cfgFile;
    if (is_file($cfgFile)) {
        preg_match('/(CALLSIGN=")(\S+)"/', file_get_contents($cfgFile), $reply);
    }
    $callsign = (!empty($reply)) ? $reply[2] : 'Not available';
    return '<div class="input-group mb-2">
        <span class="input-group-text" style="width: 6.5rem;">Indicatif</span>
        <input type="text" class="form-control" placeholder="' . $callsign . '" readonly>
    </div>';
}

/* Get free space */
function getFreeSpace()
{
    $expand = null;
    $status = 'background:lightgreen';
    $bytes  = disk_free_space('/');
    $suffix = array('B', 'KB', 'MB', 'GB');
    $scale  = min((int) log($bytes, 1024), count($suffix) - 1);
    $space  = sprintf('%1.2f', $bytes / pow(1024, $scale)) . ' ' . $suffix[$scale];
    if ($bytes < 104857600) {
        $status = 'background:red;color:white';
    } elseif ($bytes < 262144000) {
        $status = 'background:orange;color:white';
    }
    /* Determine if it's an image based install */
    exec('/usr/bin/df /', $drive);
    preg_match('/mmcblk0p1\s+(\d+)/m', $drive[1], $size);
    if ($size[1] < 3145728) {
        $status = 'background:yellow;color:black';
        $expand = '<button type="button" id="expandFS" class="btn btn-danger">&#8633;</button>';
    }
    return '<div class="input-group mb-2">
        <span class="input-group-text" style="width: 6.5rem;' . $status . '">Espace libre</span>
        <input type="text" class="form-control" placeholder="' . $space . '" readonly>
        ' . $expand . '
    </div>';
}

/* Get kernel & release version */
function getKernel()
{
    preg_match('/VERSION_CODENAME=(\S+)/', file_get_contents('/etc/os-release'), $reply);
    $kernel = str_replace(['-sunxi', '-current'], '', posix_uname()['release']);
    return '<div class="input-group mb-2">
        <span class="input-group-text" style="width: 6.5rem;">Kernel</span>
        <input type="text" class="form-control" placeholder="' . $kernel . ' (' . $reply[1] . ')" readonly>
    </div>';
}

/* File System status */
function getFileSystem()
{
    if (!preg_match('/ro,ro/', file_get_contents('/etc/fstab'))) {
        return;
    }

    exec('/usr/bin/cat /proc/mounts | grep -Po \'(?<=(ext4\s)).*(?=,noatime)\'', $fileSystemStatus);
    $stateFS      = ($fileSystemStatus[0] == 'rw') ? 'Read/Write' : 'Read-only';
    $stateFSColor = ($fileSystemStatus[0] == 'rw') ? 'background:red;color: white;' : 'background:lightgreen;';
    return '<div class="input-group mb-2">
        <button data-bs-toggle="tooltip" title="Cliquez pour passer de l’état <b>Lecture seule</b> à l’état <b>Lecture/Écriture﻿" class="btn" style="' . $stateFSColor . 'width: 6.5rem;" value="' . $fileSystemStatus[0] . '" type="button" id="changeFS">Système des fichiers</button>
        <input type="text" class="form-control" placeholder="' . $stateFS . '" readonly>
    </div>';
}

/* Version check */
function getRemoteVersion()
{
    global $remoteVerUrl;

    $version = version();
    if (!$version) {
        return;
    }

    $remoteData    = false;
    $cachedVersion = (isset($_COOKIE["remote_version"])) ? (int) $_COOKIE["remote_version"] : false;
    $notify        = 'width: 6.5rem';
    if ($cachedVersion) {
        $result = ($cachedVersion > $version['date']) ? 'Update available (' . $cachedVersion . ')' : $version['number'] . ' (' . $version['date'] . ')';
        $notify = ($cachedVersion > $version['date']) ? 'width:6.5rem;border-left-width:thick;border-left-color:red' : $notify;
    } else {
        $gotIP = getExtIp();
        if ($gotIP) {
            $remoteData = file_get_contents($remoteVerUrl);
        }
    }
    if ($remoteData) {
        $remoteVersion = explode('|', $remoteData);
        $result        = ((int) $remoteVersion[0] > $version['date']) ? 'Update available (' . $remoteVersion[0] . ')' : $version['number'] . ' (' . $version['date'] . ')';
        $notify        = ((int) $remoteVersion[0] > $version['date']) ? 'width:6.5rem;border-left-width:thick;border-left-color:red' : $notify;
        setcookie("remote_version", $remoteVersion[0], time() + 60 * 60 * 24);
    } elseif (!isset($result)) {
        $result = 'Unavailable';
    }
    return '<div class="input-group mb-2">
        <span data-bs-toggle="tooltip" title="Local version : ' . $version['date'] . '</br>Remote version : ' .
        (isset($remoteVersion[0]) ? $remoteVersion[0] : $cachedVersion) . '" class="input-group-text" style="' . $notify . '">Version</span>
        <input type="text" class="form-control" placeholder="' . $result . '" readonly>
    </div>';
}

/* DTMF commands sender */
function dtmfSender()
{
    return '<div class="modal fade" id="dtmfModal" tabindex="-1" aria-labelledby="dtmfModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dtmfModalLabel">Émetteur DTMF</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group flex-nowrap">
                    <span data-bs-toggle="tooltip" title="Commandes DTMF à envoyer à SVXLink." class="input-group-text" id="addon-wrapping">Commande:</span>
                    <input type="tel" id="dtmfCommand" class="form-control" aria-label="Command" aria-describedby="addon-wrapping">
                </div>
                <div class="alert alert-success m-1" id="dtmfConsole" role="alert" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <div class="col">
                    <button data-bs-toggle="tooltip" title="Enable the link and pass the audio to and from the reflector you are connected to." id="sendDTMF_EnableLink" type="button" class="btn btn-info mb-1" value="551#">Enable &#128279;</button>
                    <button data-bs-toggle="tooltip" title="Disable the link and stop audio to and from the reflector you are connected to." id="sendDTMF_DisableLink" type="button" class="btn btn-info mb-1" value="55#">Disable &#128279;</button>
                    <button data-bs-toggle="tooltip" title="Switch to Talk Group 59" id="sendDTMF_TG9" type="button" class="btn btn-info mb-1" value="55159#">TG#59</button>
                    <button data-bs-toggle="tooltip" title="Switch to Talk Group 62" id="sendDTMF_TG62" type="button" class="btn btn-info mb-1" value="55162#">TG#62</button>
                     <button data-bs-toggle="tooltip" title="Switch to Talk Group 100" id="sendDTMF_TG100" type="button" class="btn btn-info mb-1" value="551100#">TG#100</button>
					<button data-bs-toggle="tooltip" title="Enable the parrot and test your audio. <em>Note : After 60 seconds of inactivity the parrot will be disabled and audio will resume the normal flow." id="sendDTMF_ParrotOn" type="button" class="btn btn-info mb-1" value="1#">Parrot On</button>
                    <button data-bs-toggle="tooltip" title="Disable the parrot module (if already active)" id="sendDTMF_ParrotOff" type="button" class="btn btn-info mb-1" value="#">Parrot Off</button>
                </div>
                <button data-bs-toggle="tooltip" title="Cliquez pour envoyer les données depuis le <b>Command</b> input field" data-bs-placement="bottom" id="sendDTMF" type="button" class="btn btn-danger btn-lg">Send</button>
            </div>
        </div>
    </div>
</div>' . PHP_EOL;
}
