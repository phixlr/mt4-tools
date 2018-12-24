<?php
namespace rosasurfer\rost;

use rosasurfer\rost\view\ViewHelper;


/**
 * Validator
 */
class Validator extends \rosasurfer\util\Validator {


    /**
     * Ob der uebergebene Wert ein gueltiger OperationType-Identifier ist.
     *
     * @param  int $type - zu pruefender Wert
     *
     * @return bool
     */
    public static function isOperationType($type) {
        return (is_int($type) && isSet(ViewHelper::$operationTypes[$type]));
    }


    /**
     * Ob der uebergebene Wert ein gueltiger MT4-OperationType-Identifier ist.
     *
     * @param  int $type - zu pruefender Wert
     *
     * @return bool
     */
    public static function isMT4OperationType($type) {
        return (self::isOperationType($type) && $type <= OP_CREDIT);
    }


    /**
     * Ob der uebergebene Wert ein gueltiger Custom-OperationType-Identifier ist.
     *
     * @param  int $type - zu pruefender Wert
     *
     * @return bool
     */
    public static function isCustomOperationType($type) {
        return (self::isOperationType($type) && $type > OP_CREDIT);
    }


    /**
     * Ob der uebergebene String ein gueltiger Instrumentbezeichner ist.
     *
     * @param  string $string - zu pruefender Sring
     *
     * @return bool
     */
    public static function isInstrument($string) {
        return (is_string($string) && isSet(ViewHelper::$instruments[$string]));
    }
}
