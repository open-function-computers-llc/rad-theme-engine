<?php

namespace ofc;

abstract class RadAction implements RadActionInterface
{
    protected string $hookName;
    protected int $priority = 99;
    protected bool $wrapInit = false;

    public function getHookName(): string
    {
        return $this->hookName;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function wrapHookInInit(): bool
    {
        return $this->wrapInit;
    }

    abstract public function callback();
}
