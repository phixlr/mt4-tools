#!/usr/bin/env php
<?php
/**
 * Aktualisiert die lokal vorhandenen Dukascopy-Tickdaten. Die Daten werden nach FXT konvertiert und im Rost-Format
 * gespeichert. Am Wochenende, an Feiertagen und wenn keine Tickdaten verfuegbar sind, sind die Dukascopy-Dateien leer.
 * Wochenenden werden lokal nicht gespeichert. Montags frueh koennen die Daten erst um 01:00 FXT beginnen.
 * Die Daten der aktuellen Stunde sind fruehestens ab der naechsten Stunde verfuegbar.
 *
 *
 * Website:       https://www.dukascopy.com/swiss/english/marketwatch/historical/
 *                https://www.dukascopy.com/free/candelabrum/                                       (inactive)
 *
 * Instruments:   https://www.dukascopy.com/free/candelabrum/data.json                              (inactive)
 *
 * History start: http://datafeed.dukascopy.com/datafeed/metadata/HistoryStart.bi5                  (big-endian)
 *                http://datafeed.dukascopy.com/datafeed/AUDUSD/metadata/HistoryStart.bi5           (big-endian)
 *
 * URL-Format:    Eine Datei je Tagestunde GMT,
 *                z.B.: (Januar = 00)
 *                - http://datafeed.dukascopy.com/datafeed/EURUSD/2013/00/06/00h_ticks.bi5
 *                - http://datafeed.dukascopy.com/datafeed/EURUSD/2013/05/10/23h_ticks.bi5
 *
 * Dateiformat:   - Binaer, LZMA-gepackt, Zeiten in GMT (keine Sommerzeit).
 *
 *          +------------++------------+------------+------------+------------+------------++------------+------------++------------+
 * GMT:     |   Sunday   ||   Monday   |  Tuesday   | Wednesday  |  Thursday  |   Friday   ||  Saturday  |   Sunday   ||   Monday   |
 *          +------------++------------+------------+------------+------------+------------++------------+------------++------------+
 *      +------------++------------+------------+------------+------------+------------++------------+------------++------------+
 * FXT: |   Sunday   ||   Monday   |  Tuesday   | Wednesday  |  Thursday  |   Friday   ||  Saturday  |   Sunday   ||   Monday   |
 *      +------------++------------+------------+------------+------------+------------++------------+------------++------------+
 *
 *
 * TODO: check info from Zorro forum:  http://www.opserver.de/ubb7/ubbthreads.php?ubb=showflat&Number=463361#Post463345
 */
namespace rosasurfer\rost\dukascopy\update_tickdata;

use rosasurfer\config\Config;
use rosasurfer\exception\IllegalTypeException;
use rosasurfer\exception\InvalidArgumentException;
use rosasurfer\exception\RuntimeException;
use rosasurfer\net\http\CurlHttpClient;
use rosasurfer\net\http\HttpClient;
use rosasurfer\net\http\HttpRequest;
use rosasurfer\net\http\HttpResponse;
use rosasurfer\process\Process;

use rosasurfer\rost\LZMA;
use rosasurfer\rost\Rost;
use rosasurfer\rost\dukascopy\Dukascopy;
use rosasurfer\rost\dukascopy\DukascopyException;
use rosasurfer\rost\model\DukascopySymbol;
use rosasurfer\rost\model\RosaSymbol;

use function rosasurfer\rost\fxtStrToTime;
use function rosasurfer\rost\fxtTimezoneOffset;
use function rosasurfer\rost\isWeekend;

require(dirName(realPath(__FILE__)).'/../../app/init.php');
date_default_timezone_set('GMT');


// -- Konfiguration ---------------------------------------------------------------------------------------------------------


$verbose = 0;                                   // output verbosity

$saveCompressedDukascopyFiles = false;          // ob heruntergeladene Dukascopy-Dateien zwischengespeichert werden sollen
$saveRawDukascopyFiles        = false;          // ob entpackte Dukascopy-Dateien zwischengespeichert werden sollen
$saveRawRostData              = true;           // ob unkomprimierte Rost-Historydaten gespeichert werden sollen


// -- Start -----------------------------------------------------------------------------------------------------------------


// (1) Befehlszeilenargumente einlesen und validieren
/** @var string[] $args */
$args = array_slice($_SERVER['argv'], 1);

