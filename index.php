<?php
/*
 *   RoLinkX Dashboard v4.7
 *   Copyright (C) 2025 - 2025 by Razvan Marin YO6NAM / FRS077 Romuald
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 */

/*
 * Index page
 */

$pages = array(
    "wifi",
    "svx",
    "sa",
    "log",
    "nod",
    "aprs",
    "tty",
    "cfg",
    "update",
    "update-run"
);

$page = (null !== filter_input(INPUT_GET, 'p', FILTER_SANITIZE_SPECIAL_CHARS))
    ? $_GET['p']
    : '';

// Common functions
include __DIR__ . '/includes/functions.php';

// Password protection
dashPassword('check');

// Events
$version = version();
$eventsData = 'var events=0';
$ajaxData = 'var auto_refresh = setInterval(function () { cpuData(); gpioStatus(); }, 3000);';

if ($version && $version['date'] > 20231120) {
    $ajaxData = '';
    $eventsData = 'var events=1; var timeOutTimer=180;';
}

// Detect mobiles
require_once __DIR__ . '/includes/Mobile_Detect.php';
$detect = new Mobile_Detect();

if (in_array($page, $pages, true)) {
    include __DIR__ . '/includes/forms.php';
} else {
    $config = include __DIR__ . '/config.php';
    include __DIR__ . '/includes/status.php';
}

$rolink = (isset($cfgFile) && is_file($cfgFile));

switch ($page) {
    case "wifi":
        $htmlOutput = wifiForm();
        break;

    case "svx":
        $htmlOutput = svxForm();
        break;

    case "sa":
        $htmlOutput = sa818Form();
        break;

    case "aprs":
        $htmlOutput = aprsForm();
        $extraResource = '
        <link href="https://cdn.jsdelivr.net/npm/ol@v8.1.0/ol.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/ol@v8.1.0/dist/ol.js"></script>';
        break;

    case "log":
        $htmlOutput = logsForm();
        break;

    case "tty":
        $htmlOutput = ttyForm();
        break;

    case "nod":
        $htmlOutput = nodForm();
        break;

    case "cfg":
        $htmlOutput = cfgForm();
        break;

    /*
     * Page de confirmation.
     * Aucun script n'est lancé à ce stade.
     */
    case "update":
        $htmlOutput = '
        <h4 class="m-2 mt-2 alert alert-info fw-bold">
            🔄 Mise à jour du dashboard
        </h4>

        <div class="card m-2">
            <div class="card-body">
                <p>
                    Cette action va télécharger la dernière version du dashboard
                    depuis GitHub, créer un backup de la version actuelle et
                    installer la nouvelle version.
                </p>

                <div class="alert alert-warning">
                    <strong>Attention :</strong> pendant le remplacement des fichiers,
                    le dashboard peut ne pas répondre pendant quelques secondes.
                    L’ancien site sera sauvegardé dans
                    <code>/var/www/html.old</code>.
                </div>

                <form method="post" action="./?p=update-run">
                    <div class="d-grid gap-2 col-md-7 mx-auto">
                        <button type="submit" class="btn btn-warning btn-lg">
                            🔄 Confirmer et lancer la mise à jour
                        </button>

                        <a href="./" class="btn btn-secondary">
                            Annuler
                        </a>
                    </div>
                </form>
            </div>
        </div>';

        $ajax = null;
        break;

    /*
     * Lance le script en tâche de fond.
     * La page reste disponible pour afficher l'avancement.
     */
    case "update-run":
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ./?p=update');
            exit;
        }

        $updateScript = '/usr/local/bin/update_dash_html.sh';
        $logFile = '/var/log/update_dash_html.log';
        $statusFile = '/tmp/dash_hotlin_update.status';

        /*
         * Supprime l'ancien statut pour éviter d'afficher 100 %
         * au lancement d'une nouvelle mise à jour.
         */
        if (is_file($statusFile)) {
            @unlink($statusFile);
        }

        /*
         * IMPORTANT :
         * La règle sudoers doit autoriser www-data à exécuter ce fichier
         * sans mot de passe :
         *
         * www-data ALL=(root) NOPASSWD: /usr/local/bin/update_dash_html.sh
         */
        $command = 'sudo -n ' . escapeshellarg($updateScript) .
            ' > ' . escapeshellarg($logFile) .
            ' 2>&1 &';

        exec($command);

        $htmlOutput = '
        <h4 class="m-2 mt-2 alert alert-info fw-bold">
            🔄 Mise à jour en cours
        </h4>

        <div class="card m-2">
            <div class="card-body text-center">
                <p id="update-message" class="fw-bold">
                    Initialisation de la mise à jour...
                </p>

                <div class="progress" style="height: 32px;">
                    <div id="update-progress"
                         class="progress-bar progress-bar-striped progress-bar-animated"
                         role="progressbar"
                         style="width: 0%;"
                         aria-valuenow="0"
                         aria-valuemin="0"
                         aria-valuemax="100">
                        0 %
                    </div>
                </div>

                <p class="small text-muted mt-3">
                    Ne ferme pas cette page pendant la mise à jour.
                </p>

                <pre id="update-log"
                     class="bg-dark text-light p-3 rounded text-start"
                     style="max-height: 300px; overflow: auto;">
