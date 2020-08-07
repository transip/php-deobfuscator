<?php


namespace Transip\Scope;


use ast\Node;

class ArrayConstantScope implements ConstantScope
{
    /** @var array */
    private $constantMap;

    public function __construct(array $constantMap = [])
    {
        $this->constantMap = $constantMap;
    }

    public function addToScope(string $name, $content): bool
    {
        $this->constantMap[$name] = $content;
        return true;
    }

    public function existsInScope(string $name): bool
    {
        return isset($this->constantMap[$name]);
    }

    public function getFromScope(string $name)
    {
        if (!$this->existsInScope($name)) {
            throw new NoSuchKeyInScopeException("{$name} is not a defined constant");
        }

        return $this->constantMap[$name];
    }
}