<?php

declare(strict_types=1);

namespace TheWindows\spectron;

use InvalidArgumentException;
use TheWindows\spectron\behaviour\spectronBehaviourFactory;
use TheWindows\spectron\behaviour\internal\spectronMovementData;
use TheWindows\spectron\behaviour\internal\TryChangeMovementInternalspectronBehaviour;
use TheWindows\spectron\behaviour\internal\UpdateMovementInternalspectronBehaviour;
use TheWindows\spectron\info\spectronInfo;
use TheWindows\spectron\listener\spectronListener;
use TheWindows\spectron\network\spectronNetworkSession;
use pocketmine\command\PluginCommand;
use pocketmine\entity\Skin;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\login\ClientData;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\player\Player;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Limits;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use function array_merge;
use function file_get_contents;
use function filesize;
use function json_encode;

final class Loader extends PluginBase implements Listener{

    /** @var spectronListener[] */
    private array $listeners = [];

    /** @var spectron[] */
    private array $fake_players = [];

    /** @var array<string, mixed> */
    private array $default_extra_data = [
        "CurrentInputMode" => InputMode::MOUSE_KEYBOARD,
        "DefaultInputMode" => InputMode::MOUSE_KEYBOARD,
        "DeviceOS" => DeviceOS::DEDICATED,
        "GameVersion" => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
    ];

    private bool $kill_chat_enabled = true;
    private bool $pvp_enabled = true;
    private bool $random_walk_enabled = true;
    private array $kill_chat_messages = [];
    private const MAX_KILL_CHAT_MESSAGES = 100;
    private ?spectronBehaviourFactory $behaviour_factory = null;
    private float $pvp_reach_distance = 4.0;
    private int $pvp_damage_cooldown = 20;
    private string $pvp_difficulty = "normal";

    public function setBehaviourFactory(spectronBehaviourFactory $factory) : void{
        $this->behaviour_factory = $factory;
    }