Initialisation...
                </pre>

                <div id="update-return" class="mt-3"></div>
            </div>
        </div>

        <script>
        function checkUpdateProgress() {
            fetch("./update-status.php?_=" + Date.now(), {
                cache: "no-store"
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                var progress = document.getElementById("update-progress");
                var message = document.getElementById("update-message");
                var log = document.getElementById("update-log");
                var returnArea = document.getElementById("update-return");

                progress.style.width = data.percent + "%";
                progress.setAttribute("aria-valuenow", data.percent);
                progress.textContent = data.percent + " %";

                message.textContent = data.message;
                log.textContent = data.log;
                log.scrollTop = log.scrollHeight;

                if (data.percent >= 100) {
                    progress.classList.remove("progress-bar-animated");

                    if (data.success) {
                        progress.classList.remove("bg-danger");
                        progress.classList.add("bg-success");

                        message.textContent =
                            "Mise à jour terminée avec succès. Retour à l’accueil...";

                        returnArea.innerHTML =
                            \'<a class="btn btn-primary" href="./">Retour immédiat à l’accueil</a>\';

                        setTimeout(function () {
                            window.location.href = "./";
                        }, 3500);

                        return;
                    }

                    progress.classList.remove("bg-success");
                    progress.classList.add("bg-danger");

                    returnArea.innerHTML =
                        \'<a class="btn btn-danger" href="./?p=update">Retour à la page de mise à jour</a>\';

                    return;
                }

                setTimeout(checkUpdateProgress, 1500);
            })
            .catch(function () {
                setTimeout(checkUpdateProgress, 2000);
            });
        }

        checkUpdateProgress();
        </script>';

        $ajax = null;
        break;

    default:
        $svxAction = (getSVXLinkStatus(1)) ? 'Restart' : 'Start';

        $htmlOutput = '
        <h4 class="m-2 mt-2 alert alert-success fw-bold talker">' .
            ($detect->isMobile() ? '&nbsp;' : '📡 Dernier trafic') .
            '<span id="onair" class="badge position-absolute top-50 start-50 translate-middle"></span>
        </h4>

        <div class="card m-2">
            <div class="card-body">';

        $htmlOutput .= ($config['cfgHostname'] == 'true' && $rolink) ? hostName() : null;
        $htmlOutput .= ($config['cfgUptime'] == 'true') ? getUpTime() : null;
        $htmlOutput .= ($config['cfgCpuStats'] == 'true') ? getCpuStats() : null;
        $htmlOutput .= ($config['cfgNetworking'] == 'true') ? networking() : null;
        $htmlOutput .= ($config['cfgSsid'] == 'true') ? getSSID() : null;
        $htmlOutput .= ($config['cfgPublicIp'] == 'true') ? getPublicIP() : null;
        $htmlOutput .= gpioStatus();
        $htmlOutput .= ($config['cfgDetectSa'] == 'false') ? sa818() : null;
        $htmlOutput .= ($config['cfgSvxStatus'] == 'true' && $rolink)
            ? '<div id="svxStatus">' . getSVXLinkStatus() . '</div>'
            : null;
        $htmlOutput .= '<div id="refContainer">' . getReflector() . '</div>';
        $htmlOutput .= ($config['cfgRefNodes'] == 'true' && $rolink) ? getRefNodes() : null;
        $htmlOutput .= ($config['cfgCallsign'] == 'false' && $rolink)
            ? getCallSign() . PHP_EOL
            : null;
        $htmlOutput .= ($rolink) ? getGPSDongle() . PHP_EOL : null;
        $htmlOutput .= ($config['cfgKernel'] == 'true') ? getKernel() : null;
        $htmlOutput .= ($config['cfgFreeSpace'] == 'true') ? getFreeSpace() : null;
        $htmlOutput .= ($rolink) ? getFileSystem() . PHP_EOL : null;
        $htmlOutput .= ($rolink) ? getRemoteVersion() . PHP_EOL : null;

        $htmlOutput .= ($rolink) ? '
        <div class="d-grid gap-2 col-7 mx-auto">
            <button id="resvx" class="btn btn-warning btn-lg">' . $svxAction . ' Hotlink</button>
            <button id="endsvx" class="btn btn-dark btn-lg">Stop Hotlink</button>
            <button id="reboot" class="btn btn-primary btn-lg">Reboot</button>
            <button id="halt" class="btn btn-danger btn-lg">Mise hors tension</button>
        </div>
        </div>
        </div>' : null;

        $htmlOutput .= ($config['cfgDTMF'] == 'true') ? dtmfSender() . PHP_EOL : null;

        $ajax = ($config['cfgCpuStats'] == 'true') ? "
        \$(document).ready(function () {
            cpuData();
            gpioStatus();
            $ajaxData
        });" : null;

        break;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="HotLink All-Radio Dashboard" />
    <meta name="author" content="FRS077" />

    <title>HotLink All-Radio - <?php echo htmlspecialchars(gethostname(), ENT_QUOTES, 'UTF-8'); ?></title>

    <link rel="apple-touch-icon" sizes="57x57" href="assets/fav/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="assets/fav/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="assets/fav/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="assets/fav/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="assets/fav/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="assets/fav/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="assets/fav/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="assets/fav/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-icon-180x180.png">

    <link rel="icon" type="image/png" sizes="192x192" href="assets/fav/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="assets/fav/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

    <link rel="manifest" href="manifest.json">

    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="assets/fav/ms-icon-144x144.png">
    <meta name="theme-color" content="#ffffff">

    <link href="css/styles.css?_=<?php echo cacheBuster('css/styles.css'); ?>" rel="stylesheet" />
    <link href="css/select2.min.css" rel="stylesheet" />
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="css/jquery.toast.min.css" rel="stylesheet" />
    <link href="css/iziModal.min.css" rel="stylesheet" />

    <style>
        .version-blink {
            font-weight: bold;
            animation: pulse-red 2s infinite;
        }

        @keyframes pulse-red {
            0% {
                color: #000000;
                text-shadow: none;
                transform: scale(1);
            }

            50% {
                color: #ff0000;
                text-shadow: 0 0 10px #ff0000;
                transform: scale(1.05);
            }

            100% {
                color: #000000;
                text-shadow: none;
                transform: scale(1);
            }
        }
    </style>

    <?php echo isset($extraResource) ? $extraResource . PHP_EOL : ''; ?>
