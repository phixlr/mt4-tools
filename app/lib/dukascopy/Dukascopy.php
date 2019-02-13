<?php
namespace rosasurfer\rt\dukascopy;

use rosasurfer\core\Object;
use rosasurfer\exception\IllegalArgumentException;
use rosasurfer\exception\IllegalTypeException;
use rosasurfer\exception\InvalidArgumentException;
use rosasurfer\exception\RuntimeException;
use rosasurfer\file\FileSystem as FS;
use rosasurfer\log\Logger;
use rosasurfer\net\http\HttpResponse;

use rosasurfer\rt\LZMA;
use rosasurfer\rt\model\DukascopySymbol;

use function rosasurfer\rt\periodToStr;

use const rosasurfer\rt\DUKASCOPY_BAR_SIZE;
use const rosasurfer\rt\DUKASCOPY_TICK_SIZE;
use const rosasurfer\rt\PERIOD_M1;


/**
 * Dukascopy
 *
 * Functionality for downloading and processing Dukascopy history data.
 *
 *
 * // big-endian
 * struct DUKASCOPY_HISTORY_START {     // -- offset --- size --- description -----------------------------------------------
 *     char      start;                 //         0        1     symbol start marker (always NULL)
 *     char      length;                //         1        1     length of the following symbol name
 *     char      symbol[length];        //         2 {length}     symbol name (no terminating NULL character)
 *     int64     count;                 //  variable        8     number of timeframe start records to follow
 *     {record};                        //  variable       16     struct DUKASCOPY_TIMEFRAME_START
 *     ...                              //  variable       16     struct DUKASCOPY_TIMEFRAME_START
 *     {record};                        //  variable       16     struct DUKASCOPY_TIMEFRAME_START
 * };                                   // ----------------------------------------------------------------------------------
 *                                      //                = 2 + {length} + {count}*16
 *
 * // big-endian
 * struct DUKASCOPY_TIMEFRAME_START {   // -- offset --- size --- description -----------------------------------------------
 *     int64 timeframe;                 //         0        8     period length in minutes as a Java timestamp (msec)
 *     int64 time;                      //         8        8     start time as a Java timestamp (msec), PHP_INT_MAX = n/a
 * };                                   // ----------------------------------------------------------------------------------
 *                                      //               = 16
 *
 * // big-endian
 * struct DUKASCOPY_BAR {               // -- offset --- size --- description -----------------------------------------------
 *     uint  timeDelta;                 //         0        4     time difference in seconds since 00:00 GMT
 *     uint  open;                      //         4        4     in point
 *     uint  close;                     //         8        4     in point
 *     uint  low;                       //        12        4     in point
 *     uint  high;                      //        16        4     in point
 *     float volume;                    //        20        4
 * };                                   // ----------------------------------------------------------------------------------
 *                                      //               = 24
 *
 * // big-endian
 * struct DUKASCOPY_TICK {              // -- offset --- size --- description -----------------------------------------------
 *     uint  timeDelta;                 //         0        4     time difference in msec since start of the hour
 *     uint  ask;                       //         4        4     in point
 *     uint  bid;                       //         8        4     in point
 *     float askSize;                   //        12        4     cumulated ask size in lot (min. 1)
 *     float bidSize;                   //        16        4     cumulated bid size in lot (min. 1)
 * };                                   // ----------------------------------------------------------------------------------
 *                                      //               = 20
 */
class Dukascopy extends Object {


    /** @var HttpClient */
    protected $httpClient;


    /**
     * Resolve and return a Dukascopy specific HTTP client. The instance is kept in memory to enable "keep-alive" connections.
     *
     * @return HttpClient
     */
    protected function getHttpClient() {
        if (!$this->httpClient) {
            $options = [];
            $options[CURLOPT_SSL_VERIFYPEER] = false;       // suppress SSL certificate validation errors
            //$options[CURLOPT_VERBOSE     ] = true;
            $this->httpClient = new HttpClient($options);
        }
        return $this->httpClient;
    }


