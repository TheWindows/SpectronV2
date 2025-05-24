<?php

declare(strict_types=1);

namespace TheWindows\spectron\behaviour\internal;

use TheWindows\spectron\behaviour\spectronBehaviour;
use TheWindows\spectron\spectron;
use TheWindows\spectron\Loader;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;
use ReflectionMethod;
use ReflectionProperty;

final class UpdateMovementInternalspectronBehaviour implements spectronBehaviour{
    use InternalspectronBehaviourTrait;

    public static function init(Loader $plugin) : void{
    }

    private static function readMovementFromPlayer(Player $player) : Vector3{
        static $_motion = null;
        $_motion ??= new ReflectionProperty(Human::class, "motion");
        return $_motion->getValue($player)->asVector3();
    }

    private static function movePlayer(Player $player, Vector3 $dv) : void{
        static $reflection_method = null;
        $reflection_method ??= new ReflectionMethod(Human::class, "move");
        $reflection_method->getClosure($player)($dv->x, $dv->y, $dv->z);
    }

    private static function setPlayerLocation(Player $player, Location $location) : void{
        static $reflection_property = null;
        $reflection_property ??= new ReflectionProperty(Human::class, "location");
        $reflection_property->setValue($player, $location);
    }

    private static function normalizeMotion(Vector3 $motion, string $movement_mode, bool $is_jumping) : Vector3{
        $max_speed = $is_jumping ? 0.1 : ($movement_mode === 'walk' ? 0.1 : 0.18);
        $xz_magnitude = sqrt($motion->x * $motion->x + $motion->z * $motion->z);
        if($xz_magnitude > $max_speed){
            $scale = $max_speed / $xz_magnitude;
            $motion = $motion->withComponents($motion->x * $scale, null, $motion->z * $scale);
        }
        return $motion;
    }

    public function __construct(
        private spectronMovementData $data
    ){}

    public function onAddToPlayer(spectron $player) : void{
    }

    public function onRemoveFromPlayer(spectron $player) : void{
    }

    public function tick(spectron $player) : void{
        $player_instance = $player->getPlayer();
        $this->data->motion = self::readMovementFromPlayer($player_instance);

        if($player_instance->hasMovementUpdate()){
            if($this->data->motion->length() > 0.15 && !$this->data->is_jumping){
                $this->data->movement_mode = 'run';
            }
            $this->data->motion = self::normalizeMotion($this->data->motion, $this->data->movement_mode, $this->data->is_jumping);

            // Update look direction based on motion
            $motion_length = sqrt($this->data->motion->x ** 2 + $this->data->motion->z ** 2);
            if($motion_length > 0.03){
                $yaw = rad2deg(atan2(-$this->data->motion->x, $this->data->motion->z));
                $location = $player_instance->getLocation();
                $new_location = new Location(
                    $location->x,
                    $location->y,
                    $location->z,
                    $player_instance->getWorld(),
                    $yaw,
                    $location->pitch
                );
                self::setPlayerLocation($player_instance, $new_location);
                $player_instance->getServer()->getLogger()->debug("Updated yaw to $yaw at " . $player_instance->getPosition());
            }

            $this->data->motion = $this->data->motion->withComponents(
                abs($this->data->motion->x) <= Entity::MOTION_THRESHOLD ? 0 : null,
                abs($this->data->motion->y) <= Entity::MOTION_THRESHOLD ? 0 : null,
                abs($this->data->motion->z) <= Entity::MOTION_THRESHOLD ? 0 : null
            );

            if($this->data->motion->x != 0 || $this->data->motion->y != 0 || $this->data->motion->z != 0){
                $old_location = $player_instance->getLocation();
                self::movePlayer($player_instance, $this->data->motion);
                $new_location = $player_instance->getLocation();

                self::setPlayerLocation($player_instance, $old_location);
                $player_instance->handleMovement($new_location);
            }

            $this->data->motion = self::readMovementFromPlayer($player_instance);
        }
    }

    public function onRespawn(spectron $player) : void{
    }
}