</head>

<body>
    <div class="d-flex" id="wrapper">
        <div class="border-end bg-white" id="sidebar-wrapper">
            <div class="sidebar-heading border-bottom bg-light fw-bold text-center">
                <a href="./" class="text-decoration-none d-block" style="color:purple">
                    <span class="d-block">
                        <i class="icon-dashboard" style="font-size:26px;color:purple;vertical-align:middle;padding:0 4px 4px 0;"></i>
                        HotLink All-Radio
                    </span>
                    <span class="d-block mt-1">Dashboard</span>
                </a>
            </div>

            <div class="list-group list-group-flush">
                <a class="<?php echo ($page == '') ? 'active' : ''; ?> list-group-item list-group-item-action list-group-item-light p-3" href="./">📊 Statut</a>
                <a class="<?php echo ($page == 'wifi') ? 'active' : ''; ?> list-group-item list-group-item-action list-group-item-light p-3" href="./?p=wifi">📶 WiFi</a>
                <a class="<?php echo ($page == 'aprs') ? 'active' : ''; ?> list-group-item list-group-item-action list-group-item-light p-3" href="./?p=aprs">📡 APRS</a>
                <a class="<?php echo ($page == 'log') ? 'active' : ''; ?> list-group-item list-group-item-action list-group-item-light p-3" href="./?p=log">📋 Logs</a>
                <a class="<?php echo ($page == 'tty') ? 'active' : ''; ?> list-group-item list-group-item-action list-group-item-light p-3" href="./?p=tty">💻 Terminal</a>
                <a class="<?php echo ($page == 'cfg') ? 'active' : ''; ?> list-group-item list-group-item-action list-group-item-light p-3" href="./?p=cfg">⚙️ Config</a>
                <a class="<?php echo ($page == 'update' || $page == 'update-run') ? 'active' : ''; ?> list-group-item list-group-item-action list-group-item-light p-3" href="./?p=update">🔄 Mise à jour</a>
                <a class="<?php echo ($page == 'nod') ? 'active' : ''; ?> list-group-item list-group-item-action list-group-item-light p-3" href="./?p=nod">ℹ️ Node Info</a>
                <a class="list-group-item list-group-item-action list-group-item-light p-3" href="https://www.f62dmr.fr/index.php" target="_blank">🌐 Dashboard RNFA</a>
                <a class="list-group-item list-group-item-action list-group-item-light p-3" href="https://www.facebook.com/groups/1067389751809869" target="_blank">📘 Groupe Facebook</a>
            </div>
        </div>

        <div id="page-content-wrapper">
            <nav <?php echo $detect->isMobile() ? '' : 'style="display:none !important" '; ?> class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <div class="text-center bg-light py-2">
                        <h1 class="sidebar-heading fw-light mt-1 mb-1 text-dark">
                            <a href="./" class="text-decoration-none d-block" style="color:black">
                                <span class="d-block">HotLink</span>
                                <span class="d-block">Dashboard</span>
                            </a>
                        </h1>
                        <i class="icon-dashboard d-block mx-auto" style="font-size:40px;color:purple"></i>
                    </div>

                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <ul class="navbar-nav ms-auto mt-2 mt-lg-0">
                            <li class="nav-item"><a class="<?php echo ($page == '') ? 'active p-2' : ''; ?> nav-link" href="./">📊 Statut</a></li>
                            <li class="nav-item"><a class="<?php echo ($page == 'wifi') ? 'active p-2' : ''; ?> nav-link" href="./?p=wifi">📶 WiFi</a></li>
                            <li class="nav-item"><a class="<?php echo ($page == 'aprs') ? 'active p-2' : ''; ?> nav-link" href="./?p=aprs">📡 APRS</a></li>
                            <li class="nav-item"><a class="<?php echo ($page == 'log') ? 'active p-2' : ''; ?> nav-link" href="./?p=log">📋 Logs</a></li>
                            <li class="nav-item"><a class="<?php echo ($page == 'tty') ? 'active p-2' : ''; ?> nav-link" href="./?p=tty">💻 Terminal</a></li>
                            <li class="nav-item"><a class="<?php echo ($page == 'cfg') ? 'active p-2' : ''; ?> nav-link" href="./?p=cfg">⚙️ Config</a></li>
                            <li class="nav-item"><a class="<?php echo ($page == 'update' || $page == 'update-run') ? 'active p-2' : ''; ?> nav-link" href="./?p=update">🔄 Mise à jour</a></li>
                            <li class="nav-item"><a class="<?php echo ($page == 'nod') ? 'active p-2' : ''; ?> nav-link" href="./?p=nod">ℹ️ Node Info</a></li>
                            <li class="nav-item"><a class="nav-link p-2" href="https://www.f62dmr.fr/index.php" target="_blank">🌐 Dashboard du RNFA</a></li>
                            <li class="nav-item"><a class="nav-link p-2" href="https://www.facebook.com/groups/1067389751809869" target="_blank">📘 Groupe Facebook</a></li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div id="main-content" class="container-fluid mb-5">
                <?php echo $htmlOutput; ?>
            </div>
        </div>

        <div id="sysmsg"></div>
    </div>

    <footer class="page-footer fixed-bottom font-small bg-light">
        <div class="text-center small p-2">
            Copyright @
            <a class="text-primary" target="_blank" href="http://f62dmr.fr">
                Romuald / FRS077
            </a>
            - Modifications pour le réseau RNFA - Version :
            <?php
            $versionFile = __DIR__ . '/version';

            if (is_readable($versionFile)) {
                $versionText = trim((string) file_get_contents($versionFile));
                echo '<span class="version-blink">' .
                    htmlspecialchars($versionText, ENT_QUOTES, 'UTF-8') .
                    '</span>';
            }
            ?>
        </div>
    </footer>

    <script><?php echo $eventsData; ?></script>
    <script src="js/jquery.js"></script>
    <script src="js/iziModal.min.js"></script>
    <script src="js/bootstrap.js"></script>
    <script src="js/select2.min.js"></script>
    <script src="js/scripts.js?_=<?php echo cacheBuster('js/scripts.js'); ?>"></script>

    <?php echo isset($ajax) ? '<script>' . $ajax . '</script>' . PHP_EOL : ''; ?>
</body>
</html>