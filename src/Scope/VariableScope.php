<?php

namespace Transip\Scope;

use ast\Node;

interface VariableScope
{
    /**
     * Goes one scope "deeper" (eg into a function definition).
     */
    public function stepIntoScope();

    /**
     * Goes one scope "shallower" (eg out of a function definition).
     */
    public function stepOutOfScope();

    /**
     * @param Node $key the key to make global
     */
    public function makeGlobal(Node $key);

    /**
     * @param Node $key
     * @param mixed $value
     * @return bool whether or not the key was successfully stored
     */
    public function addToScope(Node $key, $value): bool;

    /**
     * @param Node $key
     * @return bool whether or not a value has been stored for this key
     */
    public function existsInScope(Node $key): bool;

    /**
     * @param Node $key
     * @return mixed whatever value was stored for this key
     * @throws NoSuchKeyInScopeException if there is no value stored for this key
     */
    public function getFromScope(Node $key);

    /**
     * @param Node $key
     * @return bool whether or not a given key can be stored
     */
    public function canKeyBeStored(Node $key): bool;

}
