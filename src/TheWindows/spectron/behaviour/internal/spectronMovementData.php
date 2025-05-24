<?php

declare(strict_types=1);

namespace TheWindows\spectron\behaviour\internal;

use pocketmine\math\Vector3;
use pocketmine\world\Position;

class spectronMovementData {
    public Vector3 $motion;
    public bool $is_jumping = false;
    public bool $needs_jump = false;
    public bool $jump_forward = false;
    public ?Vector3 $random_walk_target = null;
    public int $random_walk_ticks = 0;
    public string $movement_mode = 'walk';
    public bool $is_pvp_active = false;
    public ?string $bridging_state = null;
    public ?Position $bridging_block = null;
    public int $bridging_cooldown = 0;
    public ?string $climbing_state = null;
    public ?Position $climbing_target = null;
    public int $pathfinding_cooldown = 0;
    public int $climbing_cooldown = 0;
    public int $stuck_ticks = 0;
    public ?int $target_item_id = null;
    public function __construct()
    {
        $this->motion = new Vector3(0, 0, 0);
    }
}