// Optionen parsen
foreach ($args as $i => $arg) {
    if ($arg == '-h'  )   exit(1|help());                                            // Hilfe
    if ($arg == '-v'  ) { $verbose = max($verbose, 1); unset($args[$i]); continue; } // verbose output
    if ($arg == '-vv' ) { $verbose = max($verbose, 2); unset($args[$i]); continue; } // more verbose output
    if ($arg == '-vvv') { $verbose = max($verbose, 3); unset($args[$i]); continue; } // very verbose output
}

/** @var RosaSymbol[] $symbols */
$symbols = [];

// Symbole parsen
foreach ($args as $i => $arg) {
    /** @var RosaSymbol $symbol */
    $symbol = RosaSymbol::dao()->findByName($arg);
    if (!$symbol)                       exit(1|stderror('error: unknown symbol "'.$args[$i].'"'));
    if (!$symbol->getDukascopySymbol()) exit(1|stderror('error: no Dukascopy mapping found for symbol "'.$args[$i].'"'));
    $symbols[$symbol->getName()] = $symbol;                                         // using the name as index removes duplicates
}
$symbols = $symbols ?: RosaSymbol::dao()->findAllDukascopyMapped();                 // ohne Angabe werden alle Instrumente verarbeitet


// (2) Daten aktualisieren
foreach ($symbols as $symbol) {
    updateSymbol($symbol) || exit(1);
}
exit(0);


// --- Funktionen -----------------------------------------------------------------------------------------------------------


/**
 * Aktualisiert die Tickdaten eines Symbol.
 *
 * Eine Dukascopy-Datei enthaelt eine Stunde Tickdaten. Die Daten der aktuellen Stunde sind fruehestens
 * ab der naechsten Stunde verfuegbar.
 *
 * @param  RosaSymbol $symbol
 *
 * @return bool - Erfolgsstatus
 */
function updateSymbol(RosaSymbol $symbol) {
    /** @var DukascopySymbol $dukaSymbol */
    $dukaSymbol = $symbol->getDukascopySymbol();
    $symbolName = $symbol->getName();

    echoPre('[Info]    '.$symbolName);


    // (1) Beginn des naechsten Forex-Tages ermitteln
    $startTimeFXT = $dukaSymbol->getHistoryTicksStart();
    $startTimeGMT = $startTimeFXT ? fxtStrToTime($startTimeFXT) : 0;        // Beginn der Tickdaten des Symbols in GMT
    $prev = $next = null;
    $fxtOffset    = fxtTimezoneOffset($startTimeGMT, $prev, $next);         // es gilt: FXT = GMT + Offset
    $startTimeFXT = $startTimeGMT + $fxtOffset;                             // Beginn der Tickdaten in FXT

    if ($remainder=$startTimeFXT % DAY) {                                   // Beginn auf den naechsten Forex-Tag 00:00 aufrunden, sodass
        $diff = 1*DAY - $remainder;                                         // wir nur vollstaendige Forex-Tage verarbeiten. Dabei
        if ($startTimeGMT + $diff >= $next['time']) {                       // beruecksichtigen, dass sich zu Beginn des naechsten Forex-Tages
            $startTimeGMT = $next['time'];                                  // der DST-Offset der FXT geaendert haben kann.
            $startTimeFXT = $startTimeGMT + $next['offset'];
            if ($remainder=$startTimeFXT % DAY) $diff = 1*DAY - $remainder;
            else                                $diff = 0;
            $fxtOffset = fxtTimezoneOffset($startTimeGMT, $prev, $next);
        }
        $startTimeGMT += $diff;                                             // naechster Forex-Tag 00:00 in GMT
        $startTimeFXT += $diff;                                             // naechster Forex-Tag 00:00 in FXT
    }


    // (2) Gesamte Zeitspanne inklusive Wochenenden stundenweise durchlaufen, um von vorherigen Durchlaufen ggf. vorhandene
    // Zwischendateien finden und loeschen zu koennen.
    $thisHour = ($thisHour=time()) - $thisHour%HOUR;                        // Beginn der aktuellen Stunde GMT
    $lastHour = $thisHour - 1*HOUR;                                         // Beginn der letzten Stunde GMT

    for ($gmtHour=$startTimeGMT; $gmtHour < $lastHour; $gmtHour+=1*HOUR) {
        if ($gmtHour >= $next['time'])
            $fxtOffset = fxtTimezoneOffset($gmtHour, $prev, $next);         // $fxtOffset on-the-fly aktualisieren
        $fxtHour = $gmtHour + $fxtOffset;

        if (!checkHistory($symbolName, $gmtHour, $fxtHour)) return false;

        Process::dispatchSignals();                                         // check for Ctrl-C
    }
    echoPre('[Ok]      '.$symbolName);
    return true;
}