    protected function onEnable() : void{
        $this->getLogger()->info("§b
  ____                  _                __     ______    ___  
 / ___| _ __   ___  ___| |_ _ __ ___  _ _\ \   / /___ \  / _ \ 
 \___ \| '_ \ / _ \/ __| __| '__/ _ \| '_ \ \ / /  __) || | | |
  ___) | |_) |  __/ (__| |_| | | (_) | | | \ V /  / __/ | |_| |
 |____/| .__/ \___|\___|\__|_|  \___/|_| |_|\_/  |_____(_)___/ 
       |_|                      ReCreated By TheWindows©
                               
        ");
        $client_data = new ReflectionClass(ClientData::class);
        foreach($client_data->getProperties() as $property){
            $comment = $property->getDocComment();
            if($comment === false || !in_array("@required", explode(PHP_EOL, $comment), true)){
                continue;
            }

            $property_name = $property->getName();
            if(isset($this->default_extra_data[$property_name])){
                continue;
            }

            $this->default_extra_data[$property_name] = $property->hasDefaultValue() ? $property->getDefaultValue() : match($property->getType()?->getName()){
                "string" => "",
                "int" => 0,
                "array" => [],
                "bool" => false,
                default => throw new RuntimeException("Cannot map default value for property: " . ClientData::class . "::{$property_name}")
            };
        }

        $config_path = $this->getDataFolder() . "config.yml";
        if(file_exists($config_path) && filesize($config_path) > 10 * 1024 * 1024) {
            $this->getLogger()->warning("config.yml is too large (>10MB). Using default settings.");
            $this->kill_chat_enabled = true;
            $this->pvp_enabled = true;
            $this->random_walk_enabled = true;
            $this->kill_chat_messages = ["gg", "loser", "LOL", "Get rekt!", "Too easy!", "Nice try!", "Owned!"];
            $this->pvp_reach_distance = 4.0;
            $this->pvp_damage_cooldown = 20;
            $this->pvp_difficulty = "normal";
        } else {
            try {
                $this->saveResource("config.yml");
                $this->loadConfig();
            } catch (\Exception $e) {
                $this->getLogger()->warning("Failed to load config.yml: {$e->getMessage()}. Using default settings.");
                $this->kill_chat_enabled = true;
                $this->pvp_enabled = true;
                $this->random_walk_enabled = true;
                $this->kill_chat_messages = ["gg", "loser", "LOL", "Get rekt!", "Too easy!", "Nice try!", "Owned!"];
                $this->pvp_reach_distance = 4.0;
            }
        }
        $this->getLogger()->debug("Loaded config.yml: kill-chat-enabled={$this->kill_chat_enabled}, pvp-enabled={$this->pvp_enabled}, random-walk-enabled={$this->random_walk_enabled}, kill-chat-messages-count=" . count($this->kill_chat_messages) . ", pvp-reach-distance={$this->pvp_reach_distance}, pvp-damage-cooldown={$this->pvp_damage_cooldown}, pvp-difficulty={$this->pvp_difficulty}");

        $command = new PluginCommand("spectron", $this, new spectronCommandExecutor($this));
        $command->setPermission("spectron.command.spectron");
        $command->setDescription("Control fake player");
        $command->setAliases(["st"]);
        $this->getServer()->getCommandMap()->register($this->getName(), $command);

        $this->registerListener(new DefaultspectronListener($this));
        spectronBehaviourFactory::registerDefaults($this);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            foreach($this->fake_players as $player){
                $player->tick();
            }
        }), 1);

        $this->saveResource("players.json");

        $configured_players_add_delay = (int) $this->getConfig()->get("configured-players-add-delay");
        if($configured_players_add_delay === -1){
            $this->addConfiguredPlayers();
        }else{
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() : void{
                $this->addConfiguredPlayers();
            }), $configured_players_add_delay);
        }
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    private function loadConfig(): void{
        $config = $this->getConfig();
        $this->kill_chat_enabled = $config->get("kill-chat-enabled", true);
        $this->pvp_enabled = $config->get("pvp-enabled", true);
        $this->random_walk_enabled = $config->get("random-walk-enabled", true);
        $this->kill_chat_messages = $config->get("kill-chat-messages", ["gg", "loser", "LOL", "Get rekt!", "Too easy!", "Nice try!", "Owned!"]);
        $this->pvp_reach_distance = (float) $config->get("pvp-reach-distance", 4.0);
        $this->pvp_damage_cooldown = (int) $config->get("pvp-damage-cooldown", 20);
        $this->pvp_difficulty = (string) $config->get("pvp-difficulty", "normal");

        if (!is_bool($this->kill_chat_enabled)) {
            $this->getLogger()->warning("Invalid 'kill-chat-enabled' in config.yml, expected boolean, got " . gettype($this->kill_chat_enabled) . ". Using default: true");
            $this->kill_chat_enabled = true;
        }

        if (!is_bool($this->pvp_enabled)) {
            $this->getLogger()->warning("Invalid 'pvp-enabled' in config.yml, expected boolean, got " . gettype($this->pvp_enabled) . ". Using default: true");
            $this->pvp_enabled = true;
        }

        if (!is_bool($this->random_walk_enabled)) {
            $this->getLogger()->warning("Invalid 'random-walk-enabled' in config.yml, expected boolean, got " . gettype($this->random_walk_enabled) . ". Using default: true");
            $this->random_walk_enabled = true;
        }

        if (!is_array($this->kill_chat_messages) || empty($this->kill_chat_messages)) {
            $this->getLogger()->warning("Invalid or empty 'kill-chat-messages' in config.yml. Using default messages.");
            $this->kill_chat_messages = ["gg", "loser", "LOL", "Get rekt!", "Too easy!", "Nice try!", "Owned!"];
        } else {
            $this->kill_chat_messages = array_slice($this->kill_chat_messages, 0, self::MAX_KILL_CHAT_MESSAGES);
            foreach($this->kill_chat_messages as $index => $message) {
                if (!is_string($message) || trim($message) === "") {
                    $this->getLogger()->warning("Invalid message at index $index in 'kill-chat-messages'. Removing it.");
                    unset($this->kill_chat_messages[$index]);
                }
            }
            if (empty($this->kill_chat_messages)) {
                $this->getLogger()->warning("No valid messages in 'kill-chat-messages'. Using default messages.");
                $this->kill_chat_messages = ["gg", "loser", "LOL", "Get rekt!", "Too easy!", "Nice try!", "Owned!"];
            }
        }
        $this->kill_chat_messages = array_values($this->kill_chat_messages);

        if (!is_float($this->pvp_reach_distance) || $this->pvp_reach_distance < 1.0 || $this->pvp_reach_distance > 10.0) {
            $this->getLogger()->warning("Invalid 'pvp-reach-distance' in config.yml, expected float between 1.0 and 10.0, got " . gettype($this->pvp_reach_distance) . ". Using default: 4.0");
            $this->pvp_reach_distance = 4.0;
        }

        if (!is_int($this->pvp_damage_cooldown) || $this->pvp_damage_cooldown < 10 || $this->pvp_damage_cooldown > 60) {
            $this->getLogger()->warning("Invalid 'pvp-damage-cooldown' in config.yml, expected integer between 10 and 60, got " . gettype($this->pvp_damage_cooldown) . ". Using default: 20");
            $this->pvp_damage_cooldown = 20;
        }

        if (!in_array($this->pvp_difficulty, ["easy", "normal", "hard"], true)) {
            $this->getLogger()->warning("Invalid 'pvp-difficulty' in config.yml, expected 'easy', 'normal', or 'hard', got " . $this->pvp_difficulty . ". Using default: normal");
            $this->pvp_difficulty = "normal";
        }
    }

    public function saveConfigFile(): void{
        $config = $this->getConfig();
        $config->set("kill-chat-enabled", $this->kill_chat_enabled);
        $config->set("pvp-enabled", $this->pvp_enabled);
        $config->set("random-walk-enabled", $this->random_walk_enabled);
        $config->set("kill-chat-messages", $this->kill_chat_messages);
        $config->set("pvp-reach-distance", $this->pvp_reach_distance);
        $config->set("pvp-damage-cooldown", $this->pvp_damage_cooldown);
        $config->set("pvp-difficulty", $this->pvp_difficulty);
        $config->save();
        $this->getLogger()->debug("Saved config.yml: kill-chat-enabled={$this->kill_chat_enabled}, pvp-enabled={$this->pvp_enabled}, random-walk-enabled={$this->random_walk_enabled}, kill-chat-messages-count=" . count($this->kill_chat_messages) . ", pvp-reach-distance={$this->pvp_reach_distance}, pvp-damage-cooldown={$this->pvp_damage_cooldown}, pvp-difficulty={$this->pvp_difficulty}");
    }

    public function setKillChatEnabled(bool $enabled): void{
        $this->kill_chat_enabled = $enabled;
        $this->saveConfigFile();
        $this->getLogger()->debug("Set kill-chat-enabled to {$enabled}");
    }

    public function setPvpEnabled(bool $enabled): void{
        $this->pvp_enabled = $enabled;
        $this->saveConfigFile();
        $this->updatePvpBehaviors();
        $this->getLogger()->debug("Set pvp-enabled to {$enabled} and updated active players");
    }

    public function isPvpEnabled(): bool{
        return $this->pvp_enabled;
    }

    public function setRandomWalkEnabled(bool $enabled): void{
        $this->random_walk_enabled = $enabled;
        $this->saveConfigFile();
        $this->updatePvpBehaviors();
        $this->getLogger()->debug("Set random-walk-enabled to {$enabled} and updated active players");
    }

    public function isRandomWalkEnabled(): bool{
        return $this->random_walk_enabled;
    }

    public function setPvpReachDistance(float $distance): bool{
        if ($distance < 1.0 || $distance > 10.0) {
            $this->getLogger()->warning("Invalid reach distance: $distance. Must be between 1.0 and 10.0.");
            return false;
        }
        $this->pvp_reach_distance = $distance;
        $this->saveConfigFile();
        $this->updatePvpBehaviors();
        $this->getLogger()->debug("Set pvp-reach-distance to $distance and updated active players");
        return true;
    }

    public function getPvpReachDistance(): float{
        return $this->pvp_reach_distance;
    }

    public function setPvpDamageCooldown(int $ticks): bool{
        if ($ticks < 10 || $ticks > 60) {
            $this->getLogger()->warning("Invalid damage cooldown: $ticks. Must be between 10 and 60 ticks.");
            return false;
        }
        $this->pvp_damage_cooldown = $ticks;
        $this->saveConfigFile();
        $this->updatePvpBehaviors();
        $this->getLogger()->debug("Set pvp-damage-cooldown to $ticks and updated active players");
        return true;
    }

    public function getPvpDamageCooldown(): int{
        return $this->pvp_damage_cooldown;
    }

    public function setPvpDifficulty(string $difficulty): bool{
        if (!in_array($difficulty, ["easy", "normal", "hard"], true)) {
            $this->getLogger()->warning("Invalid difficulty: $difficulty. Must be 'easy', 'normal', or 'hard'.");
            return false;
        }
        $this->pvp_difficulty = $difficulty;
        $this->saveConfigFile();
        $this->updatePvpBehaviors();
        $this->getLogger()->debug("Set pvp-difficulty to $difficulty and updated active players");
        return true;
    }

    public function getPvpDifficulty(): string{
        return $this->pvp_difficulty;
    }

    private function updatePvpBehaviors(): void{
        $players = json_decode(Filesystem::fileGetContents($this->getDataFolder() . "players.json"), true, 512, JSON_THROW_ON_ERROR);
        $players_to_respawn = [];

        foreach($this->fake_players as $uuid => $fake_player){
            $player = $fake_player->getPlayer();
            if($player === null || !$player->isOnline()){
                $this->getLogger()->debug("Skipping behavior update for offline player with UUID $uuid");
                continue;
            }

            $uuid_str = $player->getUniqueId()->toString();
            if(!Uuid::isValid($uuid_str)){
                $this->getLogger()->warning("Invalid UUID for player {$player->getName()}: $uuid_str. Skipping respawn.");
                continue;
            }
            $behaviours = $players[$uuid_str]['behaviours'] ?? [];

            if($this->pvp_enabled){
                $behaviours["spectron:pvp"] = [
                    "reach_distance" => $this->pvp_reach_distance,
                    "pvp_idle_time" => 500,
                    "damage_cooldown" => $this->pvp_damage_cooldown,
                    "difficulty" => $this->pvp_difficulty,
                    "random_walk_enabled" => $this->random_walk_enabled
                ];
            } else {
                unset($behaviours["spectron:pvp"]);
            }

            if(isset($players[$uuid_str])){
                $players[$uuid_str]['behaviours'] = $behaviours;
            } else {
                $this->getLogger()->warning("Player $uuid_str not found in players.json. Adding new entry.");
                $players[$uuid_str] = [
                    "xuid" => $player->getXuid(),
                    "gamertag" => $player->getName(),
                    "extra_data" => [],
                    "behaviours" => $behaviours
                ];
            }

            $players_to_respawn[] = [
                'name' => $player->getName(),
                'position' => $player->getPosition(),
                'uuid' => $uuid_str,
                'behaviours' => $behaviours
            ];
        }

        $this->savePlayersJson($players);

        foreach($players_to_respawn as $data){
            $this->removePlayer($this->getServer()->getPlayerByRawUUID(Uuid::fromString($data['uuid'])->getBytes()));
            $this->spawnPlayer($data['name'], $data['position'], $data['uuid'], $data['behaviours']);
            $this->getLogger()->debug("Respawned player {$data['name']} (UUID: {$data['uuid']}) with updated behaviors: pvp-enabled={$this->pvp_enabled}, random-walk-enabled={$this->random_walk_enabled}, reach_distance={$this->pvp_reach_distance}, damage_cooldown={$this->pvp_damage_cooldown}, difficulty={$this->pvp_difficulty}");
        }
    }

    public function addKillChatMessage(string $message) : bool{
        if(trim($message) === "") {
            $this->getLogger()->warning("Cannot add empty kill chat message");
            return false;
        }
        if(count($this->kill_chat_messages) >= self::MAX_KILL_CHAT_MESSAGES) {
            $this->getLogger()->warning("Cannot add kill chat message: Maximum limit of " . self::MAX_KILL_CHAT_MESSAGES . " messages reached");
            return false;
        }
        $this->kill_chat_messages[] = $message;
        $this->kill_chat_messages = array_values($this->kill_chat_messages);
        $this->saveConfigFile();
        $this->getLogger()->debug("Added kill chat message: $message");
        return true;
    }

    public function removeKillChatMessage(string $message) : bool{
        $index = array_search($message, $this->kill_chat_messages, true);
        if($index === false) {
            $this->getLogger()->warning("Cannot remove kill chat message: '$message' not found");
            return false;
        }
        unset($this->kill_chat_messages[$index]);
        $this->kill_chat_messages = array_values($this->kill_chat_messages);
        $this->saveConfigFile();
        $this->getLogger()->debug("Removed kill chat message: $message");
        return true;
    }

    public function getKillChatSettings(): array{
        return [
            'enabled' => $this->kill_chat_enabled,
            'messages' => $this->kill_chat_messages
        ];
    }

    public function getPvpSettings(): array{
        return [
            'enabled' => $this->pvp_enabled,
            'reach_distance' => $this->pvp_reach_distance,
            'damage_cooldown' => $this->pvp_damage_cooldown,
            'difficulty' => $this->pvp_difficulty
        ];
    }

    public function spawnPlayer(string $name, ?Vector3 $position = null, ?string $uuid = null, ?array $behaviours = null) : bool{
        if($this->getServer()->getPlayerByPrefix($name) !== null){
            return false;
        }

        $players = json_decode(Filesystem::fileGetContents($this->getDataFolder() . "players.json"), true, 512, JSON_THROW_ON_ERROR);
        $existing_uuid = null;
        foreach($players as $player_uuid => $data){
            if($data['gamertag'] === $name){
                if(Uuid::isValid($player_uuid)){
                    $existing_uuid = $player_uuid;
                }else{
                    $this->getLogger()->warning("Invalid UUID in players.json for gamertag $name: $player_uuid. Ignoring entry.");
                    unset($players[$player_uuid]);
                }
            }
        }

        $uuid = $uuid ?? $existing_uuid ?? Uuid::uuid4()->toString();
        if(!Uuid::isValid($uuid)){
            $this->getLogger()->warning("Invalid UUID provided for player $name: $uuid. Generating new UUID.");
            $uuid = Uuid::uuid4()->toString();
        }

        $xuid = sprintf("%016x", mt_rand());
        $skin_data = file_get_contents($this->getResourcePath("skin.rgba"));
        $skin_data !== false || throw new RuntimeException("Failed to read default skin data");
        $skin = new Skin("Standard_Custom", $skin_data);
        $default_behaviours = [
            "spectron:auto_equip_armor" => []
        ];
        if($this->pvp_enabled){
            $default_behaviours["spectron:pvp"] = [
                "reach_distance" => $this->pvp_reach_distance,
                "pvp_idle_time" => 500,
                "damage_cooldown" => $this->pvp_damage_cooldown,
                "difficulty" => $this->pvp_difficulty,
                "random_walk_enabled" => $this->random_walk_enabled
            ];
        }
        $behaviours = $behaviours ?? $default_behaviours;

        $extra_data = $players[$existing_uuid]['extra_data'] ?? [];
        $promise = $this->addPlayer(new spectronInfo(Uuid::fromString($uuid), $xuid, $name, $skin, $extra_data, $behaviours));
        $promise->onCompletion(function(Player $player) use($position) : void{
            if($position !== null){
                $player->teleport($position);
            }
            $this->getLogger()->debug("Spawned fake player: {$player->getName()} with UUID: {$player->getUniqueId()->toString()}");
        }, function() : void{
            $this->getLogger()->warning("Failed to spawn fake player");
        });

        $players[$uuid] = [
            "xuid" => $xuid,
            "gamertag" => $name,
            "extra_data" => $extra_data,
            "behaviours" => $behaviours
        ];
        $this->savePlayersJson($players);

        return true;
    }

    public function removePlayerByName(string $name) : bool{
        $player = $this->getServer()->getPlayerByPrefix($name);
        if($player === null || !$this->isspectron($player)){
            return false;
        }

        $uuid = $player->getUniqueId()->toString();
        $this->removePlayer($player);

        $players = json_decode(Filesystem::fileGetContents($this->getDataFolder() . "players.json"), true, 512, JSON_THROW_ON_ERROR);
        unset($players[$uuid]);
        $this->savePlayersJson($players);

        $this->getLogger()->debug("Removed fake player: $name with UUID: $uuid");
        return true;
    }

    public function resetPlayers() : void{
        foreach($this->fake_players as $player){
            $this->removePlayer($player->getPlayer());
        }
        $this->fake_players = [];
        $this->addConfiguredPlayers();
        $this->getLogger()->debug("Reset all fake players");
    }

    private function savePlayersJson(array $players) : void{
        $cleaned_players = [];
        foreach($players as $uuid => $data){
            if(Uuid::isValid($uuid)){
                $cleaned_players[$uuid] = $data;
            }else{
                $this->getLogger()->warning("Skipping invalid UUID in players.json: $uuid");
            }
        }
        $json = json_encode($cleaned_players, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        Filesystem::safeFilePutContents($this->getDataFolder() . "players.json", $json);
    }

    public function registerListener(spectronListener $listener) : void{
        $this->listeners[spl_object_id($listener)] = $listener;
        $server = $this->getServer();
        foreach($this->fake_players as $uuid => $_){
            $player = $server->getPlayerByRawUUID(Uuid::fromString($uuid)->getBytes());
            if($player !== null){
                $listener->onPlayerAdd($player);
            }
        }
    }

    public function unregisterListener(spectronListener $listener) : void{
        unset($this->listeners[spl_object_id($listener)]);
    }

    public function isspectron(Player $player) : bool{
        return isset($this->fake_players[$player->getUniqueId()->toString()]);
    }

    public function getspectron(Player $player) : ?spectron{
        return $this->fake_players[$player->getUniqueId()->toString()] ?? null;
    }

    public function addPlayer(spectronInfo $info) : Promise{
        $server = $this->getServer();
        $network = $server->getNetwork();
        $type_converter = TypeConverter::getInstance();
        $packet_broadcaster = new StandardPacketBroadcaster($this->getServer(), ProtocolInfo::CURRENT_PROTOCOL);
        $entity_event_broadcaster = new StandardEntityEventBroadcaster($packet_broadcaster, $type_converter);

        $internal_resolver = new PromiseResolver();
        $session = new spectronNetworkSession(
            $server,
            $network->getSessionManager(),
            PacketPool::getInstance(),
            new FakePacketSender(),
            $packet_broadcaster,
            $entity_event_broadcaster,
            ZlibCompressor::getInstance(),
            $type_converter,
            $server->getIp(),
            $server->getPort(),
            $internal_resolver
        );
        $network->getSessionManager()->add($session);

        $rp = new ReflectionProperty(spectronNetworkSession::class, "info");
        $rp->setValue($session, new XboxLivePlayerInfo($info->xuid, $info->username, $info->uuid, $info->skin, "en_US", array_merge($info->extra_data, $this->default_extra_data)));

        $rp = new ReflectionMethod(spectronNetworkSession::class, "onServerLoginSuccess");
        $rp->invoke($session);

        $packet = ResourcePackClientResponsePacket::create(ResourcePackClientResponsePacket::STATUS_COMPLETED, []);
        $serializer = PacketSerializer::encoder(ProtocolInfo::CURRENT_PROTOCOL);
        $packet->encode($serializer);
        $session->handleDataPacket($packet, $serializer->getBuffer());

        $internal_resolver->getPromise()->onCompletion(function(Player $player) use($info, $session) : void{
            $player->setViewDistance(4);

            $uuid_str = $player->getUniqueId()->toString();
            $this->fake_players[$uuid_str] = $fake_player = new spectron($session);

            $movement_data = new spectronMovementData();
            $fake_player->addBehaviour(new TryChangeMovementInternalspectronBehaviour($movement_data, $this), Limits::INT32_MIN);
            $fake_player->addBehaviour(new UpdateMovementInternalspectronBehaviour($movement_data), Limits::INT32_MAX);
            foreach($info->behaviours as $behaviour_identifier => $behaviour_data){
                $fake_player->addBehaviour($this->behaviour_factory->create($behaviour_identifier, $behaviour_data));
            }

            foreach($this->listeners as $listener){
                $listener->onPlayerAdd($player);
            }

            if(!$player->isAlive()){
                $player->respawn();
            }
        }, static function() : void{});

        $result = new PromiseResolver();
        $internal_resolver->getPromise()->onCompletion(static function(Player $player) use($result) : void{
            $result->resolve($player);
        }, static function() use($result) : void{ $result->reject(); });
        return $result->getPromise();
    }

    public function removePlayer(Player $player, bool $disconnect = true) : void{
        $uuid_str = $player->getUniqueId()->toString();
        if(!$this->isspectron($player)){
            throw new InvalidArgumentException("Invalid Player supplied, expected a fake player, got " . $player->getName());
        }

        if(!isset($this->fake_players[$uuid_str])){
            return;
        }

        $this->fake_players[$uuid_str]->destroy();
        unset($this->fake_players[$uuid_str]);

        if($disconnect){
            $player->disconnect("Removed");
        }

        foreach($this->listeners as $listener){
            $listener->onPlayerRemove($player);
        }
    }

    public function addConfiguredPlayers() : array{
        $players = json_decode(Filesystem::fileGetContents($this->getDataFolder() . "players.json"), true, 512, JSON_THROW_ON_ERROR);

        $skin_data = file_get_contents($this->getResourcePath("skin.rgba"));
        $skin_data !== false || throw new RuntimeException("Failed to read default skin data");
        $skin = new Skin("Standard_Custom", $skin_data);

        $promises = [];
        $seen_gamertags = [];
        foreach($players as $uuid => $data){
            if(!Uuid::isValid($uuid)){
                $this->getLogger()->warning("Invalid UUID in players.json: $uuid. Skipping player.");
                continue;
            }
            ["xuid" => $xuid, "gamertag" => $gamertag] = $data;
            if(in_array($gamertag, $seen_gamertags)){
                $this->getLogger()->debug("Skipping duplicate gamertag: $gamertag (UUID: $uuid)");
                continue;
            }
            if($this->getServer()->getPlayerByRawUUID(Uuid::fromString($uuid)->getBytes()) !== null){
                $this->getLogger()->debug("Skipping duplicate player: $gamertag (UUID: $uuid)");
                continue;
            }
            $seen_gamertags[] = $gamertag;
            $behaviours = $data["behaviours"] ?? [];
            if($this->pvp_enabled){
                $behaviours["spectron:pvp"] = [
                    "reach_distance" => $this->pvp_reach_distance,
                    "pvp_idle_time" => 500,
                    "damage_cooldown" => $this->pvp_damage_cooldown,
                    "difficulty" => $this->pvp_difficulty,
                    "random_walk_enabled" => $this->random_walk_enabled
                ];
            } else {
                unset($behaviours["spectron:pvp"]);
            }
            $promises[$uuid] = $this->addPlayer(new spectronInfo(Uuid::fromString($uuid), $xuid, $gamertag, $skin, $data["extra_data"] ?? [], $behaviours));
        }
        return $promises;
    }

    public function onPlayerQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        try{
            $this->removePlayer($player, false);
        }catch(InvalidArgumentException $e){
        }
    }
}