<?php

namespace ofc;

interface RadActionInterface
{
    public function getHookName(): string;
    public function getPriority(): int;
    public function callback();
}
