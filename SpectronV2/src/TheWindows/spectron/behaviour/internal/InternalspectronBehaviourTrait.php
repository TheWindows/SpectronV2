<?php

declare(strict_types=1);

namespace TheWindows\spectron\behaviour\internal;

use RuntimeException;

trait InternalspectronBehaviourTrait{

    public static function create(array $data) : self{
        throw new RuntimeException("Cannot create internal fake player behavior " . static::class . " from data");
    }
}