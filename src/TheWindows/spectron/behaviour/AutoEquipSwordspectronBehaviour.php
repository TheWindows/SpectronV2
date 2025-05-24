<?php

declare(strict_types=1);

namespace TheWindows\spectron\behaviour;

use TheWindows\spectron\spectron;
use TheWindows\spectron\Loader;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\EventPriority;
use pocketmine\item\Sword;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;

final class AutoEquipSwordspectronBehaviour implements spectronBehaviour{

    private const METADATA_KEY = "behaviour:auto_equip_sword";

    public static function create(array $data) : self{
        return new self();
    }

    public static function init(Loader $plugin) : void{
        $plugin->getServer()->getPluginManager()->registerEvent(EntityItemPickupEvent::class, static function(EntityItemPickupEvent $event) use($plugin) : void{
            $item = $event->getItem();
            if(!($item instanceof Sword)){
                $plugin->getServer()->getLogger()->debug("Pickup event triggered, but item is not a sword: " . $item->getName());
                return;
            }

            $entity = $event->getEntity();
            if(!($entity instanceof Player)){
                $plugin->getServer()->getLogger()->debug("Pickup event triggered, but entity is not a player: " . get_class($entity));
                return;
            }

            $fake_player = $plugin->getspectron($entity);
            if($fake_player === null || $fake_player->getMetadata(self::METADATA_KEY) === null){
                $plugin->getServer()->getLogger()->debug("Pickup event triggered, but player is not a fake player or lacks metadata");
                return;
            }

            if($event->getInventory() !== $entity->getInventory()){
                $plugin->getServer()->getLogger()->debug("Pickup event triggered, but inventory mismatch");
                return;
            }

            $inventory = $entity->getInventory();
            $current = $inventory->getItemInHand();
            $current_damage = $current instanceof Sword ? self::getSwordDamage($current) : 0;
            $new_damage = self::getSwordDamage($item);

            if($new_damage <= $current_damage){
                $plugin->getServer()->getLogger()->debug("Cancelled pickup of " . $item->getName() . ": damage ($new_damage) not better than current ($current_damage)");
                $event->cancel();
                return;
            }

            $plugin->getServer()->getLogger()->debug("Attempting to equip sword: " . $item->getName() . " (damage: $new_damage), current: " . ($current instanceof Sword ? $current->getName() : "none") . " (damage: $current_damage)");

            ($ev = new EntityItemPickupEvent($entity, $event->getOrigin(), $item, $inventory))->call();
            if($ev->isCancelled()){
                $plugin->getServer()->getLogger()->debug("Sword pickup cancelled by another plugin for " . $item->getName());
                $event->cancel();
                return;
            }

            $event->cancel();
            $event->getOrigin()->flagForDespawn();

            if($current instanceof Sword){
                $entity->getWorld()->dropItem($entity->getPosition(), $current);
                $plugin->getServer()->getLogger()->debug("Dropped current sword: " . $current->getName());
            }

            $inventory->setItemInHand($item);
            $plugin->getServer()->getLogger()->debug("Equipped sword: " . $item->getName() . " with damage $new_damage, inventory: " . print_r($inventory->getContents(), true));
        }, EventPriority::NORMAL, $plugin);
    }

    private static function getSwordDamage(Sword $sword) : float{
        return match($sword->getTypeId()){
            VanillaItems::WOODEN_SWORD()->getTypeId() => 4.0,
            VanillaItems::STONE_SWORD()->getTypeId() => 5.0,
            VanillaItems::IRON_SWORD()->getTypeId() => 6.0,
            VanillaItems::DIAMOND_SWORD()->getTypeId() => 7.0,
            VanillaItems::NETHERITE_SWORD()->getTypeId() => 8.0,
            default => 4.0
        };
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