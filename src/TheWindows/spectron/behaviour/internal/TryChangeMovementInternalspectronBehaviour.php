<?php
declare(strict_types=1);
namespace TheWindows\spectron\behaviour\internal;
use TheWindows\spectron\behaviour\spectronBehaviour;
use TheWindows\spectron\spectron;
use TheWindows\spectron\Loader;
use TheWindows\spectron\network\listener\ClosurespectronPacketListener;
use TheWindows\spectron\network\listener\spectronPacketListener;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\block\Lava;
use pocketmine\block\Grass;
use pocketmine\block\Log;
use pocketmine\block\Wheat;
use pocketmine\block\Sign;
use pocketmine\block\TallGrass;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\Item;
use pocketmine\item\Sword;
use pocketmine\item\Armor;
use pocketmine\item\Helmet;
use pocketmine\item\Chestplate;
use pocketmine\item\Leggings;
use pocketmine\item\Boots;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use pocketmine\world\Position;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
final class TryChangeMovementInternalspectronBehaviour implements spectronBehaviour{
    use InternalspectronBehaviourTrait;
    public static function init(Loader $plugin) : void{
    }
    private static function changeDrag(Player $player) : void{
        static $_drag = null;
        $_drag ??= new ReflectionProperty(Entity::class, "drag");
        $_drag->setValue($player, $_drag->getValue($player) * 2);
    }
    private static function writeMovementToPlayer(Player $player, Vector3 $motion) : void{
        static $_motion = null;
        $_motion ??= new ReflectionProperty(Entity::class, "motion");
        $_motion->setValue($player, $motion->asVector3());
    }
    private static function tryChangeMovement(Player $player) : void{
        static $reflection_method = null;
        $reflection_method ??= new ReflectionMethod(Human::class, "tryChangeMovement");
        $reflection_method->getClosure($player)();
    }
    private ?spectronPacketListener $motion_packet_listener = null;
    public function __construct(
        private spectronMovementData $data,
        private Loader $plugin
    ){}
    private function triggerJump(Player $player, bool $forward = false) : void{
        if($player->onGround && !$this->data->is_jumping){
            $yaw = $player->getLocation()->yaw;
            $rad = deg2rad($yaw);
            $forward_motion = $forward ? new Vector3(-sin($rad) * 0.1, 0, cos($rad) * 0.1) : new Vector3(0, 0, 0);
            $this->data->motion = $forward_motion->withComponents(null, 0.42, null);
            $this->data->is_jumping = true;
            $this->data->needs_jump = true;
            $this->data->jump_forward = $forward;
            self::writeMovementToPlayer($player, $this->data->motion);
            self::tryChangeMovement($player);
            $player->getServer()->getLogger()->debug("Triggered jump for player at " . $player->getPosition() . ", forward: " . ($forward ? "yes" : "no"));
        }
    }
    private function checkObstaclesForJump(Player $player) : bool{
        if(!$player->onGround || $this->data->is_jumping){
            return false;
        }
        $pos = $player->getPosition()->floor();
        $world = $player->getWorld();
        $motion = $this->data->motion;
        $yaw = $player->getLocation()->yaw;
        $rad = deg2rad($yaw);
        $front_dir = $motion->length() > 0.03 ? $motion->normalize()->multiply(1.0) : (new Vector3(-sin($rad), 0, cos($rad)))->normalize()->multiply(1.0);
        $directions = [
            $front_dir, 
            (new Vector3(-sin($rad), 0, cos($rad)))->normalize()->multiply(-1.0), 
            (new Vector3(-sin($rad + M_PI_2), 0, cos($rad + M_PI_2)))->normalize()->multiply(1.0), 
            (new Vector3(-sin($rad - M_PI_2), 0, cos($rad - M_PI_2)))->normalize()->multiply(1.0) 
        ];
        foreach($directions as $dir){
            $check_pos = $pos->add($dir->x, 0, $dir->z)->floor();
            if($pos->distance($check_pos) > 1.0){
                continue;
            }
            $block = $world->getBlockAt((int) $check_pos->x, (int) $check_pos->y, (int) $check_pos->z);
            if($block instanceof Wheat || $block instanceof Sign || $block instanceof TallGrass || !$block->isSolid() && empty($block->getCollisionBoxes())){
                continue; 
            }
            $above = $world->getBlockAt((int) $check_pos->x, (int) $check_pos->y + 1, (int) $check_pos->z);
            if($block->isSolid() || !empty($block->getCollisionBoxes())){
                if(!$above->isSolid() && empty($above->getCollisionBoxes())){
                    $player->getServer()->getLogger()->debug("Jump triggered at $check_pos: " . $block->getName());
                    $this->triggerJump($player, true); 
                    return true;
                }
                $above2 = $world->getBlockAt((int) $check_pos->x, (int) $check_pos->y + 2, (int) $check_pos->z);
                if($above->isSolid() && !$above2->isSolid()){
                    $this->data->climbing_state = 'preparing';
                    $this->data->climbing_target = Position::fromObject($check_pos->add(0, 1, 0), $world);
                    $player->getServer()->getLogger()->debug("Climbing initiated at $check_pos");
                    return false;
                }
            }
        }
        return false;
    }
    private function checkForVoid(Player $player) : bool{
        $pos = $player->getPosition()->floor();
        $world = $player->getWorld();
        $motion = $this->data->motion;
        $yaw = $player->getLocation()->yaw;
        $rad = deg2rad($yaw);
        $front_dir = $motion->length() > 0.03 ? $motion->normalize()->multiply(1.0) : (new Vector3(-sin($rad), 0, cos($rad)))->normalize()->multiply(1.0);
        $directions = [
            $front_dir, 
            (new Vector3(-sin($rad), 0, cos($rad)))->normalize()->multiply(-1.0), 
            (new Vector3(-sin($rad + M_PI_2), 0, cos($rad + M_PI_2)))->normalize()->multiply(1.0), 
            (new Vector3(-sin($rad - M_PI_2), 0, cos($rad - M_PI_2)))->normalize()->multiply(1.0) 
        ];
        foreach($directions as $dir){
            $check_pos = $pos->add($dir->x, 0, $dir->z)->floor();
            $below = $world->getBlockAt((int) $check_pos->x, (int) $check_pos->y - 1, (int) $check_pos->z);
            $below2 = $world->getBlockAt((int) $check_pos->x, (int) $check_pos->y - 2, (int) $check_pos->z);
            if(!$below->isSolid() && !$below2->isSolid()){
                $player->getServer()->getLogger()->debug("Void detected at $check_pos");
                return true;
            }
        }
        return false;
    }
    private function checkForStuck(Player $player) : bool{
        $pos = $player->getPosition()->floor();
        $world = $player->getWorld();
        $check_positions = [
            $pos->add(1, 0, 0), $pos->add(-1, 0, 0),
            $pos->add(0, 0, 1), $pos->add(0, 0, -1),
            $pos->add(1, 1, 0), $pos->add(-1, 1, 0),
            $pos->add(0, 1, 1), $pos->add(0, 1, -1)
        ];
        $stuck = true;
        foreach($check_positions as $check_pos){
            $block = $world->getBlockAt((int) $check_pos->x, (int) $check_pos->y, (int) $check_pos->z);
            if(!$block->isSolid()){
                $stuck = false;
                break;
            }
        }
        if($stuck){
            $this->data->stuck_ticks++;
            if($this->data->stuck_ticks >= 20){
                $player->getServer()->getLogger()->debug("Player stuck at $pos for {$this->data->stuck_ticks} ticks");
                return true;
            }
        }else{
            $this->data->stuck_ticks = 0;
        }
        return false;
    }
    private function isSafeDestination(Player $player, Vector3 $target) : bool{
        $world = $player->getWorld();
        $target_floor = $target->floor();
        $block = $world->getBlockAt((int) $target_floor->x, (int) $target_floor->y, (int) $target_floor->z);
        if($block instanceof Water || $block instanceof Lava){
            return false;
        }
        $below = $world->getBlockAt((int) $target_floor->x, (int) $target_floor->y - 1, (int) $target_floor->z);
        if(!$below->isSolid() && $target_floor->y <= 0){
            return false;
        }
        $head = $world->getBlockAt((int) $target_floor->x, (int) $target_floor->y + 1, (int) $target_floor->z);
        if($head->isSolid()){
            return false;
        }
        return true;
    }
    private function findBlockToBreak(Player $player) : bool{
        $pos = $player->getPosition()->floor();
        $world = $player->getWorld();
        $inventory = $player->getInventory();
        $current_item = $inventory->getItemInHand();
        if($current_item instanceof Sword){
            $inventory->setItemInHand(VanillaBlocks::AIR()->asItem());
            $current_item = VanillaBlocks::AIR()->asItem();
        }
        $check_positions = [
            $pos->add(1, 0, 0), $pos->add(-1, 0, 0),
            $pos->add(0, 0, 1), $pos->add(0, 0, -1),
            $pos->add(0, -1, 0)
        ];
        foreach($check_positions as $check_pos){
            $block = $world->getBlockAt((int) $check_pos->x, (int) $check_pos->y, (int) $check_pos->z);
            if($block->isSolid()){
                $world->useBreakOn(Position::fromObject($check_pos, $world), $current_item, $player);
                $player->getServer()->getLogger()->debug("Broke block " . $block->getName() . " at $check_pos with " . ($current_item->isNull() ? "empty hand" : $current_item->getName()));
                return true;
            }
        }
        $player->getServer()->getLogger()->debug("No breakable block found near $pos");
        return false;
    }
    private function checkForBridging(Player $player, Vector3 $target_pos) : bool{
        if(!$this->data->is_pvp_active || $this->data->bridging_cooldown > 0){
            return false;
        }
        $pos = $player->getPosition();
        $world = $player->getWorld();
        $below = $pos->floor()->add(0, -1, 0);
        $block_below = $world->getBlockAt((int) $below->x, (int) $below->y, (int) $below->z);
        $block_below2 = $world->getBlockAt((int) $below->x, (int) $below->y - 1, (int) $below->z);
        if(!$block_below->isSolid() && !$block_below2->isSolid() && $pos->distance($target_pos) <= 4.0){
            $player->getServer()->getLogger()->debug("Bridging triggered at $pos");
            return true;
        }
        return false;
    }
    private function performBridging(Player $player, Vector3 $target_pos) : void{
        $inventory = $player->getInventory();
        $item_in_hand = $inventory->getItemInHand();
        $block_item = null;
        if($item_in_hand->getBlock()->isSolid()){
            $block_item = $item_in_hand;
        }else{
            foreach($inventory->getContents() as $slot => $item){
                if($item->getBlock()->isSolid()){
                    $inventory->setItemInHand($item);
                    $block_item = $item;
                    break;
                }
            }
            if($block_item === null){
                $this->data->bridging_state = null;
                $this->findNewPath($player);
                return;
            }
        }
        $this->data->bridging_state = 'placing';
        $pos = $player->getPosition();
        $world = $player->getWorld();
        $direction = $pos->subtractVector($target_pos)->normalize();
        $yaw = $player->getLocation()->yaw;
        $rad = deg2rad($yaw);
        $place_dir = $this->data->motion->length() > 0.03 ? $this->data->motion->normalize() : (new Vector3(-sin($rad), 0, cos($rad)));
        $place_pos = $pos->floor()->add($place_dir->x, -1, $place_dir->z);
        $adjacent_pos = $place_pos->subtract($place_dir->x, 0, $place_dir->z);
        $adjacent_block = $world->getBlockAt((int) $adjacent_pos->x, (int) $adjacent_pos->y, (int) $adjacent_pos->z);
        if(!$adjacent_block->isSolid()){
            $player->getServer()->getLogger()->debug("Bridging failed at $place_pos: no solid adjacent block");
            $this->data->bridging_state = null;
            $this->findNewPath($player);
            return;
        }
        $face = $this->getFacingFromDirection($place_dir);
        $this->data->motion = $direction->multiply(0.1)->withComponents(null, 0.42, null);
        $this->data->is_jumping = true;
        $world->useItemOn(Position::fromObject($place_pos, $world), $block_item, $face, $place_pos, $player);
        $this->data->bridging_cooldown = 10;
        $player->getServer()->getLogger()->debug("Bridging at $place_pos with " . $block_item->getName() . " on face $face");
    }
    private function performClimbing(Player $player) : void{
        if($this->data->climbing_target === null || $this->data->climbing_cooldown > 0){
            $this->data->climbing_state = null;
            return;
        }
        $inventory = $player->getInventory();
        $item_in_hand = $inventory->getItemInHand();
        $block_item = null;
        if($item_in_hand->getBlock()->isSolid()){
            $block_item = $item_in_hand;
        }else{
            foreach($inventory->getContents() as $slot => $item){
                if($item->getBlock()->isSolid()){
                    $inventory->setItemInHand($item);
                    $block_item = $item;
                    break;
                }
            }
            if($block_item === null){
                $this->data->climbing_state = null;
                $this->findNewPath($player);
                return;
            }
        }
        $this->data->climbing_state = 'placing';
        $place_pos = $this->data->climbing_target->floor();
        $world = $player->getWorld();
        $target_block = $world->getBlockAt((int) $place_pos->x, (int) $place_pos->y, (int) $place_pos->z);
        if($target_block->isSolid()){
            $player->getServer()->getLogger()->debug("Climbing failed at $place_pos: target position is solid");
            $this->data->climbing_state = null;
            $this->findNewPath($player);
            return;
        }
        $pos = $player->getPosition();
        $start_y = $pos->y;
        $adjacent_positions = [
            $place_pos->add(1, 0, 0), $place_pos->add(-1, 0, 0),
            $place_pos->add(0, 0, 1), $place_pos->add(0, 0, -1),
            $place_pos->add(1, -1, 0), $place_pos->add(-1, -1, 0),
            $place_pos->add(0, -1, 1), $place_pos->add(0, -1, -1),
            $place_pos->add(1, 1, 0), $place_pos->add(-1, 1, 0),
            $place_pos->add(0, 1, 1), $place_pos->add(0, 1, -1),
            $place_pos->add(0, -2, 0)
        ];
        $adjacent_block = null;
        $adjacent_pos = null;
        foreach($adjacent_positions as $adj_pos){
            $adj_pos = $adj_pos->floor();
            $block = $world->getBlockAt((int) $adj_pos->x, (int) $adj_pos->y, (int) $adj_pos->z);
            $player->getServer()->getLogger()->debug("Checking adjacent pos $adj_pos: " . ($block->isSolid() ? "solid" : "non-solid"));
            if($block->isSolid()){
                $adjacent_block = $block;
                $adjacent_pos = $adj_pos;
                break;
            }
        }
        if($adjacent_block === null){
            $player->getServer()->getLogger()->debug("Climbing failed at $place_pos: no solid adjacent block");
            $this->data->climbing_state = null;
            $this->findNewPath($player);
            return;
        }
        $this->triggerJump($player, false);
        $current_y = $player->getPosition()->y;
        if($current_y < $start_y + 0.3){
            $player->getServer()->getLogger()->debug("Waiting for jump elevation at y=$current_y, target=$start_y + 0.3");
            return;
        }
        $horizontal_faces = [Facing::NORTH, Facing::SOUTH, Facing::EAST, Facing::WEST];
        $result = null;
        foreach($horizontal_faces as $face){
            $player->getServer()->getLogger()->debug("Attempting to place block at $place_pos with item {$block_item->getName()} on face $face, player pos=" . $player->getPosition());
            $result = $world->useItemOn($place_pos, $block_item, $face, $place_pos, $player);
            if($result !== null){
                break;
            }
        }
        $this->data->climbing_state = null;
        $this->data->climbing_target = null;
        $this->data->climbing_cooldown = 20;
        $player->getServer()->getLogger()->debug("Climbing attempt at $place_pos using " . $block_item->getName() . ", result: " . ($result !== null ? "success" : "failed"));
        if($result === null){
            $player->getServer()->getLogger()->debug("All horizontal faces failed; inventory: " . print_r($inventory->getContents(), true));
        }
    }
    private function getFacingFromDirection(Vector3 $direction) : int{
        $abs_x = abs($direction->x);
        $abs_z = abs($direction->z);
        if($abs_x >= $abs_z && $direction->x > 0){
            return Facing::WEST;
        }
        if($abs_x >= $abs_z && $direction->x < 0){
            return Facing::EAST;
        }
        if($direction->z > 0){
            return Facing::SOUTH;
        }
        return Facing::NORTH;
    }
    private function findNewPath(Player $player) : void{
        if($this->data->pathfinding_cooldown > 0){
            return;
        }
        $pos = $player->getPosition();
        $world = $player->getWorld();
        $check_positions = [
            $pos->add(6, 0, 0), $pos->add(-6, 0, 0),
            $pos->add(0, 0, 6), $pos->add(0, 0, -6),
            $pos->add(3, 0, 3), $pos->add(-3, 0, -3),
            $pos->add(3, 0, -3), $pos->add(-3, 0, 3)
        ];
        foreach($check_positions as $target){
            if($this->isSafeDestination($player, $target)){
                $this->data->random_walk_target = $target;
                $this->data->random_walk_ticks = 100;
                $this->data->movement_mode = 'walk';
                $this->data->pathfinding_cooldown = 30;
                $player->getServer()->getLogger()->debug("New path to $target");
                return;
            }
        }
        $this->data->pathfinding_cooldown = 30;
        $player->getServer()->getLogger()->debug("No safe path found near $pos");
    }
    private function isBetterItem(Player $player, Item $item) : bool{
        $inventory = $player->getInventory();
        $armor_inventory = $player->getArmorInventory();
        if($item instanceof Armor){
            $slot = match(true){
                $item instanceof Helmet => 0,
                $item instanceof Chestplate => 1,
                $item instanceof Leggings => 2,
                $item instanceof Boots => 3,
                default => -1
            };
            if($slot === -1){
                $player->getServer()->getLogger()->debug("Rejected armor " . $item->getName() . ": invalid armor type");
                return false;
            }
            $player->getServer()->getLogger()->debug("Accepted armor: " . $item->getName() . " for slot $slot - pursuing item");
            return true; 
        }
        if($item instanceof Sword){
            $current = $inventory->getItemInHand();
            $current_damage = $current instanceof Sword ? $this->getSwordDamage($current) : 0;
            $new_damage = $this->getSwordDamage($item);
            $is_better = $new_damage > $current_damage;
            $player->getServer()->getLogger()->debug("Evaluating sword: " . $item->getName() . " (damage: $new_damage) vs current: " . ($current instanceof Sword ? $current->getName() : "none") . " (damage: $current_damage) - " . ($is_better ? "better" : "not better"));
            return $is_better;
        }
        $player->getServer()->getLogger()->debug("Rejected item " . $item->getName() . ": not a sword or armor");
        return false;
    }
    private function getSwordDamage(Sword $sword) : float{
        return match($sword->getTypeId()){
            VanillaItems::WOODEN_SWORD()->getTypeId() => 4.0,
            VanillaItems::STONE_SWORD()->getTypeId() => 5.0,
            VanillaItems::IRON_SWORD()->getTypeId() => 6.0,
            VanillaItems::DIAMOND_SWORD()->getTypeId() => 7.0,
            VanillaItems::NETHERITE_SWORD()->getTypeId() => 8.0,
            default => 4.0 
        };
    }
    private function findNearbyItem(Player $player) : ?array{
        $pos = $player->getPosition();
        $world = $player->getWorld();
        $nearest_item = null;
        $least_dist = INF;
        $entities = $world->getNearbyEntities(new AxisAlignedBB(
            $pos->x - 12, $pos->y - 16, $pos->z - 12,
            $pos->x + 12, $pos->y + 16, $pos->z + 12
        ));
        $player->getServer()->getLogger()->debug("Checking for nearby items at $pos, found " . count($entities) . " entities");
        foreach($entities as $entity){
            if($entity instanceof ItemEntity){
                $item = $entity->getItem();
                $player->getServer()->getLogger()->debug("Found item entity at " . $entity->getPosition() . ": " . $item->getName() . " (type: " . get_class($item) . ")");
                if(!($item instanceof Sword || $item instanceof Armor)){
                    $player->getServer()->getLogger()->debug("Skipped item at " . $entity->getPosition() . ": " . $item->getName() . " is not sword or armor");
                    continue;
                }
                if(!$this->isBetterItem($player, $item)){
                    $player->getServer()->getLogger()->debug("Skipped item at " . $entity->getPosition() . ": " . $item->getName() . " is not better");
                    continue;
                }
                $dist = $pos->distanceSquared($entity->getPosition());
                if($dist < $least_dist && $dist <= 144){
                    $nearest_item = $entity;
                    $least_dist = $dist;
                    $player->getServer()->getLogger()->debug("Selected better item: " . $item->getName() . " at " . $entity->getPosition() . ", distance: " . sqrt($dist));
                }
            }
        }
        if($nearest_item !== null){
            $target_pos = $nearest_item->getPosition();
            $player->getServer()->getLogger()->debug("Targeting item " . $nearest_item->getItem()->getName() . " at $target_pos (entity ID: " . $nearest_item->getId() . ")");
            return ['position' => $target_pos, 'entity_id' => $nearest_item->getId()];
        }
        $player->getServer()->getLogger()->debug("No swords or armor found within 12 blocks");
        return null;
    }
    private function setLookDirection(Player $player, Vector3 $target) : void{
        $pos = $player->getPosition();
        $delta = $target->subtractVector($pos);
        $yaw = atan2($delta->z, $delta->x) * 180 / M_PI - 90;
        if($yaw < 0){
            $yaw += 360;
        }
        $pitch = 0; 
        $player->setRotation($yaw, $pitch);
        $player->getServer()->getLogger()->debug("Set head position: yaw=$yaw, pitch=$pitch for target $target");
    }
    private function startRandomWalk(Player $player) : void{
        $pos = $player->getPosition();
        $angle = mt_rand(0, 359) * M_PI / 180;
        $distance = mt_rand(2, 6);
        $target = $pos->add(cos($angle) * $distance, 0, sin($angle) * $distance);
        if($this->isSafeDestination($player, $target)){
            $this->data->random_walk_target = $target;
            $this->data->random_walk_ticks = mt_rand(40, 100);
            $this->data->movement_mode = 'walk';
            $this->data->target_item_id = null;
            $player->getServer()->getLogger()->debug("Started random walk to $target");
        }else{
            $this->data->random_walk_target = null;
            $this->data->random_walk_ticks = 0;
            $this->data->target_item_id = null;
            $this->findNewPath($player);
        }
    }
    private function updateRandomWalk(Player $player) : void{
        $pos = $player->getPosition();
        if($this->data->target_item_id !== null){
            $world = $player->getWorld();
            $item_entity = $world->getEntity($this->data->target_item_id);
            if($item_entity instanceof ItemEntity && $item_entity->isAlive() && ($item_entity->getItem() instanceof Sword || $item_entity->getItem() instanceof Armor)){
                $this->data->random_walk_target = $item_entity->getPosition();
                $this->setLookDirection($player, $this->data->random_walk_target);
                $player->getServer()->getLogger()->debug("Continuing to target item " . $item_entity->getItem()->getName() . " at " . $this->data->random_walk_target);
            }else{
                $this->data->random_walk_target = null;
                $this->data->random_walk_ticks = 0;
                $this->data->target_item_id = null;
                $player->getServer()->getLogger()->debug("Item target lost or despawned");
            }
        }
        if($this->data->random_walk_target === null || $this->data->random_walk_ticks <= 0){
            $item_info = $this->findNearbyItem($player);
            if($item_info !== null){
                $this->data->random_walk_target = $item_info['position'];
                $this->data->random_walk_ticks = 400;
                $this->data->movement_mode = 'walk';
                $this->data->target_item_id = $item_info['entity_id'];
                $this->setLookDirection($player, $this->data->random_walk_target);
                $player->getServer()->getLogger()->debug("Switching to item target " . ($item_info['position'] ? $item_info['position'] : "unknown") . " (entity ID: " . $item_info['entity_id'] . ")");
            }else{
                $this->startRandomWalk($player);
            }
            return;
        }
        $target = $this->data->random_walk_target;
        $distance = $pos->distance($target);
        $speed = $distance < 1.0 ? 0.05 : ($this->data->movement_mode === 'walk' ? 0.12 : 0.15); 
        $direction = $target->subtractVector($pos)->normalize()->multiply($speed);
        $this->data->motion = $direction->withComponents($direction->x, $this->data->motion->y, $direction->z);
        $this->data->random_walk_ticks--;
        if($distance < 0.25){ 
            $this->data->random_walk_target = null;
            $this->data->random_walk_ticks = 0;
            $this->data->target_item_id = null;
            $player->getServer()->getLogger()->debug("Reached target $target, resetting walk");
        }else{
            $this->setLookDirection($player, $target);
            $player->getServer()->getLogger()->debug("Moving toward target $target, distance: $distance, speed: $speed");
        }
    }
    public function onAddToPlayer(spectron $player) : void{
        if($this->motion_packet_listener !== null){
            throw new RuntimeException("Listener was already added");
        }
        $player_instance = $player->getPlayer();
        $player_instance->keepMovement = false;
        self::changeDrag($player_instance);
        $player_id = $player_instance->getId();
        $this->motion_packet_listener = new ClosurespectronPacketListener(function(ClientboundPacket $packet, NetworkSession $session) use($player_id) : void{
            if($packet instanceof SetActorMotionPacket && $packet->actorRuntimeId === $player_id){
                if($this->data->climbing_state === null){
                    $this->data->motion = $packet->motion->asVector3();
                    $this->data->is_jumping = false;
                    if($this->data->motion->length() > 0.03){
                        $this->data->random_walk_target = null;
                        $this->data->random_walk_ticks = 0;
                        $this->data->target_item_id = null;
                    }
                }
            }
        });
        $player->getNetworkSession()->registerSpecificPacketListener(SetActorMotionPacket::class, $this->motion_packet_listener);
    }
    public function onRemoveFromPlayer(spectron $player) : void{
        $player->getNetworkSession()->unregisterSpecificPacketListener(
            SetActorMotionPacket::class,
            $this->motion_packet_listener ?? throw new RuntimeException("Listener was already removed")
        );
        $this->motion_packet_listener = null;
    }
    public function tick(spectron $player) : void{
        $player_instance = $player->getPlayer();
        if($player_instance === null || !$player_instance->isOnline()){
            return;
        }
        if(!$this->plugin->isRandomWalkEnabled()){
            $this->data->motion = new Vector3(0, $this->data->motion->y, 0); 
            $this->data->random_walk_target = null;
            $this->data->random_walk_ticks = 0;
            $this->data->target_item_id = null;
            $this->data->is_jumping = false;
            $this->data->needs_jump = false;
            $this->data->jump_forward = false;
            $this->data->climbing_state = null;
            $this->data->climbing_target = null;
            $this->data->climbing_cooldown = 0;
            $this->data->bridging_state = null;
            $this->data->bridging_cooldown = 0;
            $this->data->pathfinding_cooldown = 0;
            $this->data->stuck_ticks = 0;
            $player_instance->getServer()->getLogger()->debug("Random walk disabled; cleared all movement data at " . $player_instance->getPosition());
            self::writeMovementToPlayer($player_instance, $this->data->motion);
            self::tryChangeMovement($player_instance);
            return;
        }
        if($player_instance->onGround && $this->data->is_jumping){
            $this->data->is_jumping = false;
            $this->data->needs_jump = false;
            $this->data->jump_forward = false;
        }
        if($this->data->bridging_cooldown > 0){
            $this->data->bridging_cooldown--;
        }
        if($this->data->pathfinding_cooldown > 0){
            $this->data->pathfinding_cooldown--;
        }
        if($this->data->climbing_cooldown > 0){
            $this->data->climbing_cooldown--;
        }
        if($this->checkForStuck($player_instance)){
            if($this->findBlockToBreak($player_instance)){
                $this->data->stuck_ticks = 0;
                $player_instance->getServer()->getLogger()->debug("Unstuck by breaking block at " . $player_instance->getPosition());
                return;
            }
        }
        if($this->checkForVoid($player_instance)){
            $target_pos = $this->data->motion->length() > 0.03 ? $player_instance->getPosition()->addVector($this->data->motion->normalize()->multiply(2)) : $player_instance->getPosition();
            if($this->checkForBridging($player_instance, $target_pos)){
                $this->performBridging($player_instance, $target_pos);
            }else{
                $this->findNewPath($player_instance);
            }
        }
        if(($this->data->needs_jump || !$this->data->is_pvp_active) && $this->checkObstaclesForJump($player_instance)){
        }
        if($this->data->climbing_state !== null){
            $this->performClimbing($player_instance);
        }
        if($this->data->is_pvp_active){
            $target_pos = $this->data->motion->length() > 0.03 ? $player_instance->getPosition()->addVector($this->data->motion->normalize()->multiply(3)) : $player_instance->getPosition();
            if($this->checkForBridging($player_instance, $target_pos)){
                $this->performBridging($player_instance, $target_pos);
            }
        }else{
            $this->updateRandomWalk($player_instance);
        }
        self::writeMovementToPlayer($player_instance, $this->data->motion);
        self::tryChangeMovement($player_instance);
    }
    public function onRespawn(spectron $player) : void{
        $this->data->is_jumping = false;
        $this->data->random_walk_target = null;
        $this->data->random_walk_ticks = 0;
        $this->data->movement_mode = 'walk';
        $this->data->is_pvp_active = false;
        $this->data->bridging_state = null;
        $this->data->bridging_block = null;
        $this->data->bridging_cooldown = 0;
        $this->data->climbing_state = null;
        $this->data->climbing_target = null;
        $this->data->pathfinding_cooldown = 0;
        $this->data->climbing_cooldown = 0;
        $this->data->needs_jump = false;
        $this->data->stuck_ticks = 0;
        $this->data->jump_forward = false;
        $this->data->target_item_id = null;
        $this->data->motion = new Vector3(0, 0, 0); 
        $player->getPlayer()->getServer()->getLogger()->debug("Reset movement data on respawn for player " . $player->getPlayer()->getName());
    }
    public function updateConfig(array $config) : void{
        if(isset($config['random_walk_enabled'])){
            $this->data->is_pvp_active = !$config['random_walk_enabled']; 
            $player = $this->data->player ?? null;
            if($player instanceof Player){
                $player->getServer()->getLogger()->debug("Updated movement config for player {$player->getName()}: random_walk_enabled=" . ($config['random_walk_enabled'] ? 'true' : 'false'));
            }
        }
    }
}