<?php

declare(strict_types=1);

namespace TheWindows\spectron\behaviour;

use TheWindows\spectron\spectron;
use TheWindows\spectron\Loader;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\EventPriority;
use pocketmine\item\Armor;
use pocketmine\player\Player;

final class AutoEquipArmorspectronBehaviour implements spectronBehaviour{

    private const METADATA_KEY = "behaviour:auto_equip_armor";

    public static function create(array $data) : self{
        return new self();
    }

    public static function init(Loader $plugin) : void{
        $plugin->getServer()->getPluginManager()->registerEvent(EntityItemPickupEvent::class, static function(EntityItemPickupEvent $event) use($plugin) : void{
            $item = $event->getItem();
            if(!($item instanceof Armor)){
                $plugin->getServer()->getLogger()->debug("Armor pickup event triggered, but item is not armor: " . $item->getName());
                return;
            }

            $entity = $event->getEntity();
            if(!($entity instanceof Player)){
                $plugin->getServer()->getLogger()->debug("Armor pickup event triggered, but entity is not a player: " . get_class($entity));
                return;
            }

            $fake_player = $plugin->getspectron($entity);
            if($fake_player === null || $fake_player->getMetadata(self::METADATA_KEY) === null){
                $plugin->getServer()->getLogger()->debug("Armor pickup event triggered, but player is not a fake player or lacks metadata");
                return;
            }

            if($event->getInventory() !== $entity->getInventory()){
                $plugin->getServer()->getLogger()->debug("Armor pickup event triggered, but inventory mismatch");
                return;
            }

            $destination_inventory = $entity->getArmorInventory();
            $destination_slot = $item->getArmorSlot();
            $current_armor = $destination_inventory->getItem($destination_slot);

            if($current_armor->isNull()){
                $plugin->getServer()->getLogger()->debug("Attempting to equip armor: " . $item->getName() . " to slot $destination_slot (empty)");
                ($ev = new EntityItemPickupEvent($entity, $event->getOrigin(), $item, $destination_inventory))->call();
                if($ev->isCancelled()){
                    $plugin->getServer()->getLogger()->debug("Armor pickup cancelled by another plugin for " . $item->getName());
                    return;
                }

                $event->cancel();
                $event->getOrigin()->flagForDespawn();
                $destination_inventory->setItem($destination_slot, $item);
                $plugin->getServer()->getLogger()->debug("Equipped armor: " . $item->getName() . " to slot $destination_slot");
            }else{
                $plugin->getServer()->getLogger()->debug("Armor slot $destination_slot occupied by " . $current_armor->getName() . ", attempting inventory pickup for " . $item->getName());
                ($ev = new EntityItemPickupEvent($entity, $event->getOrigin(), $item, $entity->getInventory()))->call();
                if($ev->isCancelled()){
                    $plugin->getServer()->getLogger()->debug("Inventory pickup cancelled by another plugin for " . $item->getName());
                    return;
                }

                $event->cancel();
                $event->getOrigin()->flagForDespawn();
                $entity->getInventory()->addItem($item);
                $plugin->getServer()->getLogger()->debug("Added armor: " . $item->getName() . " to inventory");
            }
        }, EventPriority::NORMAL, $plugin);
    }

    public function __construct(){
    }

    public function onAddToPlayer(spectron $player) : void{
        $player->setMetadata(self::METADATA_KEY, true);
    }

    public function onRemoveFromPlayer(spectron $player) : void{
        $player->deleteMetadata(self::METADATA_KEY);
    }

    public function tick(spectron $player) : void{
    }

    public function onRespawn(spectron $player) : void{
    }
}