    /**
     * Fetch history start for the specified symbol from Dukascopy.
     *
     * @param  string $symbol
     *
     * @return int - FXT timestamp or 0 (zero) if history start info is not available
     */
    public function fetchHistoryStart($symbol) {
        $data = $this->downloadHistoryStart($symbol);
        //$root = $this->di()['config']['app.dir.root'];
        //$data = file_get_contents($root.'/bin/dukascopy/HistoryStart.AUDUSD.bi5');

        if (strlen($data)) {
            $times = $this->readHistoryStart($data);

            $dates = [];
            foreach ($times as $timeframe => $time) {
                $datetime = \DateTime::createFromFormat(is_int($time) ? 'U':'U.u', is_int($time) ? (string)$time : number_format($time, 6, '.', ''));
                $dates[str_pad(periodToStr($timeframe), 12)] = $datetime->format('D, d-M-Y H:i:s'.(is_int($time) ? '':'.u'));
            }
            echoPre($dates);

            return $times[PERIOD_M1];
        }
        return 0;
    }


    /**
     * Fetch history start times from Dukascopy for all available symbols. Returns a list of arrays with history start times
     * for each available symbol.
     *
     * @return array[] - list of arrays in a format as follows:
     *
     * <pre>
     * Array [
     *     {symbol} => Array [
     *         {timeframe-id} => {timestamp},       // e.g.: PERIOD_TICKS => Mon, 04-Aug-2003 10:03:02.837,
     *         {timeframe-id} => {timestamp},       //       PERIOD_M1    => Mon, 04-Aug-2003 10:03:00,
     *         {timeframe-id} => {timestamp},       //       PERIOD_H1    => Mon, 04-Aug-2003 10:00:00,
     *         ...                                  //       PERIOD_D1    => Mon, 25-Nov-1991 00:00:00,
     *     ],
     *     {symbol} => Array [
     *         {timeframe-id} => {timestamp},
     *         ...
     *     ],
     *     ...
     * ]
     * </pre>
     */
    public function fetchHistoryStarts() {
        $data = $this->downloadHistoryStarts();
        //$data = file_get_contents($this->di()['config']['app.dir.root'].'/bin/dukascopy/HistoryStart.All.bi5');

        if (strlen($data)) {
            $symbols = $this->readHistoryStarts($data);

            $results = [];
            foreach ($symbols as $name => $timeframes) {
                $dates = [];
                foreach ($timeframes as $timeframe => $time) {
                    $datetime = \DateTime::createFromFormat(is_int($time) ? 'U':'U.u', is_int($time) ? (string)$time : number_format($time, 6, '.', ''));
                    $dates[str_pad(periodToStr($timeframe), 12)] = $datetime->format('D, d-M-Y H:i:s'.(is_int($time) ? '':'.u'));
                }
                $results[$name] = $dates;
            }
            echoPre($results);
            echoPre(sizeof($results).' symbols');

            return $symbols;
        }
        return [];
    }


    /**
     * Download history start data for the specified symbol.
     *
     * @param  string $symbol
     *
     * @return string - binary history start data or an empty string in case of errors
     */
    protected function downloadHistoryStart($symbol) {
        $url = 'http://datafeed.dukascopy.com/datafeed/'.$symbol.'/metadata/HistoryStart.bi5';

        $request  = new HttpRequest($url);
        $response = $this->getHttpClient()->send($request);
        $status   = $response->getStatus();
        if ($status!=200 && $status!=404) throw new RuntimeException('Unexpected HTTP status '.$status.' ('.HttpResponse::$sc[$status].') for url "'.$url.'"'.NL.printPretty($response, true));

        // treat an empty response as error 404
        $content = $response->getContent();
        if (!strlen($content))
            $status = 404;
        if ($status == 404) echoPre('[Error]   URL not found (404): '.$url);

        return ($status==200) ? $response->getContent() : '';
    }


    /**
     * Download all available history start data.
     *
     * @return string - binary history start data or an empty string in case of errors
     */
    protected function downloadHistoryStarts() {
        $url = 'http://datafeed.dukascopy.com/datafeed/metadata/HistoryStart.bi5';

        $request  = new HttpRequest($url);
        $response = $this->getHttpClient()->send($request);
        $status   = $response->getStatus();
        if ($status!=200 && $status!=404) throw new RuntimeException('Unexpected HTTP status '.$status.' ('.HttpResponse::$sc[$status].') for url "'.$url.'"'.NL.printPretty($response, true));

        // treat an empty response as error 404
        $content = $response->getContent();
        if (!strlen($content))
            $status = 404;
        if ($status == 404) echoPre('[Error]   URL not found (404): '.$url);

        return ($status==200) ? $response->getContent() : '';
    }


