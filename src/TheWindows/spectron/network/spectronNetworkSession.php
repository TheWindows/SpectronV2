<?php

declare(strict_types=1);

namespace TheWindows\spectron\network;

use TheWindows\spectron\network\listener\spectronPacketListener;
use TheWindows\spectron\network\listener\spectronSpecificPacketListener;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\NetworkSessionManager;
use pocketmine\player\Player;
use pocketmine\promise\PromiseResolver;
use pocketmine\Server;
use ReflectionMethod;
use ReflectionProperty;

class spectronNetworkSession extends NetworkSession{

	/** @var spectronPacketListener[] */
	private array $packet_listeners = [];

	/** @var PromiseResolver<Player>|null */
	private ?PromiseResolver $player_add_resolver;

	private ?spectronSpecificPacketListener $specific_packet_listener = null;

	/**
	 * @param Server $server
	 * @param NetworkSessionManager $manager
	 * @param PacketPool $packetPool
	 * @param PacketSender $sender
	 * @param PacketBroadcaster $broadcaster
	 * @param EntityEventBroadcaster $entityEventBroadcaster
	 * @param Compressor $compressor
	 * @param TypeConverter $typeConverter
	 * @param string $ip
	 * @param int $port
	 * @param PromiseResolver<Player> $player_add_resolver
	 */
	public function __construct(
		Server $server,
		NetworkSessionManager $manager,
		PacketPool $packetPool,
		PacketSender $sender,
		PacketBroadcaster $broadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		Compressor $compressor,
		TypeConverter $typeConverter,
		string $ip,
		int $port,
		PromiseResolver $player_add_resolver
	){
		parent::__construct($server, $manager, $packetPool, $sender, $broadcaster, $entityEventBroadcaster, $compressor, $typeConverter, $ip, $port);
		$this->player_add_resolver = $player_add_resolver;

		// do not store the resolver eternally
		$this->player_add_resolver->getPromise()->onCompletion(function(Player $_) : void{
			$this->player_add_resolver = null;
		}, function() : void{ $this->player_add_resolver = null; });
	}

	public function registerPacketListener(spectronPacketListener $listener) : void{
		$this->packet_listeners[spl_object_id($listener)] = $listener;
	}

	public function unregisterPacketListener(spectronPacketListener $listener) : void{
		unset($this->packet_listeners[spl_object_id($listener)]);
	}

	public function registerSpecificPacketListener(string $packet, spectronPacketListener $listener) : void{
		if($this->specific_packet_listener === null){
			$this->specific_packet_listener = new spectronSpecificPacketListener();
			$this->registerPacketListener($this->specific_packet_listener);
		}
		$this->specific_packet_listener->register($packet, $listener);
	}

	public function unregisterSpecificPacketListener(string $packet, spectronPacketListener $listener) : void{
		if($this->specific_packet_listener !== null){
			$this->specific_packet_listener->unregister($packet, $listener);
			if($this->specific_packet_listener->isEmpty()){
				$this->unregisterPacketListener($this->specific_packet_listener);
				$this->specific_packet_listener = null;
			}
		}
	}

	public function addToSendBuffer(string $buffer) : void{
		parent::addToSendBuffer($buffer);
		$rp = new ReflectionProperty(NetworkSession::class, 'packetPool');
		$packetPool = $rp->getValue($this);
		$packet = $packetPool->getPacket($buffer);
		$packet->decode(PacketSerializer::decoder(ProtocolInfo::CURRENT_PROTOCOL, $buffer, 0)); // Fixed: Pass protocol ID
		foreach($this->packet_listeners as $listener){
			$listener->onPacketSend($packet, $this);
		}
	}

	protected function createPlayer(): void{
		$get_prop = function(string $name) : mixed{
			$rp = new ReflectionProperty(NetworkSession::class, $name);
			return $rp->getValue($this);
		};

		$info = $get_prop("info");
		$authenticated = $get_prop("authenticated");
		$cached_offline_player_data = $get_prop("cachedOfflinePlayerData");
		Server::getInstance()->createPlayer($this, $info, $authenticated, $cached_offline_player_data)->onCompletion(function(Player $player) : void{
			$this->onPlayerCreated($player);
		}, function() : void{
			$this->disconnect("Player creation failed");
			$this->player_add_resolver->reject();
		});
	}

	private function onPlayerCreated(Player $player) : void{
		// call parent private method
		$rm = new ReflectionMethod(NetworkSession::class, "onPlayerCreated");
		$rm->invoke($this, $player);
		$this->player_add_resolver->resolve($player);
	}
}