/**
 * Prueft den Stand der Rost-Tickdaten einer einzelnen Stunde und stoesst ggf. das Update an.
 *
 * @param  string $symbol  - Symbol
 * @param  int    $gmtHour - GMT-Timestamp der zu pruefenden Stunde
 * @param  int    $fxtHour - FXT-Timestamp der zu pruefenden Stunde
 *
 * @return bool - Erfolgsstatus
 */
function checkHistory($symbol, $gmtHour, $fxtHour) {
    if (!is_int($gmtHour)) throw new IllegalTypeException('Illegal type of parameter $gmtHour: '.getType($gmtHour));
    if (!is_int($fxtHour)) throw new IllegalTypeException('Illegal type of parameter $fxtHour: '.getType($fxtHour));
    $shortDate = gmDate('D, d-M-Y H:i', $fxtHour);

    global $verbose, $saveCompressedDukascopyFiles, $saveRawDukascopyFiles, $saveRawRostData;
    static $lastDay=-1, $lastMonth=-1;

    // (1) nur an Handelstagen pruefen, ob die Rost-History existiert und ggf. aktualisieren
    if (!isWeekend($fxtHour)) {
        $day = (int) gmDate('d', $fxtHour);
        if ($day != $lastDay) {
            if ($verbose > 1) echoPre('[Info]    '.gmDate('d-M-Y', $fxtHour));
            else {
                $month = (int) gmDate('m', $fxtHour);
                if ($month != $lastMonth) {
                    if ($verbose > 0) echoPre('[Info]    '.gmDate('M-Y', $fxtHour));
                    $lastMonth = $month;
                }
            }
            $lastDay = $day;
        }

        // History ist ok, wenn entweder die komprimierte Rost-Datei existiert...
        if (is_file($file=getVar('rostFile.compressed', $symbol, $fxtHour))) {
            if ($verbose > 1) echoPre('[Ok]      '.$shortDate.'  Rosatrader compressed tick file: '.Rost::relativePath($file));
        }
        // History ist ok, ...oder die unkomprimierte Rost-Datei gespeichert wird und existiert
        else if ($saveRawRostData && is_file($file=getVar('rostFile.raw', $symbol, $fxtHour))) {
            if ($verbose > 1) echoPre('[Ok]      '.$shortDate.'  Rosatrader uncompressed tick file: '.Rost::relativePath($file));
        }
        // andererseits Tickdaten aktualisieren
        else {
            try {
                if (!updateTicks($symbol, $gmtHour, $fxtHour)) return false;
            }
            catch (DukascopyException $ex) {    // bei leerem Response fortfahren (Fehler wurde schon gespeichert)
                if (!strStartsWithI($ex->getMessage(), 'empty response for url:')) throw $ex;
            }
        }
    }


    // (2) an allen Tagen: nicht mehr benoetigte Dateien und Verzeichnisse loeschen
    // komprimierte Dukascopy-Daten (Downloads) der geprueften Stunde
    if (!$saveCompressedDukascopyFiles) {
        if (is_file($file=getVar('dukaFile.compressed', $symbol, $gmtHour))) unlink($file);
    }
    // dekomprimierte Dukascopy-Daten der geprueften Stunde
    if (!$saveRawDukascopyFiles) {
        if (is_file($file=getVar('dukaFile.raw', $symbol, $gmtHour))) unlink($file);
    }
    // Dukascopy-Downloadverzeichnis der aktuellen Stunde, wenn es leer ist
    if (is_dir($dir=getVar('rostDir', $symbol, $gmtHour))) @rmDir($dir);
    // lokales Historyverzeichnis der aktuellen Stunde, wenn Wochenende und es leer ist
    if (isWeekend($fxtHour)) {
        if (is_dir($dir=getVar('rostDir', $symbol, $fxtHour))) @rmDir($dir);
    }

    return true;
}


