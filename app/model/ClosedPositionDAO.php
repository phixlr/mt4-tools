<?php
namespace rosasurfer\xtrade\model;

use rosasurfer\db\orm\DAO;

use rosasurfer\exception\IllegalTypeException;
use rosasurfer\exception\InvalidArgumentException;

use const rosasurfer\PHP_TYPE_FLOAT;
use const rosasurfer\PHP_TYPE_INT;
use const rosasurfer\PHP_TYPE_STRING;


/**
 * DAO zum Zugriff auf ClosedPosition-Instanzen.
 */
class ClosedPositionDAO extends DAO {


    /**
     * {@inheritdoc}
     */
    public function getMapping() {
        static $mapping; return $mapping ?: ($mapping=$this->parseMapping([
            'class'      => ClosedPosition::class,
            'table'      => 't_closedposition',
            'connection' => 'mysql',
            'properties' => [
                ['name'=>'id'         , 'type'=>PHP_TYPE_INT   , 'primary'=>true   ],      // db:int
                ['name'=>'created'    , 'type'=>PHP_TYPE_STRING,                   ],      // db:datetime
                ['name'=>'version'    , 'type'=>PHP_TYPE_STRING, 'version'=>true   ],      // db:datetime

                ['name'=>'ticket'     , 'type'=>PHP_TYPE_INT   ,                   ],      // db:int
                ['name'=>'type'       , 'type'=>PHP_TYPE_STRING,                   ],      // db:string
                ['name'=>'lots'       , 'type'=>PHP_TYPE_FLOAT ,                   ],      // db:decimal
                ['name'=>'symbol'     , 'type'=>PHP_TYPE_STRING,                   ],      // db:string
                ['name'=>'openTime'   , 'type'=>PHP_TYPE_STRING,                   ],      // db:datetime
                ['name'=>'openPrice'  , 'type'=>PHP_TYPE_FLOAT ,                   ],      // db:decimal
                ['name'=>'closeTime'  , 'type'=>PHP_TYPE_STRING,                   ],      // db:datetime
                ['name'=>'closePrice' , 'type'=>PHP_TYPE_FLOAT ,                   ],      // db:decimal
                ['name'=>'stopLoss'   , 'type'=>PHP_TYPE_FLOAT ,                   ],      // db:decimal
                ['name'=>'takeProfit' , 'type'=>PHP_TYPE_FLOAT ,                   ],      // db:decimal
                ['name'=>'commission' , 'type'=>PHP_TYPE_FLOAT ,                   ],      // db:decimal
                ['name'=>'swap'       , 'type'=>PHP_TYPE_FLOAT ,                   ],      // db:decimal
                ['name'=>'grossProfit', 'type'=>PHP_TYPE_FLOAT , 'column'=>'profit'],      // db:decimal
                ['name'=>'netProfit'  , 'type'=>PHP_TYPE_FLOAT ,                   ],      // db:decimal
                ['name'=>'magicNumber', 'type'=>PHP_TYPE_INT   ,                   ],      // db:int
                ['name'=>'comment'    , 'type'=>PHP_TYPE_STRING,                   ],      // db:string
                ['name'=>'signal_id'  , 'type'=>PHP_TYPE_INT   ,                   ],      // db:int
            ],
            'relations' => [
                ['name'=>'signal', 'relation'=>'many-to-one', 'type'=>Signal::class, 'column'=>'signal_id'],
            ],
        ]));
    }


    /**
     * Ob das angegebene Ticket zum angegebenen Signal existiert.
     *
     * @param  Signal $signal - Signal
     * @param  int    $ticket - zu pruefendes Ticket
     *
     * @return bool
     */
    public function isTicket($signal, $ticket) {
        if (!$signal->isPersistent()) throw new InvalidArgumentException('Cannot process non-persistent '.get_class($signal));
        if (!is_int($ticket))         throw new IllegalTypeException('Illegal type of parameter $ticket: '.getType($ticket));

        $signal_id = $signal->getId();

        $sql = "select count(*)
                      from :ClosedPosition c
                      where c.signal_id = $signal_id
                         and c.ticket    = $ticket";
        return $this->query($sql)->fetchBool();
    }


    /**
     * Gibt die geschlossenen Positionen des angegebenen Signals zurueck.
     *
     * @param  Signal $signal      - Signal
     * @param  bool   $assocTicket - ob das Ergebnisarray assoziativ nach Tickets organisiert werden soll (default: nein)
     *
     * @return ClosedPosition[] - Array von ClosedPosition-Instanzen, aufsteigend sortiert nach {CloseTime,OpenTime,Ticket}
     */
    public function listBySignal(Signal $signal, $assocTicket=false) {
        if (!$signal->isPersistent()) throw new InvalidArgumentException('Cannot process non-persistent '.get_class($signal));
        return $this->listBySignalAlias($signal->getAlias(), $assocTicket);
    }


    /**
     * Gibt die geschlossenen Positionen des angegebenen Signals zurueck.
     *
     * @param  string $alias       - Signalalias
     * @param  bool   $assocTicket - ob das Ergebnisarray assoziativ nach Tickets organisiert werden soll (default: nein)
     *
     * @return ClosedPosition[] - Array von ClosedPosition-Instanzen, aufsteigend sortiert nach {CloseTime,OpenTime,Ticket}
     */
    public function listBySignalAlias($alias, $assocTicket=false) {
        if (!is_string($alias)) throw new IllegalTypeException('Illegal type of parameter $alias: '.getType($alias));

        $alias = $this->escapeLiteral($alias);

        $sql = "select c.*
                   from :Signal         s
                   join :ClosedPosition c on s.id = c.signal_id
                   where s.alias = $alias
                   order by c.closetime, c.opentime, c.ticket";
        /** @var ClosedPosition[] $results */
        $results = $this->findAll($sql);

        if ($assocTicket) {
            foreach ($results as $i => $position) {
                $results[(string) $position->getTicket()] = $position;
                unset($results[$i]);
            }
        }
        return $results;
    }
}
