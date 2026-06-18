<?php

/**
 * waze-feed.php - Generiert einen XML-Feed im CIFS-Format fuer Waze
 */

header('Cache-Control: private, max-age=10, pre-check=10');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 10) . ' GMT');

// Config laden
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    $configFile = 'config.php';
}

if (!file_exists($configFile)) {
    http_response_code(500);
    die('Fehler: Config-Datei nicht gefunden.');
}

$config = require $configFile;

if (!is_array($config)) {
    http_response_code(500);
    die('Fehler: Config-Datei muss ein Array zurueckgeben.');
}

$config = array_merge([
    'TRACCAR_URL' => '',
    'USERNAME' => '',
    'PASSWORD' => '',
    'WAZE_REVERSE_GEOCODE_TOKEN' => '',
    'FEED_TITLE' => 'Waze Incident Feed',
    'FEED_LINK' => '',
    'FEED_DESCRIPTION' => '',
    'IGNORED_DEVICES' => [],
    'EMERGENCY_GROUPS' => [],
    'EMSTATUS_FILTER' => [],
    'GEOFENCE_IDS' => [],
    'MIN_SPEED_KMH' => 0,
    'DEBUG_MODE' => false,
], $config);

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

function logDebug($message)
{
    global $config;
    if (!empty($config['DEBUG_MODE'])) {
        error_log("[WazeFeed] " . $message);
    }
}

function getCachedData($key, &$cacheAge = null)
{
    global $config;
    $cacheDir = sys_get_temp_dir() . '/waze_feed_cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    $file = $cacheDir . '/' . md5($key) . '.json';
    if (!file_exists($file)) {
        $cacheAge = null;
        return null;
    }

    $age = time() - filemtime($file);
    if ($age > 300) {
        @unlink($file);
        $cacheAge = null;
        return null;
    }

    $data = json_decode(file_get_contents($file), true);
    $cacheAge = $age;
    return json_last_error() === JSON_ERROR_NONE ? $data : null;
}

function setCachedData($key, $data)
{
    global $config;
    $cacheDir = sys_get_temp_dir() . '/waze_feed_cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    $file = $cacheDir . '/' . md5($key) . '.json';
    file_put_contents($file, json_encode($data));
}

function getStreetNameFromOSM(float $lat, float $lon): ?string
{
    global $config;
    $lat = round($lat, 4);
    $lon = round($lon, 4);

    $cacheKey = "geo_osm_" . number_format($lat, 4) . "_" . number_format($lon, 4);
    $cachedResult = getCachedData($cacheKey);
    if ($cachedResult !== null) {
        logDebug("OSM Cache hit for {$lat},{$lon}: {$cachedResult}");
        return $cachedResult;
    }

    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?format=json&lat=%s&lon=%s&zoom=18&addressdetails=1',
        number_format($lat, 4),
        number_format($lon, 4)
    );

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_USERAGENT => 'WazeFeedGenerator/1.0 (contact: admin@yourdomain.com)',
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        logDebug("OSM Request failed: HTTP {$httpCode}");
        return null;
    }

    $data = json_decode($response, true);

    if (isset($data['address'])) {
        $street = $data['address']['road']
            ?? $data['address']['pedestrian']
            ?? $data['address']['residential']
            ?? null;

        if (!empty($street)) {
            logDebug("OSM SUCCESS: Found street '{$street}'");
            setCachedData($cacheKey, $street);
            return trim($street);
        }
    }

    if (!empty($data['display_name'])) {
        $parts = explode(',', $data['display_name']);
        if (count($parts) > 0) {
            $street = trim($parts[0]);
            logDebug("OSM Fallback: Used display_name '{$street}'");
            setCachedData($cacheKey, $street);
            return $street;
        }
    }

    logDebug("OSM No street found for {$lat},{$lon}");
    return null;
}

