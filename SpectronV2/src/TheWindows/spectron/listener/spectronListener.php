<?php

declare(strict_types=1);

namespace TheWindows\spectron\listener;

use pocketmine\player\Player;

interface spectronListener{

	public function onPlayerAdd(Player $player) : void;

	public function onPlayerRemove(Player $player) : void;
}