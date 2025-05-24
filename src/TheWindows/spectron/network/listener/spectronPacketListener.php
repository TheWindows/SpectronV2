<?php

declare(strict_types=1);

namespace TheWindows\spectron\network\listener;

use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;

interface spectronPacketListener{

	public function onPacketSend(ClientboundPacket $packet, NetworkSession $session) : void;
}