/**
 * Aktualisiert die Tickdaten einer einzelnen Forex-Handelstunde. Wird aufgerufen, wenn fuer diese Stunde keine lokalen
 * Rost-Tickdateien existieren.
 *
 * @param  string $symbol  - Symbol
 * @param  int    $gmtHour - GMT-Timestamp der zu aktualisierenden Stunde
 * @param  int    $fxtHour - FXT-Timestamp der zu aktualisierenden Stunde
 *
 * @return bool - Erfolgsstatus
 */
function updateTicks($symbol, $gmtHour, $fxtHour) {
    if (!is_int($gmtHour)) throw new IllegalTypeException('Illegal type of parameter $gmtHour: '.getType($gmtHour));
    if (!is_int($fxtHour)) throw new IllegalTypeException('Illegal type of parameter $fxtHour: '.getType($fxtHour));
    $shortDate = gmDate('D, d-M-Y H:i', $fxtHour);

    // Tickdaten laden
    $ticks = loadTicks($symbol, $gmtHour, $fxtHour);
    if (!is_array($ticks)) return false;

    // Tickdaten speichern
    if (!saveTicks($symbol, $gmtHour, $fxtHour, $ticks)) return false;

    return true;
}


/**
 * Laedt die Daten einer einzelnen Forex-Handelsstunde und gibt sie zurueck.
 *
 * @param  string $symbol  - Symbol
 * @param  int    $gmtHour - GMT-Timestamp der zu ladenden Stunde
 * @param  int    $fxtHour - FXT-Timestamp der zu ladenden Stunde
 *
 * @return array[]|bool - Array mit Tickdaten oder FALSE in case of errors
 */
function loadTicks($symbol, $gmtHour, $fxtHour) {
    if (!is_int($gmtHour)) throw new IllegalTypeException('Illegal type of parameter $gmtHour: '.getType($gmtHour));
    if (!is_int($fxtHour)) throw new IllegalTypeException('Illegal type of parameter $fxtHour: '.getType($fxtHour));
    $shortDate = gmDate('D, d-M-Y H:i', $fxtHour);

    // Die Tickdaten der Handelsstunde werden in folgender Reihenfolge gesucht:
    //  - in bereits dekomprimierten Dukascopy-Dateien
    //  - in noch komprimierten Dukascopy-Dateien
    //  - als Dukascopy-Download

    global $saveCompressedDukascopyFiles;
    $ticks = [];

    // dekomprimierte Dukascopy-Datei suchen und bei Erfolg Ticks laden
    if (!$ticks) {
        if (is_file($file=getVar('dukaFile.raw', $symbol, $gmtHour))) {
            $ticks = loadRawDukascopyTickFile($file, $symbol, $gmtHour, $fxtHour);
            if (!$ticks) return false;
        }
    }

    // ggf. komprimierte Dukascopy-Datei suchen und bei Erfolg Ticks laden
    if (!$ticks) {
        if (is_file($file=getVar('dukaFile.compressed', $symbol, $gmtHour))) {
            $ticks = loadCompressedDukascopyTickFile($file, $symbol, $gmtHour, $fxtHour);
            if (!$ticks) return false;
        }
    }

    // ggf. Dukascopy-Datei herunterladen und Ticks laden
    if (!$ticks) {
        $data = downloadTickdata($symbol, $gmtHour, $fxtHour, false, $saveCompressedDukascopyFiles);
        if (!$data) return false;

        $ticks = loadCompressedDukascopyTickData($data, $symbol, $gmtHour, $fxtHour);
        if (!$ticks) return false;
    }

    return $ticks;
}


/**
 * Schreibt die Tickdaten einer Handelsstunde in die lokale Rost-Tickdatei.
 *
 * @param  string  $symbol  - Symbol
 * @param  int     $gmtHour - GMT-Timestamp der Handelsstunde
 * @param  int     $fxtHour - FXT-Timestamp der Handelsstunde
 * @param  array[] $ticks   - zu speichernde Ticks
 *
 * @return bool - Erfolgsstatus
 */
