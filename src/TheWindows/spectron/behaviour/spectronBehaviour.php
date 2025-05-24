<?php

declare(strict_types=1);

namespace TheWindows\spectron\behaviour;

use TheWindows\spectron\spectron;
use TheWindows\spectron\Loader;

interface spectronBehaviour{

	public static function init(Loader $plugin) : void;

	/**
	 * @param mixed[] $data
	 * @return static
	 *
	 * @phpstan-param array<string, mixed> $data
	 */
	public static function create(array $data) : self;

	public function onAddToPlayer(spectron $player) : void;

	public function onRemoveFromPlayer(spectron $player) : void;

	public function tick(spectron $player) : void;

	public function onRespawn(spectron $player) : void;
}