function getStreetNameFromWaze(float $lat, float $lon): array
{
    global $config;
    $cacheAge = null;
    $lat = round($lat, 4);
    $lon = round($lon, 4);

    if (!empty($config['WAZE_REVERSE_GEOCODE_TOKEN'])) {
        $cacheKey = "geo_waze_" . number_format($lat, 6) . "_" . number_format($lon, 6);
        $cachedResult = getCachedData($cacheKey, $cacheAge);
        if ($cachedResult !== null) {
            logDebug("Waze Cache hit for {$lat},{$lon}: {$cachedResult}");
            return ['street' => $cachedResult, 'source' => 'waze_cache', 'cache_age' => $cacheAge];
        }

        $url = sprintf(
            'https://www.waze.com/row-partnerhub-api/waze-map/streetsInfo?lat=%s&lon=%s&token=%s',
            number_format($lat, 6),
            number_format($lon, 6),
            urlencode($config['WAZE_REVERSE_GEOCODE_TOKEN'])
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT => 'WazeFeedGenerator/1.0 (contact: admin@yourdomain.com)',
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!$error && $httpCode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                logDebug("Waze JSON decode error");
            } else {
                $street = null;
                if (isset($data['result']) && is_array($data['result']) && !empty($data['result'])) {
                    $firstResult = $data['result'][0];
                    if (isset($firstResult['names']) && is_array($firstResult['names']) && !empty($firstResult['names'])) {
                        $street = trim($firstResult['names'][0]);
                    } elseif (isset($firstResult['street']) && !empty($firstResult['street'])) {
                        $street = trim($firstResult['street']);
                    }
                }

                if (!empty($street)) {
                    logDebug("Waze SUCCESS: Found street '{$street}'");
                    setCachedData($cacheKey, $street);
                    return ['street' => $street, 'source' => 'waze_api', 'cache_age' => null];
                } else {
                    logDebug("Waze No street found in response structure.");
                }
            }
        } elseif (!empty($error)) {
            logDebug("Waze cURL Error: {$error}");
        } else {
            logDebug("Waze HTTP Error {$httpCode}");
        }
    }

    $osmStreet = getStreetNameFromOSM($lat, $lon);
    if ($osmStreet !== null) {
        return ['street' => $osmStreet, 'source' => 'nominatim', 'cache_age' => null];
    }

    return ['street' => null, 'source' => 'none', 'cache_age' => null];
}

// --- Hauptlogik ---

$traccarUrl = rtrim($config['TRACCAR_URL'], '/');
$authEndpoint = $traccarUrl . '/api/session';
$cookieFile = sys_get_temp_dir() . '/traccar_cookie_' . getmypid() . '.txt';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $authEndpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['email' => $config['USERNAME'], 'password' => $config['PASSWORD']]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
]);

$authResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$authError = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$authResponse) {
    http_response_code(500);
    die("Fehler bei der Traccar Anmeldung. HTTP-Code: {$httpCode}, cURL-Fehler: " . ($authError ?: 'Keine Antwort'));
}

$authResult = json_decode($authResponse, true);
if (json_last_error() !== JSON_ERROR_NONE || !isset($authResult['id'])) {
    http_response_code(500);
    die("Ungueltige Antwort von Traccar bei der Anmeldung.");
}

// Geraete abrufen
$devicesEndpoint = $traccarUrl . '/api/devices?limit=1000';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $devicesEndpoint,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
]);

$devicesJson = curl_exec($ch);
curl_close($ch);

if (!$devicesJson) {
    http_response_code(500);
    die("Fehler beim Laden der Geraete.");
}

$devices = json_decode($devicesJson, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($devices)) {
    http_response_code(500);
    die("Ungueltige Geraete-Daten von Traccar.");
}

// Positionen abrufen (mit geofenceIds Attribut)
$positionsEndpoint = $traccarUrl . '/api/positions?limit=-1&attributes=emstatus,geofenceIds';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $positionsEndpoint,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
]);

$allPositionsJson = curl_exec($ch);
curl_close($ch);

if (!$allPositionsJson) {
    http_response_code(500);
    die("Fehler beim Laden der Positionsdaten.");
}

$allPositions = json_decode($allPositionsJson, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($allPositions)) {
    http_response_code(500);
    die("Ungueltige Positions-Daten von Traccar.");
}