function saveTicks($symbol, $gmtHour, $fxtHour, array $ticks) {
    if (!is_int($gmtHour)) throw new IllegalTypeException('Illegal type of parameter $gmtHour: '.getType($gmtHour));
    if (!is_int($fxtHour)) throw new IllegalTypeException('Illegal type of parameter $fxtHour: '.getType($fxtHour));
    $shortDate = gmDate('D, d-M-Y H:i', $fxtHour);
    global $saveRawRostData;


    // (1) Tickdaten nochmal pruefen
    if (!$ticks) throw new RuntimeException('No ticks for '.$shortDate);
    $size = sizeof($ticks);
    $fromHour = ($time=$ticks[      0]['time_fxt']) - $time%HOUR;
    $toHour   = ($time=$ticks[$size-1]['time_fxt']) - $time%HOUR;
    if ($fromHour != $fxtHour) throw new RuntimeException('Ticks for '.$shortDate.' do not match the specified hour: $tick[0]=\''.gmDate('d-M-Y H:i:s \F\X\T', $ticks[0]['time_fxt']).'\'');
    if ($fromHour != $toHour)  throw new RuntimeException('Ticks for '.$shortDate.' span multiple hours from=\''.gmDate('d-M-Y H:i:s \F\X\T', $ticks[0]['time_fxt']).'\' to=\''.gmDate('d-M-Y H:i:s \F\X\T', $ticks[$size-1]['time_fxt']).'\'');


    // (2) Ticks binaer packen
    $data = null;
    foreach ($ticks as $tick) {
        $data .= pack('VVV', $tick['timeDelta'],
                                    $tick['bid'      ],
                                    $tick['ask'      ]);
    }


    // (3) binaere Daten ggf. unkomprimiert speichern
    if ($saveRawRostData) {
        if (is_file($file=getVar('rostFile.raw', $symbol, $fxtHour))) {
            echoPre('[Error]   '.$symbol.' ticks for '.$shortDate.' already exists');
            return false;
        }
        mkDirWritable(dirName($file));
        $tmpFile = tempNam(dirName($file), baseName($file));
        $hFile   = fOpen($tmpFile, 'wb');
        fWrite($hFile, $data);
        fClose($hFile);
        rename($tmpFile, $file);            // So kann eine existierende Datei niemals korrupt sein.
    }


    // (4) binaere Daten ggf. komprimieren und speichern

    return true;
}


/**
 * Laedt eine Dukascopy-Tickdatei und gibt ihren Inhalt zurueck.
 *
 * @param  string $symbol    - Symbol der herunterzuladenen Datei
 * @param  int    $gmtHour   - GMT-Timestamp der zu ladenden Stunde
 * @param  int    $fxtHour   - FXT-Timestamp der zu ladenden Stunde
 * @param  bool   $quiet     - ob Statusmeldungen unterdrueckt werden sollen (default: nein)
 * @param  bool   $saveData  - ob die Datei gespeichert werden soll (default: nein)
 * @param  bool   $saveError - ob ein 404-Fehler mit einer entsprechenden Fehlerdatei signalisiert werden soll (default: ja)
 *
 * @return string - Content der heruntergeladenen Datei oder Leerstring, wenn die Resource nicht gefunden wurde (404-Fehler).
 */
