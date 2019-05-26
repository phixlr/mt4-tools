<?php
namespace rosasurfer\rt\model;

use rosasurfer\core\assert\Assert;
use rosasurfer\core\exception\IllegalArgumentException;


/**
 * Represents a single input parameter of a tested strategy.
 *
 * @method string getName()  Return the name of the input parameter.
 * @method string getValue() Return the value of the input parameter.
 * @method Test   getTest()  Return the test this input parameter belongs to.
 */
class StrategyParameter extends RosatraderModel {


    /** @var string */
    protected $name;

    /** @var string */
    protected $value;

    /** @var Test [transient] */
    protected $test;


    /**
     * Create a new parameter instance.
     *
     * @param  Test   $test
     * @param  string $name
     * @param  string $value
     *
     * @return self
     */
    public static function create(Test $test, $name, $value) {
        Assert::string($name, '$name');
        if (!strlen($name)) throw new IllegalArgumentException('Illegal parameter $name "'.$name.'" (must be non-empty)');
        Assert::string($value, '$value');

        $param = new self();

        $param->test  = $test;
        $param->name  = $name;
        $param->value = $value;

        return $param;
    }
}