// TEMP: Log structure of first position to find geofenceIds
if (!empty($allPositions) && is_array($allPositions[0])) {
    $firstPos = $allPositions[0];
    logDebug("POSITION STRUCTURE - first position keys: [" . implode(', ', array_keys($firstPos)) . "]");
    logDebug("POSITION STRUCTURE - geofenceIds key (top-level) exists: " . (isset($firstPos['geofenceIds']) ? 'YES' : 'NO'));
    if (isset($firstPos['geofenceIds'])) {
        logDebug("POSITION STRUCTURE - geofenceIds value: " . json_encode($firstPos['geofenceIds']));
    }
    if (isset($firstPos['attributes']) && is_array($firstPos['attributes'])) {
        logDebug("POSITION STRUCTURE - attributes keys: [" . implode(', ', array_keys($firstPos['attributes'])) . "]");
        if (isset($firstPos['attributes']['geofenceIds'])) {
            logDebug("POSITION STRUCTURE - attributes.geofenceIds value: " . json_encode($firstPos['attributes']['geofenceIds']));
        }
    }
}

$positionsByDevice = [];
foreach ($allPositions as $pos) {
    if (!isset($pos['deviceId']) || !isset($pos['fixTime'])) continue;
    $deviceId = $pos['deviceId'];
    if (!isset($positionsByDevice[$deviceId]) || strtotime($pos['fixTime']) > strtotime($positionsByDevice[$deviceId]['fixTime'])) {
        $positionsByDevice[$deviceId] = $pos;
    }
}

// Geofence-IDs werden aus den Positionen geholt (nicht aus Devices)
// $geofenceCache wird mit geofenceIds jeder Position gefuellt