function downloadTickdata($symbol, $gmtHour, $fxtHour, $quiet=false, $saveData=false, $saveError=true) {
    if (!is_int($gmtHour))    throw new IllegalTypeException('Illegal type of parameter $gmtHour: '.getType($gmtHour));
    if (!is_int($fxtHour))    throw new IllegalTypeException('Illegal type of parameter $fxtHour: '.getType($fxtHour));
    if (!is_bool($quiet))     throw new IllegalTypeException('Illegal type of parameter $quiet: '.getType($quiet));
    if (!is_bool($saveData))  throw new IllegalTypeException('Illegal type of parameter $saveData: '.getType($saveData));
    if (!is_bool($saveError)) throw new IllegalTypeException('Illegal type of parameter $saveError: '.getType($saveError));
    global$verbose;

    $shortDate = gmDate('D, d-M-Y H:i', $fxtHour);
    $url       = getVar('dukaUrl', $symbol, $gmtHour);
    if (!$quiet && $verbose > 1) echoPre('[Info]    '.$shortDate.'  downloading: '.$url);

    if (!$config=Config::getDefault())
        throw new RuntimeException('Service locator returned invalid default config: '.getType($config));


    // (1) Standard-Browser simulieren
    $userAgent = $config->get('rost.useragent'); if (!$userAgent) throw new InvalidArgumentException('Invalid user agent configuration: "'.$userAgent.'"');
    $request = HttpRequest::create()
                                 ->setUrl($url)
                                 ->setHeader('User-Agent'     , $userAgent                                                       )
                                 ->setHeader('Accept'         , 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
                                 ->setHeader('Accept-Language', 'en-us'                                                          )
                                 ->setHeader('Accept-Charset' , 'ISO-8859-1,utf-8;q=0.7,*;q=0.7'                                 )
                                 ->setHeader('Connection'     , 'keep-alive'                                                     )
                                 ->setHeader('Cache-Control'  , 'max-age=0'                                                      )
                                 ->setHeader('Referer'        , 'http://www.dukascopy.com/free/candelabrum/'                     );
    $options[CURLOPT_SSL_VERIFYPEER] = false;                            // falls HTTPS verwendet wird
    //$options[CURLOPT_VERBOSE     ] = true;


    // (2) HTTP-Request abschicken und auswerten
    static $httpClient = null;
    !$httpClient && $httpClient=CurlHttpClient::create($options);        // Instanz fuer KeepAlive-Connections wiederverwenden

    $response = $httpClient->send($request);                             // TODO: CURL-Fehler wie bei SimpleTrader behandeln
    $status   = $response->getStatus();
    if ($status!=200 && $status!=404) throw new RuntimeException('Unexpected HTTP status '.$status.' ('.HttpResponse::$sc[$status].') for url "'.$url.'"'.NL.printPretty($response, true));

    // eine leere Antwort ist moeglich und wird als Fehler behandelt
    $content = $response->getContent();
    if ($status == 404) $content = '';                                   // moeglichen Content eines 404-Fehlers zuruecksetzen


    // (3) Download-Success: 200 und Datei ist nicht leer
    if ($status==200 && strLen($content)) {
        // vorhandene Fehlerdateien loeschen (haben FXT-Namen)
        if (is_file($file=getVar('dukaFile.404',   $symbol, $fxtHour))) unlink($file);
        if (is_file($file=getVar('dukaFile.empty', $symbol, $fxtHour))) unlink($file);

        // ist das Flag $saveData gesetzt, Content speichern
        if ($saveData) {
            mkDirWritable(getVar('rostDir', $symbol, $gmtHour));
            $tmpFile = tempNam(dirName($file=getVar('dukaFile.compressed', $symbol, $gmtHour)), baseName($file));
            $hFile   = fOpen($tmpFile, 'wb');
            fWrite($hFile, $content);
            fClose($hFile);
            if (is_file($file)) unlink($file);
            rename($tmpFile, $file);                                       // So kann eine existierende Datei niemals korrupt sein.
        }
    }


    // (4) Download-Fehler: ist das Flag $saveError gesetzt, Fehler speichern
    else {
        if ($saveError) {                                                 // Fehlerdatei unter FXT-Namen speichern
            $file = getVar($status==404 ? 'dukaFile.404':'dukaFile.empty', $symbol, $fxtHour);
            mkDirWritable(dirName($file));
            fClose(fOpen($file, 'wb'));
        }

        if (!$quiet) {
            if ($status==404) echoPre('[Error]   '.$shortDate.'  url not found (404): '.$url);
            else              echoPre('[Warn]    '.$shortDate.'  empty response: '.$url);
        }

        // bei leerem Response Exception werfen, damit eine Schleife ggf. fortgesetzt werden kann
        if ($status != 404) throw new DukascopyException('empty response for url: '.$url);
    }
    return $content;
}


/**
 * Laedt die in einem komprimierten Dukascopy-Tickfile enthaltenen Ticks.
 *
 * @return array[] - Array mit Tickdaten
 */
function loadCompressedDukascopyTickFile($file, $symbol, $gmtHour, $fxtHour) {
    if (!is_string($file)) throw new IllegalTypeException('Illegal type of parameter $file: '.getType($file));
    if (!is_int($fxtHour)) throw new IllegalTypeException('Illegal type of parameter $fxtHour: '.getType($fxtHour));

    global $verbose;
    if ($verbose > 0) echoPre('[Info]    '.gmDate('D, d-M-Y H:i', $fxtHour).'  Dukascopy compressed tick file: '.Rost::relativePath($file));

    return loadCompressedDukascopyTickData(file_get_contents($file), $symbol, $gmtHour, $fxtHour);
}


/**
 * Laedt die in einem komprimierten String enthaltenen Dukascopy-Tickdaten.
 *
 * @return array[] - Array mit Tickdaten
 */
function loadCompressedDukascopyTickData($data, $symbol, $gmtHour, $fxtHour) {
    if (!is_int($gmtHour)) throw new IllegalTypeException('Illegal type of parameter $gmtHour: '.getType($gmtHour));

    global $saveRawDukascopyFiles;
    $saveAs = $saveRawDukascopyFiles ? getVar('dukaFile.raw', $symbol, $gmtHour) : null;

    $rawData = Dukascopy ::decompressHistoryData($data, $saveAs);
    return loadRawDukascopyTickData($rawData, $symbol, $gmtHour, $fxtHour);
}


/**
 * Laedt die in einem unkomprimierten Dukascopy-Tickfile enthaltenen Ticks.
 *
 * @return array[] - Array mit Tickdaten
 */
function loadRawDukascopyTickFile($file, $symbol, $gmtHour, $fxtHour) {
    if (!is_string($file)) throw new IllegalTypeException('Illegal type of parameter $file: '.getType($file));
    if (!is_int($fxtHour)) throw new IllegalTypeException('Illegal type of parameter $fxtHour: '.getType($fxtHour));

    global $verbose;
    if ($verbose > 0) echoPre('[Info]    '.gmDate('D, d-M-Y H:i', $fxtHour).'  Dukascopy uncompressed tick file: '.Rost::relativePath($file));

    return loadRawDukascopyTickData(file_get_contents($file), $symbol, $gmtHour, $fxtHour);
}


/**
 * Laedt die in einem unkomprimierten String enthaltenen Dukascopy-Tickdaten.
 *
 * @return array[] - Array mit Tickdaten
 */
function loadRawDukascopyTickData($data, $symbol, $gmtHour, $fxtHour) {
    if (!is_string($data)) throw new IllegalTypeException('Illegal type of parameter $data: '.getType($data));
    if (!is_int($gmtHour)) throw new IllegalTypeException('Illegal type of parameter $gmtHour: '.getType($gmtHour));
    if (!is_int($fxtHour)) throw new IllegalTypeException('Illegal type of parameter $fxtHour: '.getType($fxtHour));

    // Ticks einlesen
    $ticks = Dukascopy::readTickData($data);

    // GMT- und FXT-Timestamps hinzufuegen
    foreach ($ticks as &$tick) {
        $sec    = (int)($tick['timeDelta'] / 1000);
        $millis =       $tick['timeDelta'] % 1000;

        $tick['time_gmt']    = $gmtHour + $sec;
        $tick['time_fxt']    = $fxtHour + $sec;
        $tick['time_millis'] = $millis;
    }; unset($tick);
    return $ticks;
}


/**
 * Erzeugt und verwaltet dynamisch generierte Variablen.
 *
 * Evaluiert und cacht staendig wiederbenutzte dynamische Variablen an einem zentralen Ort. Vereinfacht die Logik,
 * da die Variablen nicht global gespeichert oder ueber viele Funktionsaufrufe hinweg weitergereicht werden muessen,
 * aber trotzdem nicht bei jeder Verwendung neu ermittelt werden brauchen.
 *
 * @param  string $id     - eindeutiger Bezeichner der Variable (ID)
 * @param  string $symbol - Symbol oder NULL
 * @param  int    $time   - Timestamp oder NULL
 *
 * @return string - Variable
 */
function getVar($id, $symbol=null, $time=null) {
    static $varCache = [];
    if (array_key_exists(($key=$id.'|'.$symbol.'|'.$time), $varCache))
        return $varCache[$key];

    if (!is_string($id))                       throw new IllegalTypeException('Illegal type of parameter $id: '.getType($id));
    if (isSet($symbol) && !is_string($symbol)) throw new IllegalTypeException('Illegal type of parameter $symbol: '.getType($symbol));
    if (isSet($time) && !is_int($time))        throw new IllegalTypeException('Illegal type of parameter $time: '.getType($time));

    static $dataDir; !$dataDir && $dataDir = Config::getDefault()->get('app.dir.data');
    $self = __FUNCTION__;

    if ($id == 'rostDirDate') {                 // $yyyy/$mmL/$dd                                               // lokales Pfad-Datum
        if (!$time)   throw new InvalidArgumentException('Invalid parameter $time: '.$time);
        $result = gmDate('Y/m/d', $time);
    }
    else if ($id == 'rostDir') {                // $dataDir/history/rost/$type/$symbol/$rostDirDate             // lokales Verzeichnis
        $type        = RosaSymbol::dao()->getByName($symbol)->getType();
        $rostDirDate = $self('rostDirDate', null, $time);
        $result      = $dataDir.'/history/rost/'.$type.'/'.$symbol.'/'.$rostDirDate;
    }
    else if ($id == 'rostFile.raw') {           // $rostDir/${hour}h_ticks.bin                                  // lokale Datei ungepackt
        $rostDir = $self('rostDir', $symbol, $time);
        $hour    = gmDate('H', $time);
        $result  = $rostDir.'/'.$hour.'h_ticks.bin';
    }
    else if ($id == 'rostFile.compressed') {    // $rostDir/${hour}h_ticks.rar                                  // lokale Datei gepackt
        $rostDir = $self('rostDir', $symbol, $time);
        $hour    = gmDate('H', $time);
        $result  = $rostDir.'/'.$hour.'h_ticks.rar';
    }
    else if ($id == 'dukaFile.raw') {           // $rostDir/${hour}h_ticks.bin                                  // Dukascopy-Datei ungepackt
        $rostDir = $self('rostDir', $symbol, $time);
        $hour    = gmDate('H', $time);
        $result  = $rostDir.'/'.$hour.'h_ticks.bin';
    }
    else if ($id == 'dukaFile.compressed') {    // $rostDir/${hour}h_ticks.bi5                                  // Dukascopy-Datei gepackt
        $rostDir = $self('rostDir', $symbol, $time);
        $hour    = gmDate('H', $time);
        $result  = $rostDir.'/'.$hour.'h_ticks.bi5';
    }
    else if ($id == 'dukaUrlDate') {            // $yyyy/$mmD/$dd                                               // Dukascopy-URL-Datum
        if (!$time) throw new InvalidArgumentException('Invalid parameter $time: '.$time);
        $yyyy   = gmDate('Y', $time);
        $mmD    = strRight((string)(gmDate('m', $time)+99), 2);  // Januar = 00
        $dd     = gmDate('d', $time);
        $result = $yyyy.'/'.$mmD.'/'.$dd;
    }
    else if ($id == 'dukaUrl') {                // http://datafeed.dukascopy.com/datafeed/$symbol/$dukaUrlDate/${hour}h_ticks.bi5
        if (!$symbol) throw new InvalidArgumentException('Invalid parameter $symbol: '.$symbol);
        $dukaUrlDate = $self('dukaUrlDate', null, $time);
        $hour        = gmDate('H', $time);
        $result      = 'http://datafeed.dukascopy.com/datafeed/'.$symbol.'/'.$dukaUrlDate.'/'.$hour.'h_ticks.bi5';
    }
    else if ($id == 'dukaFile.404') {           // $rostDir/${hour}h_ticks.404                                  // Download-Fehler 404
        $rostDir = $self('rostDir', $symbol, $time);
        $hour    = gmDate('H', $time);
        $result  = $rostDir.'/'.$hour.'h_ticks.404';
    }
    else if ($id == 'dukaFile.empty') {         // $rostDir/${hour}h_ticks.na                                   // Download-Fehler leerer Response
        $rostDir = $self('rostDir', $symbol, $time);
        $hour    = gmDate('H', $time);
        $result  = $rostDir.'/'.$hour.'h_ticks.na';
    }
    else {
      throw new InvalidArgumentException('Unknown variable identifier "'.$id.'"');
    }

    $varCache[$key] = $result;
    (sizeof($varCache) > ($maxSize=128)) && array_shift($varCache) /*&& echoPre('cache size limit of '.$maxSize.' hit')*/;

    return $result;
}


/**
 * Hilfefunktion: Zeigt die Syntax des Aufrufs an.
 *
 * @param  string $message [optional] - zusaetzlich zur Syntax anzuzeigende Message (default: keine)
 */
function help($message = null) {
    if (isSet($message))
        echo $message.NL.NL;

    $self = baseName($_SERVER['PHP_SELF']);

echo <<<HELP

 Syntax:  $self [symbol ...]

 Options:  -v    Verbose output.
           -vv   More verbose output.
           -vvv  Very verbose output.
           -h    This help screen.


HELP;
}
