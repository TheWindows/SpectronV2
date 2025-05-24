<?php

declare(strict_types=1);

namespace TheWindows\spectron\behaviour;

use InvalidArgumentException;
use TheWindows\spectron\Loader;
use pocketmine\utils\AssumptionFailedError;

final class spectronBehaviourFactory{

    private Loader $plugin;
    private array $behaviours = [];

    public function __construct(Loader $plugin){
        $this->plugin = $plugin;
    }

    public static function registerDefaults(Loader $plugin) : void{
        $factory = new self($plugin);
        PvPspectronBehaviour::init($plugin);
        $factory->register("spectron:pvp", PvPspectronBehaviour::class);
        $factory->register("spectron:auto_equip_armor", AutoEquipArmorspectronBehaviour::class);
        $plugin->setBehaviourFactory($factory);
    }

    public function register(string $identifier, string $class) : void{
        if(!is_a($class, spectronBehaviour::class, true)){
            throw new InvalidArgumentException("Class $class does not implement " . spectronBehaviour::class);
        }
        $this->behaviours[$identifier] = $class;
    }

    public function create(string $identifier, array $data) : spectronBehaviour{
        $class = $this->behaviours[$identifier] ?? null;
        if($class === null){
            throw new InvalidArgumentException("No behaviour registered for identifier: $identifier");
        }

        if($class === PvPspectronBehaviour::class){
            return new PvPspectronBehaviour(
                $data["reach_distance"] ?? 4.0,
                $data["pvp_idle_time"] ?? 500,
                $this->plugin
            );
        }

        $method = [$class, "create"];
        if(!is_callable($method)){
            throw new AssumptionFailedError("Class $class does not have a static create method");
        }

        return $method($data);
    }
}