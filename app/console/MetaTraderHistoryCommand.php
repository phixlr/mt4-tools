<?php
namespace rosasurfer\rt\console;

use rosasurfer\console\Command;
use rosasurfer\console\io\Input;
use rosasurfer\console\io\Output;
use rosasurfer\core\exception\IllegalStateException;
use rosasurfer\process\Process;

use rosasurfer\rt\lib\metatrader\MetaTrader;
use rosasurfer\rt\model\RosaSymbol;


/**
 * MetaTraderHistoryCommand
 *
 * A {@link Command} to work with MetaTrader history files.
 */
class MetaTraderHistoryCommand extends Command {


    /** @var string */
    const DOCOPT = <<<'DOCOPT'

Create new MetaTrader history files.

Usage:
  rt-metatrader-history  create SYMBOL [options]

Commands:
  create       Create new MetaTrader history files (all standard timeframes) for the specified symbol.

Arguments:
  SYMBOL       The symbol to process history for.

Options:
   -h, --help  This help screen.

DOCOPT;
//Create, update or show status of MetaTrader history files.


    /**
     * {@inheritdoc}
     *
     * @return $this
     */
    protected function configure() {
        $this->setDocoptDefinition(self::DOCOPT);
        return $this;
    }


    /**
     * {@inheritdoc}
     *
     * @return int - execution status (0 for success)
     */
    protected function execute(Input $input, Output $output) {
        $symbol = $this->resolveSymbol();
        if (!$symbol) return $this->status;

        $start = (int) $symbol->getHistoryStartM1('U');
        $end   = (int) $symbol->getHistoryEndM1('U');           // starttime of the last bar
        if (!$start) {
            $output->out('[Info]    '.str_pad($symbol->getName(), 6).'  no Rosatrader history available');
            return 1;
        }
        if (!$end) throw new IllegalStateException('Rosatrader history start/end time mis-match for '.$symbol->getName().':  start='.$start.'  end='.$end);

        /** @var MetaTrader $metatrader */
        $metatrader = $this->di(MetaTrader::class);
        $historySet = $metatrader->createHistorySet($symbol);

        // iterate over existing history
        for ($day=$start, $lastMonth=0; $day <= $end; $day+=1*DAY) {
            $month = (int) gmdate('m', $day);
            if ($month != $lastMonth) {
                $output->out('[Info]    '.gmdate('M-Y', $day));
                $lastMonth = $month;
            }
            if ($symbol->isTradingDay($day)) {
                if (!$bars = $symbol->getHistoryM1($day, $optimized=true))
                    return 1;
                $historySet->appendBars($bars);
                Process::dispatchSignals();                     // check for Ctrl-C
            }
        }
        $historySet->close();
        $output->out('[Ok]      '.$symbol->getName());

        return 0;
    }


    /**
     * Resolve the symbol to process.
     *
     * @return RosaSymbol|null
     */
    protected function resolveSymbol() {
        /** @var Input $input */
        $input = $this->di(Input::class);
        /** @var Output $output */
        $output = $this->di(Output::class);

        $name = $input->getArgument('SYMBOL');

        if (!$symbol = RosaSymbol::dao()->findByName($name)) {
            $output->error('Unknown Rosatrader symbol "'.$name.'"');
            $this->status = 1;
        }
        return $symbol;
    }
}