    /**
     * Decompress a compressed Dukascopy data string and return it.
     *
     * @param  string $data              - compressed string with bars or ticks
     * @param  string $saveAs [optional] - if specified the decompressed string is additionally stored in the given file
     *                                     (default: no storage)
     *
     * @return string - decompressed data string
     */
    public static function decompressHistoryData($data, $saveAs = null) {
        if (!is_string($data))       throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));
        if (isset($saveAs)) {
            if (!is_string($saveAs)) throw new IllegalTypeException('Illegal type of parameter $saveAs: '.gettype($saveAs));
            if (!strlen($saveAs))    throw new InvalidArgumentException('Invalid parameter $saveAs: ""');
        }

        $rawData = LZMA::decompressData($data);

        if (isset($saveAs)) {
            FS::mkDir(dirname($saveAs));
            $tmpFile = tempnam(dirname($saveAs), basename($saveAs));
            file_put_contents($tmpFile, $rawData);
            if (is_file($saveAs)) unlink($saveAs);
            rename($tmpFile, $saveAs);
        }
        return $rawData;
    }


    /**
     * Decompress a compressed Dukascopy data file and return its content.
     *
     * @param  string $compressedFile    - name of the compressed data file
     * @param  string $saveAs [optional] - if specified the decompressed content is additionally stored in the given file
     *                                     (default: no storage)
     *
     * @return string - decompressed file content
     */
    public static function decompressHistoryFile($compressedFile, $saveAs = null) {
        if (!is_string($compressedFile)) throw new IllegalTypeException('Illegal type of parameter $compressedFile: '.gettype($compressedFile));
        return self::decompressHistoryData(file_get_contents($compressedFile), $saveAs);
    }


    /**
     * Parse a string with Dukascopy bar data and convert it to a timeseries array.
     *
     * @param  string $data   - string with Dukascopy bar data
     * @param  string $symbol - Dukascopy symbol
     * @param  string $type   - meta info for error message generation
     * @param  int    $time   - ditto
     *
     * @return array[] - DUKASCOPY_BAR[] data as a timeseries array
     */
    public static function readBarData($data, $symbol, $type, $time) {
        /** @var DukascopySymbol $dukaSymbol */
        $dukaSymbol = DukascopySymbol::dao()->getByName($symbol);
        $symbol     = $dukaSymbol->getName();
        $digits     = $dukaSymbol->getDigits();
        $divider    = pow(10, $digits);

        if (!is_string($data))                        throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));
        $lenData = strlen($data);
        if (!$lenData || $lenData%DUKASCOPY_BAR_SIZE) throw new RuntimeException('Odd length of passed '.$symbol.' '.$type.' data: '.$lenData.' (not an even DUKASCOPY_BAR_SIZE)');

        $offset  = 0;
        $bars    = [];
        $i       = -1;

        static $isLittleEndian = null; is_null($isLittleEndian) && $isLittleEndian=isLittleEndian();

        while ($offset < $lenData) {
            $i++;
            $bars[] = unpack("@$offset/NtimeDelta/Nopen/Nclose/Nlow/Nhigh", $data);
            $s      = substr($data, $offset+20, 4);
            $lots   = unpack('f', $isLittleEndian ? strrev($s) : $s);   // unpack doesn't support explicit big-endian floats, on little-endian
            $bars[$i]['lots'] = round($lots[1], 2);                     // machines the byte order of field "lots" must be reversed manually
            $offset += DUKASCOPY_BAR_SIZE;

            // validate bar data
            if ($bars[$i]['open' ] > $bars[$i]['high'] ||               // from (H >= O && O >= L) follws (H >= L)
                $bars[$i]['open' ] < $bars[$i]['low' ] ||               // don't use min()/max() as it's slow
                $bars[$i]['close'] > $bars[$i]['high'] ||
                $bars[$i]['close'] < $bars[$i]['low' ]) {

                $O = number_format($bars[$i]['open' ]/$divider, $digits);
                $H = number_format($bars[$i]['high' ]/$divider, $digits);
                $L = number_format($bars[$i]['low'  ]/$divider, $digits);
                $C = number_format($bars[$i]['close']/$divider, $digits);

                Logger::log("Illegal ".$symbol." $type data for bar[$i] of ".gmdate('D, d-M-Y H:i:s', $time).": O=$O H=$H L=$L C=$C, adjusting high/low...", L_WARN);

                $bars[$i]['high'] = max($bars[$i]['open'], $bars[$i]['high'], $bars[$i]['low'], $bars[$i]['close']);
                $bars[$i]['low' ] = min($bars[$i]['open'], $bars[$i]['high'], $bars[$i]['low'], $bars[$i]['close']);
            }
        }
        return $bars;
    }


    /**
     * Parse a file with Dukascopy bar data and convert it to a data array.
     *
     * @param  string $fileName - name of file with Dukascopy bar data
     * @param  string $symbol   - meta infos for generating better error messages (Dukascopy data may contain errors)
     * @param  string $type     - ...
     * @param  int    $time     - ...
     *
     * @return array - DUKASCOPY_BAR[] data
     */
    public static function readBarFile($fileName, $symbol, $type, $time) {
        if (!is_string($fileName)) throw new IllegalTypeException('Illegal type of parameter $fileName: '.gettype($fileName));
        return self::readBarData(file_get_contents($fileName), $symbol, $type, $time);
    }


    /**
     * Parse a string with Dukascopy tick data and convert it to a data array.
     *
     * @param  string $data - string with Dukascopy tick data
     *
     * @return array - DUKASCOPY_TICK[] data
     */
    public static function readTickData($data) {
        if (!is_string($data)) throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));

        $lenData = strlen($data); if (!$lenData || $lenData%DUKASCOPY_TICK_SIZE) throw new RuntimeException('Odd length of passed data: '.$lenData.' (not an even DUKASCOPY_TICK_SIZE)');
        $offset  = 0;
        $ticks   = [];
        $i       = -1;

        static $isLittleEndian = null; is_null($isLittleEndian) && $isLittleEndian=isLittleEndian();

        // unpack doesn't support explicit big-endian floats, on little-endian machines the byte order
        // of fields "bidSize" and "askSize" has to be reversed manually
        while ($offset < $lenData) {
            $i++;
            $ticks[] = unpack("@$offset/NtimeDelta/Nask/Nbid", $data);
            $s1      = substr($data, $offset+12, 4);
            $s2      = substr($data, $offset+16, 4);
            $size    = unpack('fask/fbid', $isLittleEndian ? strrev($s1).strrev($s2) : $s1.$s2);    // manually reverse
            $ticks[$i]['askSize'] = round($size['ask'], 2);                                         // on little-endian machines
            $ticks[$i]['bidSize'] = round($size['bid'], 2);
            $offset += DUKASCOPY_TICK_SIZE;
        }
        return $ticks;
    }


    /**
     * Parse a file with Dukascopy tick data and convert it to a data array.
     *
     * @param  string $fileName - name of file with Dukascopy tick data
     *
     * @return array - DUKASCOPY_TICK[] data
     */
    public static function readTickFile($fileName) {
        if (!is_string($fileName)) throw new IllegalTypeException('Illegal type of parameter $fileName: '.gettype($fileName));
        return self::readTickData(file_get_contents($fileName));
    }


    /**
     * Parse a string with a single symbol's history start records.
     *
     * @param  string $data - binary data
     *
     * @return array - array with variable number of elements each describing history start of a single timeframe
     *                 as follows:
     * <pre>
     * Array [
     *     {timeframe-id} => {timestamp},       // e.g.: PERIOD_TICKS => Mon, 04-Aug-2003 10:03:02.837,
     *     {timeframe-id} => {timestamp},       //       PERIOD_M1    => Mon, 04-Aug-2003 10:03:00,
     *     {timeframe-id} => {timestamp},       //       PERIOD_H1    => Mon, 04-Aug-2003 10:00:00,
     *     ...                                  //       PERIOD_D1    => Mon, 25-Nov-1991 00:00:00,
     * ]
     * </pre>
     */
    public static function readHistoryStart($data) {
        if (!is_string($data))          throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));
        $lenData = strlen($data);
        if (!$lenData || $lenData % 16) throw new IllegalArgumentException('Illegal length of history start data: '.$lenData);

        $timeframes = [];
        $offset     = 0;

        while ($offset < $lenData) {
            $timeframes += self::readHistoryStartRecord($data, $offset);
            $offset += 16;
        }
        ksort($timeframes);
        return $timeframes;
    }


    /**
     * Parse a string with history start records of multiple symbols.
     *
     * @param  string $data - binary data
     *
     * @return array - array with variable number of elements each describing history start of a single timeframe
     *                 as follows:
     * <pre>
     * Array [
     *     {timeframe-id} => {timestamp},       // e.g.: PERIOD_TICKS => Mon, 04-Aug-2003 10:03:02.837,
     *     {timeframe-id} => {timestamp},       //       PERIOD_M1    => Mon, 04-Aug-2003 10:03:00,
     *     {timeframe-id} => {timestamp},       //       PERIOD_H1    => Mon, 04-Aug-2003 10:00:00,
     *     ...                                  //       PERIOD_D1    => Mon, 25-Nov-1991 00:00:00,
     * ]
     * </pre>
     */
    public static function readHistoryStarts($data) {
        if (!is_string($data)) throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));
        $lenData = strlen($data);
        if (!$lenData)         throw new IllegalArgumentException('Illegal length of history start data: '.$lenData);

        $symbols = [];
        $start   = $length = $symbol = $high = $count = null;
        $offset  = 0;

        while ($offset < $lenData) {
            extract(unpack("@$offset/Cstart/Clength", $data));
            if ($start)                     throw new RuntimeException('Unexpected data format in DUKASCOPY_HISTORY_START at offset '.$offset.': start='.$start);
            $offset += 2;
            extract(unpack("@$offset/A${length}symbol/Nhigh/Ncount", $data));
            if (strlen($symbol) != $length) throw new RuntimeException('Unexpected data format in DUKASCOPY_HISTORY_START at offset '.$offset.': symbol="'.$symbol.'"  length='.$length);
            if ($high)                      throw new RuntimeException('Unexpected data format in DUKASCOPY_HISTORY_START at offset '.($offset+$length).': highInt='.$high);
            if ($count != 4)                throw new RuntimeException('Unexpected data format in DUKASCOPY_HISTORY_START at offset '.($offset+$length+1).': count='.$count);
            $offset += $length + 8;

            $timeframes = [];
            while ($count) {
                $timeframes += self::readHistoryStartRecord($data, $offset);
                $offset += 16;
                $count--;
            }
            if ($timeframes) {                                                  // skip symbols without history
                ksort($timeframes);
                $symbols[$symbol] = $timeframes;
            }
        }
        ksort($symbols);
        return $symbols;
    }


    /**
     * Parse a DUKASCOPY_TIMEFRAME_START record at the given offset of a binary string.
     *
     * @param  string $data   - binary data
     * @param  int    $offset - offset
     *
     * @return array - a key-value pair [{timeframe-id} => {timestamp}] or an empty array if history of the given timeframe
     *                 is not available
     */
    protected static function readHistoryStartRecord($data, $offset) {
        // check if 64-bit format codes are supported
        if (PHP_INT_SIZE == 8) {
            $record = unpack("@$offset/J2", $data);
            $timeframe = $record[1] / 1000 / MINUTES;
            if (!is_int($timeframe) || (string)$timeframe==periodToStr($timeframe)) throw new RuntimeException('Unexpected Dukascopy history timeframe identifier: '.$record[1]);
            $record[1] = $timeframe;
            if ($record[2] < 0) throw new \RangeException('Invalid Java timestamp: '.sprintf('%u', $record[2]).' (out of range)');
            if ($record[2] == PHP_INT_MAX)
                return [];                                                      // history not available
            if ($record[2] % 1000) $record[2] = round($record[2]/1000, 3);
            else                   $record[2] = (int)($record[2]/1000);
        }
        else {
            // 32-bit integers: 64-bit format codes are not supported
            $ints = unpack("@$offset/N4", $data);
            $record = [];
            foreach ($ints as $i => $int) {
                $int = sprintf('%u', $int);
                if ($i % 2) $record[($i+1)/2] = bcmul($int, '4294967296', 0);   // 2 ^ 32
                else        $record[ $i=$i/2] = bcadd($record[$i], $int, 0);
            }
            /** @var int $timeframe */
            $timeframe = ((int) bcdiv($record[1], '1000', 0)) / MINUTES;
            if (!is_int($timeframe) || (string)$timeframe==periodToStr($timeframe)) throw new RuntimeException('Unexpected Dukascopy history timeframe identifier: '.$record[1]);
            $record[1] = $timeframe;
            if ($record[2] == '9223372036854775807')                            // history not available
                return [];
            if (!bcmod($record[2], '1000')) $record[2] =   (int) bcdiv($record[2], '1000', 0);
            else                            $record[2] = (float) bcdiv($record[2], '1000', 3);
        }
        return [$record[1] => $record[2]];
    }
}
