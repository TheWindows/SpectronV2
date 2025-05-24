<?php
declare(strict_types=1);
namespace TheWindows\spectron\behaviour;
use TheWindows\spectron\spectron;
use TheWindows\spectron\Loader;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\item\Item;
use pocketmine\item\Sword;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
class PvPspectronBehaviour implements spectronBehaviour{
    public static function create(array $data) : spectronBehaviour{
        throw new \RuntimeException("Use spectronBehaviourFactory to create PvPspectronBehaviour with Loader");
    }
    public static function init(Loader $plugin) : void{
        $plugin->getServer()->getPluginManager()->registerEvents(new class($plugin) implements \pocketmine\event\Listener{
            private Loader $plugin;
            public function __construct(Loader $plugin){
                $this->plugin = $plugin;
            }
            public function onPlayerDeath(PlayerDeathEvent $event) : void{
                $player = $event->getPlayer();
                $last_damage = $player->getLastDamageCause();
                if(!($last_damage instanceof EntityDamageByEntityEvent)){
                    return;
                }
                $damager = $last_damage->getDamager();
                if(!($damager instanceof Player)){
                    return;
                }
                $fake_player = $this->plugin->getspectron($damager);
                if($fake_player === null){
                    return;
                }
                $settings = $this->plugin->getKillChatSettings();
                if($settings['enabled'] && !empty($settings['messages'])){
                    $message = $settings['messages'][array_rand($settings['messages'])];
                    $damager->chat($message);
                    $damager->getServer()->getLogger()->debug("Fake player {$damager->getName()} killed {$player->getName()} and sent chat: $message");
                }
            }
        }, $plugin);
    }
    protected float $reach_distance_sq;
    protected int $last_check = 0;
    protected ?int $target_entity_id = null;
    protected int $last_movement = 0;
    protected int $pvp_idle_time;
    protected Loader $plugin;
    public function __construct(float $reach_distance, int $pvp_idle_time, Loader $plugin){
        $this->reach_distance_sq = $reach_distance * $reach_distance;
        $this->pvp_idle_time = $pvp_idle_time;
        $this->plugin = $plugin;
    }
    protected function isValidTarget(Player $player, Entity $entity) : bool{
        return $entity !== $player && $entity instanceof Living && (!($entity instanceof Player) || ($entity->getGamemode()->equals(GameMode::SURVIVAL()) && $entity->isOnline()));
    }
    protected function getTargetEntity(Player $player) : ?Entity{
        return $this->target_entity_id !== null ? $player->getWorld()->getEntity($this->target_entity_id) : null;
    }
    protected function setTargetEntity(?Entity $target) : void{
        $this->target_entity_id = $target !== null ? $target->getId() : null;
    }
    protected function getSwordDamage(Item $item) : int{
        if(!($item instanceof Sword)){
            return 0;
        }
        return match ($item->getTypeId()) {
            VanillaItems::NETHERITE_SWORD()->getTypeId() => 8,
            VanillaItems::DIAMOND_SWORD()->getTypeId() => 7,
            VanillaItems::IRON_SWORD()->getTypeId() => 6,
            VanillaItems::STONE_SWORD()->getTypeId() => 5,
            VanillaItems::WOODEN_SWORD()->getTypeId(), VanillaItems::GOLDEN_SWORD()->getTypeId() => 4,
            default => 4 
        };
    }
    protected function equipBestSword(Player $player) : void{
        $inventory = $player->getInventory();
        $current = $inventory->getItemInHand();
        $best_sword = null;
        $best_damage = $this->getSwordDamage($current);
        $best_slot = -1;
        foreach($inventory->getContents() as $slot => $item){
            $damage = $this->getSwordDamage($item);
            if($damage > $best_damage){
                $best_sword = $item;
                $best_damage = $damage;
                $best_slot = $slot;
            }
        }
        if($best_sword !== null && $best_sword !== $current){
            if(!$current->isNull()){
                $player->getWorld()->dropItem($player->getPosition(), $current);
            }
            $inventory->setItemInHand($best_sword);
            $inventory->setItem($best_slot, VanillaItems::AIR());
            $player->getServer()->getLogger()->debug("Equipped best sword: " . $best_sword->getName() . " with damage $best_damage");
        }
    }
    public function onAddToPlayer(spectron $player) : void{
    }
    public function onRemoveFromPlayer(spectron $player) : void{
    }
    public function onRespawn(spectron $player) : void{
    }
   public function tick(spectron $fake_player) : void{
    if(!$this->plugin->isPvpEnabled()) {
        return;
    }
    $player = $fake_player->getPlayer();
    if($player->onGround && $player->isAlive()){
        $motion = $player->getMotion();
        if($motion->y == 0){
            $pos = $player->getPosition()->asVector3();
            $least_dist = INF;
            if($player->ticksLived - $this->last_check >= 50){
                $nearest_entity = null;
                foreach($player->getWorld()->getNearbyEntities(new AxisAlignedBB(
                    $pos->x - 8, $pos->y - 16, $pos->z - 8,
                    $pos->x + 8, $pos->y + 16, $pos->z + 8
                )) as $entity){
                    if($this->isValidTarget($player, $entity)){
                        $dist = $pos->distanceSquared($entity->getPosition());
                        if($dist < $least_dist){
                            $nearest_entity = $entity;
                            $least_dist = $dist;
                            if(mt_rand(1, 3) === 1){
                                break;
                            }
                        }
                    }
                }
                if($nearest_entity !== null){
                    $this->setTargetEntity($nearest_entity);
                    $this->last_check = $player->ticksLived;
                }
            }else{
                $nearest_entity = $this->getTargetEntity($player);
                if($nearest_entity !== null){
                    if($this->isValidTarget($player, $nearest_entity)){
                        $least_dist = $pos->distanceSquared($nearest_entity->getLocation());
                    }else{
                        $nearest_entity = null;
                        $this->setTargetEntity(null);
                    }
                }
            }
            if($nearest_entity !== null && $least_dist <= 256){
                $nearest_player_pos = $nearest_entity->getPosition();
                if($least_dist > ($nearest_entity->size->getWidth() + 6.25)){
                    $x = ($nearest_player_pos->x - $pos->x) + ((mt_rand() / mt_getrandmax()) * 2 - 1);
                    $z = ($nearest_player_pos->z - $pos->z) + ((mt_rand() / mt_getrandmax()) * 2 - 1);
                    $xz_modulus = sqrt($x * $x + $z * $z);
                    if($xz_modulus > 0.0){
                        $y = ($nearest_player_pos->y - $pos->y) / 16;
                        $this->setMotion($player, 0.4 * ($x / $xz_modulus), $y, 0.4 * ($z / $xz_modulus));
                    }
                }
                $player->lookAt($nearest_player_pos);
                if($least_dist <= $this->reach_distance_sq){
                    $this->equipBestSword($player);
                    $player->attackEntity($nearest_entity);
                }
            }
        }
    }
}
    private function setMotion(Player $player, float $x, float $y, float $z) : void{
        $player->setMotion(new Vector3($x, $y, $z));
        $this->last_movement = $player->ticksLived;
    }
}