// XML Ausgabe
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<incidents>
    <?php
    foreach ($devices as $device) {
        $deviceId = $device['id'];

        if (in_array($deviceId, $config['IGNORED_DEVICES']) || !isset($positionsByDevice[$deviceId])) continue;

        if (!empty($config['EMERGENCY_GROUPS'])) {
            $groupId = $device['groupId'] ?? null;
            if ($groupId === null || !in_array($groupId, $config['EMERGENCY_GROUPS'])) {
                logDebug("Device {$deviceId} nicht in EMERGENCY_GROUPS. Uebersprungen.");
                continue;
            }
        }

        $latestPos = $positionsByDevice[$deviceId];
        $lat = $latestPos['latitude'] ?? null;
        $lon = $latestPos['longitude'] ?? null;
        if ($lat === null || $lon === null) continue;

        $speedKmh = ($latestPos['speed'] ?? 0) * 3.6;
        if ($speedKmh < $config['MIN_SPEED_KMH']) continue;

        $geoData = getStreetNameFromWaze($lat, $lon);
        $street = $geoData['street'] ?? '';
        $source = $geoData['source'] ?? '';
        $cacheAge = $geoData['cache_age'] ?? null;

        if (!empty($street)) {
            $street = trim($street);
        }

        $deviceName = $device['name'] ?? 'Unknown Device';

        // emstatus aus Position
        $emstatus = null;
        if (isset($latestPos['attributes']) && is_array($latestPos['attributes'])) {
            if (isset($latestPos['attributes']['emstatus'])) {
                $emstatus = $latestPos['attributes']['emstatus'];
            } elseif (isset($latestPos['attributes'][0])) {
                foreach ($latestPos['attributes'] as $attr) {
                    if (isset($attr['key']) && $attr['key'] === 'emstatus') {
                        $emstatus = $attr['value'] ?? null;
                        break;
                    }
                }
            }
        }

        // Geofence-IDs aus der Position extrahieren
        $positionGeofenceIds = null;
        if (isset($latestPos['geofenceIds']) && is_array($latestPos['geofenceIds'])) {
            $positionGeofenceIds = $latestPos['geofenceIds'];
            logDebug("Device {$deviceId} ({$deviceName}) - position geofenceIds (top-level): [" . implode(',', $positionGeofenceIds) . "]");
        } elseif (isset($latestPos['attributes']['geofenceIds'])) {
            $raw = $latestPos['attributes']['geofenceIds'];
            if (is_array($raw)) {
                $positionGeofenceIds = $raw;
            } elseif (is_string($raw)) {
                $positionGeofenceIds = array_map('intval', array_filter(explode(',', $raw)));
            }
            logDebug("Device {$deviceId} ({$deviceName}) - position geofenceIds (from attributes): [" . implode(',', $positionGeofenceIds ?? []) . "]");
        } else {
            logDebug("Device {$deviceId} ({$deviceName}) - keine geofenceIds in Position gefunden.");
        }

        // emstatus Filter
        if (!empty($config['EMSTATUS_FILTER']) && $emstatus !== null) {
            if (!in_array((int)$emstatus, $config['EMSTATUS_FILTER'])) {
                logDebug("Device {$deviceId} (emstatus: {$emstatus}) nicht im EMSTATUS_FILTER. Uebersprungen.");
                continue;
            }
        }

        // geofenceIds Filter - INVERTIERT: Nur Devices AUSSERHALB der konfigurierten Geofences anzeigen
        $deviceGeofenceIds = null;
        if (!empty($config['GEOFENCE_IDS'])) {
            logDebug("GEOFENCE_IDS aktiviert (OUTSIDE-Modus): [" . implode(',', $config['GEOFENCE_IDS']) . "]");

            if ($positionGeofenceIds !== null) {
                $deviceGeofenceIds = $positionGeofenceIds;
                $matches = array_intersect($config['GEOFENCE_IDS'], $deviceGeofenceIds);
                if (!empty($matches)) {
                    logDebug("Device {$deviceId} - Geofence-Filter: IM Geofence (IDs: " . implode(',', $matches) . ") -> uebersprungen.");
                    continue;
                } else {
                    logDebug("Device {$deviceId} - Geofence-Filter: AUSSERHALB (konfiguriert: [" . implode(',', $config['GEOFENCE_IDS']) . "], position: [" . implode(',', $deviceGeofenceIds) . "])");
                }
            } else {
                logDebug("Device {$deviceId} - Keine geofenceIds in Position -> zeige trotzdem an (keine Geofence-Zuordnung).");
            }
        }

        // Debug-Info fuer Attribute
        if (!empty($config['DEBUG_MODE']) && empty($emstatus)) {
            $attrKeys = [];
            if (isset($latestPos['attributes']) && is_array($latestPos['attributes'])) {
                if (!isset($latestPos['attributes'][0])) {
                    $attrKeys = array_keys($latestPos['attributes']);
                } else {
                    foreach ($latestPos['attributes'] as $attr) {
                        $attrKeys[] = $attr['key'] ?? 'unknown';
                    }
                }
            }
            logDebug("Device {$deviceId} ({$deviceName}) - position attributes keys: [" . implode(', ', $attrKeys) . "]");
        }

        $fixTime = strtotime($latestPos['fixTime']);
        $timeSinceFix = time() - $fixTime;
        $lat = round($lat, 5);
        $lon = round($lon, 5);
    ?>
        <?php if ($config['DEBUG_MODE']): ?>
            <!-- Debug-Informationen -->
            <!-- Incident fuer Geraet: <?php echo htmlspecialchars($deviceName); ?> (ID: <?php echo $deviceId; ?>) -->
            <!-- Koordinaten: Lat=<?php echo $lat; ?>, Lon=<?php echo $lon; ?> -->
            <!-- Quelle der Strasse: <?php echo htmlspecialchars($source); ?> -->
            <!-- Zeit seit Positionsuebermittlung an Traccar: <?php echo $timeSinceFix; ?> Sekunden -->
            <!-- Cache-Alter: <?php echo ($cacheAge !== null) ? $cacheAge . ' Sekunden' : 'Neu (kein Cache)'; ?> -->
            <!-- emstatus: <?php echo htmlspecialchars($emstatus ?? 'nicht gesetzt'); ?> -->
            <!-- Geofence-Filter: <?php echo !empty($config['GEOFENCE_IDS']) ? ($deviceGeofenceIds !== null ? 'aktiv - IDs: ' . implode(',', $deviceGeofenceIds) : 'aktiv - keine IDs gefunden') : 'deaktiviert'; ?> -->
        <?php endif; ?>
        <incident id="<?php echo htmlspecialchars($deviceName); ?>">
            <type>HAZARD</type>
            <subtype>HAZARD_ON_ROAD_EMERGENCY_VEHICLE</subtype>
            <polyline><?php echo "{$lat} {$lon}"; ?></polyline>
            <direction>BOTH_DIRECTIONS</direction>
            <street><?php echo htmlspecialchars($street ?: 'Unbekannt'); ?></street>
            <description>Einsatzfahrzeug - Geschwindigkeit: <?php echo round($speedKmh, 1); ?> km/h</description>
        </incident>
    <?php
    }
    ?>
</incidents>
