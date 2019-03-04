<?php
namespace rosasurfer\rt\lib\synthetic\index;

use rosasurfer\exception\IllegalTypeException;
use rosasurfer\exception\UnimplementedFeatureException;

use rosasurfer\rt\lib\synthetic\AbstractSynthesizer;
use rosasurfer\rt\lib\synthetic\SynthesizerInterface as Synthesizer;

use function rosasurfer\rt\periodToStr;

use const rosasurfer\rt\PERIOD_M1;


/**
 * ZARFXI synthesizer
 *
 * A {@link Synthesizer} for calculating the South African Rand currency index. Due to the Rand's low value the index is
 * scaled-up by a factor of 10. This adjustment only effects the nominal scala, not the shape of the ZAR index chart.
 *
 * <pre>
 * Formulas:
 * ---------
 * ZARFXI = 10 * USDLFX / USDZAR
 * ZARFXI = 10 * pow(USDCAD * USDCHF *USDJPY / (AUDUSD * EURUSD * GBPUSD), 1/7) / USDZAR
 * ZARFXI = 10 * pow(ZARJPY / (AUDZAR * CADZAR * CHFZAR * EURZAR * GBPZAR * USDZAR), 1/7)
 * </pre>
 */
class ZARFXI extends AbstractSynthesizer {


    /** @var string[][] */
    protected $components = [
        'fast'    => ['USDLFX', 'USDZAR'],
        'majors'  => ['AUDUSD', 'EURUSD', 'GBPUSD', 'USDCAD', 'USDCHF', 'USDJPY', 'USDZAR'],
        'crosses' => ['AUDZAR', 'CADZAR', 'CHFZAR', 'EURZAR', 'GBPZAR', 'USDZAR', 'ZARJPY'],
    ];


    /**
     * {@inheritdoc}
     */
    public function getHistory($timeframe, $time) {
        if (!is_int($timeframe))     throw new IllegalTypeException('Illegal type of parameter $timeframe: '.gettype($timeframe));
        if (!is_int($time))          throw new IllegalTypeException('Illegal type of parameter $time: '.gettype($time));
        if ($timeframe != PERIOD_M1) throw new UnimplementedFeatureException(__METHOD__.'('.periodToStr($timeframe).') not implemented');

        if (!$symbols = $this->loadComponents(first($this->components)))
            return [];
        if (!$time && !($time = $this->findCommonHistoryStartM1($symbols)))     // if no time was specified find the oldest available history
            return [];
        if (!$this->symbol->isTradingDay($time))                                // skip non-trading days
            return [];
        if (!$quotes = $this->loadComponentHistory($symbols, $time))
            return [];

        // calculate quotes
        echoPre('[Info]    '.str_pad($this->symbolName, 6).'  calculating M1 history for '.gmdate('D, d-M-Y', $time));
        $USDZAR = $quotes['USDZAR'];
        $USDLFX = $quotes['USDLFX'];

        $digits = $this->symbol->getDigits();
        $point  = $this->symbol->getPoint();
        $bars   = [];

        // ZARFXI = 10 * USDLFX / USDZAR
        foreach ($USDZAR as $i => $bar) {
            $usdzar = $USDZAR[$i]['open'];
            $usdlfx = $USDLFX[$i]['open'];
            $open   = 10 * $usdlfx / $usdzar;
            $open   = round($open, $digits);
            $iOpen  = (int) round($open/$point);

            $usdzar = $USDZAR[$i]['close'];
            $usdlfx = $USDLFX[$i]['close'];
            $close  = 10 * $usdlfx / $usdzar;
            $close  = round($close, $digits);
            $iClose = (int) round($close/$point);

            $bars[$i]['time' ] = $bar['time'];
            $bars[$i]['open' ] = $open;
            $bars[$i]['high' ] = $iOpen > $iClose ? $open : $close;         // no min()/max() for performance
            $bars[$i]['low'  ] = $iOpen < $iClose ? $open : $close;
            $bars[$i]['close'] = $close;
            $bars[$i]['ticks'] = $iOpen==$iClose ? 1 : (abs($iOpen-$iClose) << 1);
        }
        return $bars;
    }
}
