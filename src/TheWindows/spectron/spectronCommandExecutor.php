<?php

declare(strict_types=1);

namespace TheWindows\spectron;

use JsonException;
use TheWindows\spectron\network\spectronNetworkSession;
use TheWindows\spectron\network\listener\ClosurespectronPacketListener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\CommandExecutor;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use ReflectionProperty;
use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\ModalForm;

final class spectronCommandExecutor implements CommandExecutor {

    public function __construct(
        private Loader $plugin
    ){}

    private function sendServerPacket(Player $sender, Packet $packet) : void{
        $serializer = PacketSerializer::encoder(ProtocolInfo::CURRENT_PROTOCOL);
        $packet->encode($serializer);
        $sender->getNetworkSession()->handleDataPacket($packet, $serializer->getBuffer());
    }

    private function getOnlinespectrons() : array{
        $players = [];
        foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
            if($this->plugin->isspectron($player) && $player->isOnline()){
                $players[] = $player->getName();
            }
        }
        return $players;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(!isset($args[0])){
            return $this->sendHelp($sender, $label);
        }

        switch($args[0]){
            case "menu":
                if(!$sender instanceof Player){
                    $sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
                    return true;
                }
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                $this->openMainMenu($sender);
                return true;
            case "tpall":
                if($sender instanceof Player){
                    $pos = $sender->getPosition();
                    foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
                        if($this->plugin->isspectron($player)){
                            $player->teleport($pos);
                            $this->plugin->getLogger()->debug("Teleported fake player {$player->getName()} to {$pos->asVector3()}");
                        }
                    }
                    $sender->sendMessage(TextFormat::GREEN . "Teleported all fake players to your position.");
                }else{
                    $sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
                }
                return true;
            case "addmessage":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                if(isset($args[1])){
                    $message = implode(" ", array_slice($args, 1));
                    if($this->plugin->addKillChatMessage($message)){
                        $sender->sendMessage(TextFormat::GREEN . "Added kill chat message: " . $message);
                    }else{
                        $sender->sendMessage(TextFormat::RED . "Failed to add message: Message cannot be empty or limit reached.");
                    }
                }else{
                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " addmessage <message>");
                }
                return true;
            case "removemessage":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                if(isset($args[1])){
                    $message = implode(" ", array_slice($args, 1));
                    if($this->plugin->removeKillChatMessage($message)){
                        $sender->sendMessage(TextFormat::GREEN . "Removed kill chat message: " . $message);
                    }else{
                        $sender->sendMessage(TextFormat::RED . "Failed to remove message: Message not found.");
                    }
                }else{
                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " removemessage <message>");
                }
                return true;
            case "togglekillchat":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                $current = $this->plugin->getKillChatSettings()['enabled'];
                $this->plugin->setKillChatEnabled(!$current);
                $sender->sendMessage(TextFormat::GREEN . "Kill chat " . (!$current ? "enabled" : "disabled") . ".");
                return true;
            case "togglepvp":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                $current = $this->plugin->isPvpEnabled();
                $this->plugin->setPvpEnabled(!$current);
                $sender->sendMessage(TextFormat::GREEN . "PvP for fake players " . (!$current ? "enabled" : "disabled") . ".");
                return true;
            case "togglerandomwalk":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                $current = $this->plugin->isRandomWalkEnabled();
                $this->plugin->setRandomWalkEnabled(!$current);
                $sender->sendMessage(TextFormat::GREEN . "Random walk for fake players " . (!$current ? "enabled" : "disabled") . ".");
                return true;
            case "setreach":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                if(isset($args[1]) && is_numeric($args[1])){
                    $reach = (float) $args[1];
                    if($this->plugin->setPvpReachDistance($reach)){
                        $sender->sendMessage(TextFormat::GREEN . "Set PvP reach distance to $reach blocks.");
                    }else{
                        $sender->sendMessage(TextFormat::RED . "Failed to set reach: Must be between 1.0 and 10.0 blocks.");
                    }
                }else{
                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " setreach <distance>");
                }
                return true;
            case "setcooldown":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                if(isset($args[1]) && is_numeric($args[1])){
                    $cooldown = (int) $args[1];
                    if($this->plugin->setPvpDamageCooldown($cooldown)){
                        $sender->sendMessage(TextFormat::GREEN . "Set PvP damage cooldown to $cooldown ticks.");
                    }else{
                        $sender->sendMessage(TextFormat::RED . "Failed to set cooldown: Must be between 10 and 60 ticks.");
                    }
                }else{
                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " setcooldown <ticks>");
                }
                return true;
            case "setdifficulty":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                if(isset($args[1]) && in_array(strtolower($args[1]), ["easy", "normal", "hard"], true)){
                    $difficulty = strtolower($args[1]);
                    if($this->plugin->setPvpDifficulty($difficulty)){
                        $sender->sendMessage(TextFormat::GREEN . "Set PvP difficulty to $difficulty.");
                    }else{
                        $sender->sendMessage(TextFormat::RED . "Failed to set difficulty: Must be 'easy', 'normal', or 'hard'.");
                    }
                }else{
                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " setdifficulty <easy|normal|hard>");
                }
                return true;
            case "spawn":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                if(isset($args[1])){
                    $name = $args[1];
                    $position = $sender instanceof Player ? $sender->getPosition() : null;
                    if($this->plugin->spawnPlayer($name, $position)){
                        $sender->sendMessage(TextFormat::GREEN . "Spawned fake player: $name");
                    }else{
                        $sender->sendMessage(TextFormat::RED . "Failed to spawn: Player '$name' already exists.");
                    }
                }else{
                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " spawn <name>");
                }
                return true;
            case "remove":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                if(isset($args[1])){
                    $name = $args[1];
                    if($this->plugin->removePlayerByName($name)){
                        $sender->sendMessage(TextFormat::GREEN . "Removed fake player: $name");
                    }else{
                        $sender->sendMessage(TextFormat::RED . "Failed to remove: Fake player '$name' not found.");
                    }
                }else{
                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " remove <name>");
                }
                return true;
            case "reset":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                $this->plugin->resetPlayers();
                $sender->sendMessage(TextFormat::GREEN . "Reset all fake players.");
                return true;
            case "info":
                if(!$sender->hasPermission("spectron.command.spectron")){
                    $sender->sendMessage(TextFormat::RED . "You do not have permission to use this command.");
                    return true;
                }
                $plugin = $this->plugin;
                $description = $plugin->getDescription();
                $sender->sendMessage(TextFormat::BLUE . "=== Spectron Plugin Info ===");
                $sender->sendMessage(TextFormat::AQUA . "Name: " . TextFormat::WHITE . $description->getName());
                $sender->sendMessage(TextFormat::AQUA . "Version: " . TextFormat::WHITE . $description->getVersion());
                $sender->sendMessage(TextFormat::AQUA . "Creator: " . TextFormat::WHITE . implode(", ", $description->getAuthors()));
                $sender->sendMessage(TextFormat::AQUA . "API: " . TextFormat::WHITE . implode(", ", $description->getCompatibleApis()));
                $sender->sendMessage(TextFormat::AQUA . "Description: " . TextFormat::WHITE . $description->getDescription());
                $sender->sendMessage(TextFormat::BLUE . "====================");
                return true;
            default:
                if(isset($args[1])){
                    $player = $this->plugin->getServer()->getPlayerByPrefix($args[0]);
                    if($player !== null){
                        if($this->plugin->isspectron($player)){
                            /** @var spectronNetworkSession $session */
                            $session = $player->getNetworkSession();
                            switch($args[1]){
                                case "chat":
                                    if(isset($args[2])){
                                        $chat = implode(" ", array_slice($args, 2));
                                        if(!$player->isOnline() || !$session instanceof spectronNetworkSession){
                                            $sender->sendMessage(TextFormat::RED . "Cannot send chat: Player is offline or session is invalid.");
                                            return true;
                                        }
                                        try {
                                            $listener = new ClosurespectronPacketListener(function(ClientboundPacket $packet, NetworkSession $session) use($sender) : void{
                                                try {
                                                    if(!$packet instanceof TextPacket){
                                                        $this->plugin->getLogger()->warning("Received unexpected packet type: " . get_class($packet));
                                                        return;
                                                    }
                                                    if($packet->type !== TextPacket::TYPE_JUKEBOX_POPUP && $packet->type !== TextPacket::TYPE_POPUP && $packet->type !== TextPacket::TYPE_TIP){
                                                        $sender->sendMessage($packet->message);
                                                    }
                                                } catch (\Exception $e) {
                                                    $this->plugin->getLogger()->error("Error processing TextPacket: " . $e->getMessage());
                                                }
                                            });
                                            $session->registerSpecificPacketListener(TextPacket::class, $listener);
                                            $player->chat($chat);
                                        
                                            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($session, $listener) : void{
                                                try {
                                                    $session->unregisterSpecificPacketListener(TextPacket::class, $listener);
                                                } catch (\Exception $e) {
                                                    $this->plugin->getLogger()->error("Error unregistering TextPacket listener: " . $e->getMessage());
                                                }
                                            }), 1);
                                        } catch (\Exception $e) {
                                            $sender->sendMessage(TextFormat::RED . "Failed to send chat: " . $e->getMessage());
                                            $this->plugin->getLogger()->error("Chat command error for player {$player->getName()}: " . $e->getMessage());
                                        }
                                    }else{
                                        $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " " . $player->getName() . " chat <...chat>");
                                    }
                                    return true;
                                case "form":
                                    if(isset($args[2]) && isset($args[3])){
                                        $_formIdCounter = new ReflectionProperty(Player::class, "formIdCounter");
                                        $form_id = $_formIdCounter->getValue($player) - 1;

                                        $data = null;
                                        if($args[2] === "button"){
                                            $data = json_encode((int) $args[3], JSON_THROW_ON_ERROR);
                                        }elseif($args[2] === "raw"){
                                            try{
                                                $response = json_decode(implode(" ", array_slice($args, 3)), false, 512, JSON_THROW_ON_ERROR);
                                            }catch(JsonException $e){
                                                $player->sendMessage(TextFormat::RED . "Failed to parse JSON: {$e->getMessage()}");
                                                return true;
                                            }
                                            $data = json_encode($response, JSON_THROW_ON_ERROR);
                                        }

                                        if($data !== null){
                                            $this->sendServerPacket($player, ModalFormResponsePacket::response($form_id, $data));
                                            return true;
                                        }
                                    }
                                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " " . $player->getName() . " form button <#>");
                                    $sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " " . $player->getName() . " form raw <responseJson>");
                                    return true;
                                case "interact":
                                    $target_block = $player->getTargetBlock(5);
                                    $item_in_hand = $player->getInventory()->getItemInHand();
                                    if($target_block !== null){
                                        $player->interactBlock($target_block->getPosition(), $player->getHorizontalFacing(), new Vector3(0, 0, 0));
                                        $sender->sendMessage(TextFormat::AQUA . "{$player->getName()} is interacting with {$target_block->getName()} at {$target_block->getPosition()->asVector3()} using {$item_in_hand}.");
                                    }else{
                                        $player->useHeldItem();
                                        $sender->sendMessage(TextFormat::AQUA . "{$player->getName()} is interacting using {$item_in_hand}.");
                                    }
                                    return true;
                            }
                        }else{
                            $sender->sendMessage(TextFormat::RED . $player->getName() . " is NOT a fake player!");
                            return true;
                        }
                    }else{
                        $sender->sendMessage(TextFormat::RED . $args[0] . " is NOT online!");
                        return true;
                    }
                    
                }
                break;
        }

        return $this->sendHelp($sender, $label);
    }

    private function sendHelp(CommandSender $sender, string $label) : bool{
        $sender->sendMessage(TextFormat::BLUE . "Spectron Commands:");
        $sender->sendMessage(TextFormat::AQUA . "/$label menu - Open the Spectron UI");
        $sender->sendMessage(TextFormat::AQUA . "/$label tpall - Teleport all fake players to you");
        $sender->sendMessage(TextFormat::AQUA . "/$label addmessage <message> - Add a kill chat message");
        $sender->sendMessage(TextFormat::AQUA . "/$label removemessage <message> - Remove a kill chat message");
        $sender->sendMessage(TextFormat::AQUA . "/$label togglekillchat - Toggle kill chat");
        $sender->sendMessage(TextFormat::AQUA . "/$label togglepvp - Toggle PvP for fake players");
        $sender->sendMessage(TextFormat::AQUA . "/$label togglerandomwalk - Toggle random walking for fake players");
        $sender->sendMessage(TextFormat::AQUA . "/$label setreach <distance> - Set PvP reach distance (1.0-10.0)");
        $sender->sendMessage(TextFormat::AQUA . "/$label setcooldown <ticks> - Set PvP damage cooldown (10-60)");
        $sender->sendMessage(TextFormat::AQUA . "/$label setdifficulty <easy|normal|hard> - Set PvP difficulty");
        $sender->sendMessage(TextFormat::AQUA . "/$label spawn <name> - Spawn a fake player");
        $sender->sendMessage(TextFormat::AQUA . "/$label remove <name> - Remove a fake player");
        $sender->sendMessage(TextFormat::AQUA . "/$label reset - Reset all fake players");
        $sender->sendMessage(TextFormat::AQUA . "/$label <name> chat <...chat> - Make a fake player chat");
        $sender->sendMessage(TextFormat::AQUA . "/$label <name> form button <#> - Submit a button form response");
        $sender->sendMessage(TextFormat::AQUA . "/$label <name> form raw <responseJson> - Submit a raw form response");
        $sender->sendMessage(TextFormat::AQUA . "/$label <name> interact - Make a fake player interact");
        $sender->sendMessage(TextFormat::AQUA . "/$label info - Show plugin information");
        return true;
    }

    private function openMainMenu(Player $sender) : void{
        $form = new SimpleForm(function(Player $player, ?int $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed main menu");
                return;
            }

            switch($data){
                case 0:
                    $this->executeTpAll($player);
                    break;
                case 1:
                    $this->openSettingsMenu($player);
                    break;
                case 2:
                    $this->openSpawnForm($player);
                    break;
                case 3:
                    $this->openRemoveForm($player);
                    break;
                case 4:
                    $this->openResetConfirmForm($player);
                    break;
                case 5:
                    $this->openChatForm($player);
                    break;
                case 6:
                    $this->openFormSubmitForm($player);
                    break;
                case 7:
                    $this->openInteractForm($player);
                    break;
                default:
                    $player->sendMessage(TextFormat::RED . "Invalid option selected.");
                    $this->plugin->getLogger()->warning("Invalid main menu option: $data");
            }
        });

        $form->setTitle(TextFormat::BLUE . "Spectron Menu");
        $form->setContent(TextFormat::AQUA . "Select an action:");
        $form->addButton("Teleport All", 1, "textures/items/compass");
        $form->addButton("Settings", 1, "textures/items/redstone");
        $form->addButton("Spawn Player", 1, "textures/items/spawn_egg");
        $form->addButton("Remove Player", 1, "textures/items/totem");
        $form->addButton("Reset Players", 1, "textures/items/flint_and_steel");
        $form->addButton("Chat as Player", 1, "textures/items/paper");
        $form->addButton("Submit Form", 1, "textures/items/map_filled");
        $form->addButton("Interact", 1, "textures/items/diamond_sword");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened main menu for {$sender->getName()}");
    }

    private function openSettingsMenu(Player $sender) : void{
        $form = new SimpleForm(function(Player $player, ?int $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed settings menu");
                $this->openMainMenu($player);
                return;
            }

            switch($data){
                case 0:
                    $this->openToggleKillChatForm($player);
                    break;
                case 1:
                    $this->openTogglePvpForm($player);
                    break;
                case 2:
                    $this->openToggleRandomWalkForm($player);
                    break;
                case 3:
                    $this->openAddMessageForm($player);
                    break;
                case 4:
                    $this->openRemoveMessageForm($player);
                    break;
                case 5:
                    $this->openSetReachForm($player);
                    break;
                case 6:
                    $this->openSetCooldownForm($player);
                    break;
                case 7:
                    $this->openSetDifficultyForm($player);
                    break;
                default:
                    $player->sendMessage(TextFormat::RED . "Invalid option selected.");
                    $this->plugin->getLogger()->warning("Invalid settings menu option: $data");
                    $this->openMainMenu($player);
            }
        });

        $settings = $this->plugin->getKillChatSettings();
        $pvp_settings = $this->plugin->getPvpSettings();
        $form->setTitle(TextFormat::BLUE . "Spectron Settings");
        $form->setContent(
            TextFormat::AQUA . "Kill Chat: " . ($settings['enabled'] ? TextFormat::GREEN . "Enabled" : TextFormat::RED . "Disabled") . "\n" .
            TextFormat::AQUA . "PvP: " . ($pvp_settings['enabled'] ? TextFormat::GREEN . "Enabled" : TextFormat::RED . "Disabled") . "\n" .
            TextFormat::AQUA . "Random Walk: " . ($this->plugin->isRandomWalkEnabled() ? TextFormat::GREEN . "Enabled" : TextFormat::RED . "Disabled") . "\n" .
            TextFormat::AQUA . "Messages: " . count($settings['messages']) . "\n" .
            TextFormat::AQUA . "Reach Distance: " . $pvp_settings['reach_distance'] . " blocks\n" .
            TextFormat::AQUA . "Damage Cooldown: " . $pvp_settings['damage_cooldown'] . " ticks\n" .
            TextFormat::AQUA . "Difficulty: " . ucfirst($pvp_settings['difficulty'])
        );
        $form->addButton("Toggle Kill Chat", 1, "textures/items/lever");
        $form->addButton("Toggle PvP", 1, "textures/items/diamond_sword");
        $form->addButton("Toggle Random Walk", 1, "textures/items/feather");
        $form->addButton("Add Kill Chat Message", 1, "textures/items/book_writable");
        $form->addButton("Remove Kill Chat Message", 1, "textures/items/book_normal");
        $form->addButton("Set Reach Distance", 1, "textures/items/stick");
        $form->addButton("Set Damage Cooldown", 1, "textures/items/clock");
        $form->addButton("Set Difficulty", 1, "textures/items/diamond");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened settings menu for {$sender->getName()}");
    }

    private function openToggleKillChatForm(Player $sender) : void{
        $form = new ModalForm(function(Player $player, ?bool $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed toggle kill chat form");
                $this->openSettingsMenu($player);
                return;
            }

            $this->plugin->setKillChatEnabled($data);
            $player->sendMessage(TextFormat::GREEN . "Kill chat " . ($data ? "enabled" : "disabled") . ".");
            $this->openSettingsMenu($player);
        });

        $settings = $this->plugin->getKillChatSettings();
        $form->setTitle(TextFormat::BLUE . "Toggle Kill Chat");
        $form->setContent(TextFormat::AQUA . "Current status: " . ($settings['enabled'] ? TextFormat::GREEN . "Enabled" : TextFormat::RED . "Disabled") . "\n" . TextFormat::AQUA . "Toggle kill chat messages?");
        $form->setButton1(TextFormat::GREEN . "Enable");
        $form->setButton2(TextFormat::RED . "Disable");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened toggle kill chat form for {$sender->getName()}");
    }

    private function openTogglePvpForm(Player $sender) : void{
        $form = new ModalForm(function(Player $player, ?bool $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed toggle PvP form");
                $this->openSettingsMenu($player);
                return;
            }

            $this->plugin->setPvpEnabled($data);
            $player->sendMessage(TextFormat::GREEN . "PvP for fake players " . ($data ? "enabled" : "disabled") . ".");
            $this->openSettingsMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Toggle PvP");
        $form->setContent(TextFormat::AQUA . "Current status: " . ($this->plugin->isPvpEnabled() ? TextFormat::GREEN . "Enabled" : TextFormat::RED . "Disabled") . "\n" . TextFormat::AQUA . "Toggle PvP for fake players?");
        $form->setButton1(TextFormat::GREEN . "Enable");
        $form->setButton2(TextFormat::RED . "Disable");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened toggle PvP form for {$sender->getName()}");
    }

    private function openToggleRandomWalkForm(Player $sender) : void{
        $form = new ModalForm(function(Player $player, ?bool $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed toggle random walk form");
                $this->openSettingsMenu($player);
                return;
            }

            $this->plugin->setRandomWalkEnabled($data);
            $player->sendMessage(TextFormat::GREEN . "Random walk for fake players " . ($data ? "enabled" : "disabled") . ".");
            $this->openSettingsMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Toggle Random Walk");
        $form->setContent(TextFormat::AQUA . "Current status: " . ($this->plugin->isRandomWalkEnabled() ? TextFormat::GREEN . "Enabled" : TextFormat::RED . "Disabled") . "\n" . TextFormat::AQUA . "Toggle random walking for fake players?");
        $form->setButton1(TextFormat::GREEN . "Enable");
        $form->setButton2(TextFormat::RED . "Disable");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened toggle random walk form for {$sender->getName()}");
    }

    private function openAddMessageForm(Player $sender) : void{
        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed add message form");
                $this->openSettingsMenu($player);
                return;
            }

            $message = trim($data[0] ?? "");
            if($message === ""){
                $player->sendMessage(TextFormat::RED . "Message cannot be empty.");
                $this->plugin->getLogger()->warning("Empty message in add message form by {$player->getName()}");
                $this->openSettingsMenu($player);
                return;
            }

            if($this->plugin->addKillChatMessage($message)){
                $player->sendMessage(TextFormat::GREEN . "Added kill chat message: " . $message);
            }else{
                $player->sendMessage(TextFormat::RED . "Failed to add message: Message cannot be empty or limit reached.");
            }
            $this->openSettingsMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Add Kill Chat Message");
        $form->addInput(TextFormat::AQUA . "Message", "Enter the kill chat message", "");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened add message form for {$sender->getName()}");
    }

    private function openRemoveMessageForm(Player $sender) : void{
        $settings = $this->plugin->getKillChatSettings();
        $messages = $settings['messages'];
        if(empty($messages)){
            $sender->sendMessage(TextFormat::RED . "No kill chat messages available to remove.");
            $this->plugin->getLogger()->warning("No kill chat messages for remove message form by {$sender->getName()}");
            $this->openSettingsMenu($sender);
            return;
        }

        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed remove message form");
                $this->openSettingsMenu($player);
                return;
            }

            $settings = $this->plugin->getKillChatSettings();
            $message = $settings['messages'][$data[0]] ?? null;
            if($message === null){
                $player->sendMessage(TextFormat::RED . "Invalid message selected.");
                $this->plugin->getLogger()->warning("Invalid message index in remove message form by {$player->getName()}");
                $this->openSettingsMenu($player);
                return;
            }

            if($this->plugin->removeKillChatMessage($message)){
                $player->sendMessage(TextFormat::GREEN . "Removed kill chat message: " . $message);
            }else{
                $player->sendMessage(TextFormat::RED . "Failed to remove message: Message not found.");
            }
            $this->openSettingsMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Remove Kill Chat Message");
        $form->addDropdown(TextFormat::AQUA . "Message", $messages, 0);
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened remove message form for {$sender->getName()}");
    }

    private function openSetReachForm(Player $sender) : void{
        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed set reach form");
                $this->openSettingsMenu($player);
                return;
            }

            $reach = (float) ($data[0] ?? 0.0);
            if($this->plugin->setPvpReachDistance($reach)){
                $player->sendMessage(TextFormat::GREEN . "Set PvP reach distance to $reach blocks.");
            }else{
                $player->sendMessage(TextFormat::RED . "Failed to set reach: Must be between 1.0 and 10.0 blocks.");
            }
            $this->openSettingsMenu($player);
        });

        $pvp_settings = $this->plugin->getPvpSettings();
        $form->setTitle(TextFormat::BLUE . "Set PvP Reach Distance");
        $form->addInput(TextFormat::AQUA . "Reach Distance (1.0-10.0)", "Enter reach distance in blocks", (string) $pvp_settings['reach_distance']);
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened set reach form for {$sender->getName()}");
    }

    private function openSetCooldownForm(Player $sender) : void{
        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed set cooldown form");
                $this->openSettingsMenu($player);
                return;
            }

            $cooldown = (int) ($data[0] ?? 0);
            if($this->plugin->setPvpDamageCooldown($cooldown)){
                $player->sendMessage(TextFormat::GREEN . "Set PvP damage cooldown to $cooldown ticks.");
            }else{
                $player->sendMessage(TextFormat::RED . "Failed to set cooldown: Must be between 10 and 60 ticks.");
            }
            $this->openSettingsMenu($player);
        });

        $pvp_settings = $this->plugin->getPvpSettings();
        $form->setTitle(TextFormat::BLUE . "Set PvP Damage Cooldown");
        $form->addInput(TextFormat::AQUA . "Damage Cooldown (10-60)", "Enter cooldown in ticks", (string) $pvp_settings['damage_cooldown']);
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened set cooldown form for {$sender->getName()}");
    }

    private function openSetDifficultyForm(Player $sender) : void{
        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed set difficulty form");
                $this->openSettingsMenu($player);
                return;
            }

            $difficulties = ["easy", "normal", "hard"];
            $difficulty = $difficulties[$data[0] ?? 0];
            if($this->plugin->setPvpDifficulty($difficulty)){
                $player->sendMessage(TextFormat::GREEN . "Set PvP difficulty to $difficulty.");
            }else{
                $player->sendMessage(TextFormat::RED . "Failed to set difficulty: Invalid selection.");
            }
            $this->openSettingsMenu($player);
        });

        $pvp_settings = $this->plugin->getPvpSettings();
        $difficulties = ["easy", "normal", "hard"];
        $default_index = array_search($pvp_settings['difficulty'], $difficulties, true) ?: 0;
        $form->setTitle(TextFormat::BLUE . "Set PvP Difficulty");
        $form->addDropdown(TextFormat::AQUA . "Difficulty", $difficulties, $default_index);
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened set difficulty form for {$sender->getName()}");
    }

    private function executeTpAll(Player $sender) : void{
        $pos = $sender->getPosition();
        foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
            if($this->plugin->isspectron($player)){
                $player->teleport($pos);
                $this->plugin->getLogger()->debug("Teleported fake player {$player->getName()} to {$pos->asVector3()} via UI");
            }
        }
        $sender->sendMessage(TextFormat::GREEN . "Teleported all fake players to your position.");
    }

    private function openSpawnForm(Player $sender) : void{
        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed spawn form");
                $this->openMainMenu($player);
                return;
            }

            $name = trim($data[0] ?? "");
            if($name === ""){
                $player->sendMessage(TextFormat::RED . "Player name cannot be empty.");
                $this->plugin->getLogger()->warning("Empty name in spawn form by {$player->getName()}");
                $this->openMainMenu($player);
                return;
            }

            if($this->plugin->spawnPlayer($name, $player->getPosition())){
                $player->sendMessage(TextFormat::GREEN . "Spawned fake player: $name");
            }else{
                $player->sendMessage(TextFormat::RED . "Failed to spawn: Player '$name' already exists.");
            }
            $this->openMainMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Spawn Fake Player");
        $form->addInput(TextFormat::AQUA . "Player Name", "Enter the player name", "");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened spawn form for {$sender->getName()}");
    }

    private function openRemoveForm(Player $sender) : void{
        $online_players = $this->getOnlinespectrons();
        if(empty($online_players)){
            $sender->sendMessage(TextFormat::RED . "No fake players are online.");
            $this->plugin->getLogger()->warning("No online fake players for remove form by {$sender->getName()}");
            $this->openMainMenu($sender);
            return;
        }

        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed remove form");
                $this->openMainMenu($player);
                return;
            }

            $name = $this->getOnlinespectrons()[$data[0]] ?? null;
            if($name === null){
                $player->sendMessage(TextFormat::RED . "Invalid player selected.");
                $this->plugin->getLogger()->warning("Invalid player index in remove form by {$player->getName()}");
                $this->openMainMenu($player);
                return;
            }

            if($this->plugin->removePlayerByName($name)){
                $player->sendMessage(TextFormat::GREEN . "Removed fake player: $name");
            }else{
                $player->sendMessage(TextFormat::RED . "Failed to remove: Fake player '$name' not found.");
            }
            $this->openMainMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Remove Fake Player");
        $form->addDropdown(TextFormat::AQUA . "Player Name", $online_players, 0);
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened remove form for {$sender->getName()}");
    }

    private function openResetConfirmForm(Player $sender) : void{
        $form = new ModalForm(function(Player $player, ?bool $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed reset confirm form");
                $this->openMainMenu($player);
                return;
            }

            if($data){
                $this->plugin->resetPlayers();
                $player->sendMessage(TextFormat::GREEN . "Reset all fake players.");
            }else{
                $player->sendMessage(TextFormat::AQUA . "Reset cancelled.");
            }
            $this->openMainMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Confirm Reset");
        $form->setContent(TextFormat::AQUA . "Are you sure you want to reset all fake players?");
        $form->setButton1(TextFormat::GREEN . "Yes");
        $form->setButton2(TextFormat::RED . "No");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened reset confirm form for {$sender->getName()}");
    }

    private function openChatForm(Player $sender) : void{
        $online_players = $this->getOnlinespectrons();
        if(empty($online_players)){
            $sender->sendMessage(TextFormat::RED . "No fake players are online.");
            $this->plugin->getLogger()->warning("No online fake players for chat form by {$sender->getName()}");
            $this->openMainMenu($sender);
            return;
        }

        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed chat form");
                $this->openMainMenu($player);
                return;
            }

            $name = $this->getOnlinespectrons()[$data[0]] ?? null;
            $message = trim($data[1] ?? "");
            if($name === null || $message === ""){
                $player->sendMessage(TextFormat::RED . "Player and message cannot be empty.");
                $this->plugin->getLogger()->warning("Invalid player or empty message in chat form by {$player->getName()}");
                $this->openMainMenu($player);
                return;
            }

            $target = $this->plugin->getServer()->getPlayerByPrefix($name);
            if($target === null || !$target->isOnline()){
                $player->sendMessage(TextFormat::RED . "$name is NOT online!");
                $this->openMainMenu($player);
                return;
            }
            if(!$this->plugin->isspectron($target)){
                $player->sendMessage(TextFormat::RED . "$name is NOT a fake player!");
                $this->openMainMenu($player);
                return;
            }

            /** @var spectronNetworkSession $session */
            $session = $target->getNetworkSession();
            if(!$session instanceof spectronNetworkSession){
                $player->sendMessage(TextFormat::RED . "Invalid network session for $name.");
                $this->plugin->getLogger()->warning("Invalid network session for $name in chat form by {$player->getName()}");
                $this->openMainMenu($player);
                return;
            }

            try {
                $listener = new ClosurespectronPacketListener(function(ClientboundPacket $packet, NetworkSession $session) use($player) : void{
                    try {
                        if(!$packet instanceof TextPacket){
                            $this->plugin->getLogger()->warning("Received unexpected packet type: " . get_class($packet));
                            return;
                        }
                        if($packet->type !== TextPacket::TYPE_JUKEBOX_POPUP && $packet->type !== TextPacket::TYPE_POPUP && $packet->type !== TextPacket::TYPE_TIP){
                            $player->sendMessage($packet->message);
                        }
                    } catch (\Exception $e) {
                        $this->plugin->getLogger()->error("Error processing TextPacket: " . $e->getMessage());
                    }
                });
                $session->registerSpecificPacketListener(TextPacket::class, $listener);
                $target->chat($message);
                $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($session, $listener) : void{
                    try {
                        $session->unregisterSpecificPacketListener(TextPacket::class, $listener);
                    } catch (\Exception $e) {
                        $this->plugin->getLogger()->error("Error unregistering TextPacket listener: " . $e->getMessage());
                    }
                }), 1);
                $player->sendMessage(TextFormat::GREEN . "Sent chat as $name: $message");
            } catch (\Exception $e) {
                $player->sendMessage(TextFormat::RED . "Failed to send chat: " . $e->getMessage());
                $this->plugin->getLogger()->error("Chat form error for player $name: " . $e->getMessage());
            }
            $this->openMainMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Chat as Fake Player");
        $form->addDropdown(TextFormat::AQUA . "Player Name", $online_players, 0);
        $form->addInput(TextFormat::AQUA . "Message", "Enter the chat message", "");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened chat form for {$sender->getName()}");
    }

    private function openFormSubmitForm(Player $sender) : void{
        $online_players = $this->getOnlinespectrons();
        if(empty($online_players)){
            $sender->sendMessage(TextFormat::RED . "No fake players are online.");
            $this->plugin->getLogger()->warning("No online fake players for form submit form by {$sender->getName()}");
            $this->openMainMenu($sender);
            return;
        }

        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed form submit form");
                $this->openMainMenu($player);
                return;
            }

            $name = $this->getOnlinespectrons()[$data[0]] ?? null;
            $type = ["button", "raw"][$data[1] ?? 0];
            $response = trim($data[2] ?? "");
            if($name === null || $response === ""){
                $player->sendMessage(TextFormat::RED . "Player and response cannot be empty.");
                $this->plugin->getLogger()->warning("Invalid player or empty response in form submit form by {$player->getName()}");
                $this->openMainMenu($player);
                return;
            }

            $target = $this->plugin->getServer()->getPlayerByPrefix($name);
            if($target === null || !$target->isOnline()){
                $player->sendMessage(TextFormat::RED . "$name is NOT online!");
                $this->openMainMenu($player);
                return;
            }
            if(!$this->plugin->isspectron($target)){
                $player->sendMessage(TextFormat::RED . "$name is NOT a fake player!");
                $this->openMainMenu($player);
                return;
            }

            $_formIdCounter = new ReflectionProperty(Player::class, "formIdCounter");
            $form_id = $_formIdCounter->getValue($target) - 1;
            $data = null;
            if($type === "button"){
                $data = json_encode((int) $response, JSON_THROW_ON_ERROR);
            }elseif($type === "raw"){
                try{
                    $decoded = json_decode($response, false, 512, JSON_THROW_ON_ERROR);
                    $data = json_encode($decoded, JSON_THROW_ON_ERROR);
                }catch(JsonException $e){
                    $player->sendMessage(TextFormat::RED . "Failed to parse JSON: {$e->getMessage()}");
                    $this->plugin->getLogger()->warning("JSON parse error in form submit form by {$player->getName()}: {$e->getMessage()}");
                    $this->openMainMenu($player);
                    return;
                }
            }

            try {
                $this->sendServerPacket($target, ModalFormResponsePacket::response($form_id, $data));
                $player->sendMessage(TextFormat::GREEN . "Submitted form response for $name.");
            } catch (\Exception $e) {
                $player->sendMessage(TextFormat::RED . "Failed to submit form: " . $e->getMessage());
                $this->plugin->getLogger()->error("Form submit error for player $name: " . $e->getMessage());
            }
            $this->openMainMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Submit Form Response");
        $form->addDropdown(TextFormat::AQUA . "Player Name", $online_players, 0);
        $form->addDropdown(TextFormat::AQUA . "Response Type", ["Button", "Raw"], 0);
        $form->addInput(TextFormat::AQUA . "Response", "Enter button number or JSON", "");
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened form submit form for {$sender->getName()}");
    }

    private function openInteractForm(Player $sender) : void{
        $online_players = $this->getOnlinespectrons();
        if(empty($online_players)){
            $sender->sendMessage(TextFormat::RED . "No fake players are online.");
            $this->plugin->getLogger()->warning("No online fake players for interact form by {$sender->getName()}");
            $this->openMainMenu($sender);
            return;
        }

        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->plugin->getLogger()->debug("Player {$player->getName()} closed interact form");
                $this->openMainMenu($player);
                return;
            }

            $name = $this->getOnlinespectrons()[$data[0]] ?? null;
            if($name === null){
                $player->sendMessage(TextFormat::RED . "Invalid player selected.");
                $this->plugin->getLogger()->warning("Invalid player index in interact form by {$player->getName()}");
                $this->openMainMenu($player);
                return;
            }

            $target = $this->plugin->getServer()->getPlayerByPrefix($name);
            if($target === null || !$target->isOnline()){
                $player->sendMessage(TextFormat::RED . "$name is NOT online!");
                $this->openMainMenu($player);
                return;
            }
            if(!$this->plugin->isspectron($target)){
                $player->sendMessage(TextFormat::RED . "$name is NOT a fake player!");
                $this->openMainMenu($player);
                return;
            }

            $target_block = $target->getTargetBlock(5);
            $item_in_hand = $target->getInventory()->getItemInHand();
            if($target_block !== null){
                $target->interactBlock($target_block->getPosition(), $target->getHorizontalFacing(), new Vector3(0, 0, 0));
                $player->sendMessage(TextFormat::AQUA . "$name is interacting with {$target_block->getName()} at {$target_block->getPosition()->asVector3()} using {$item_in_hand}.");
            }else{
                $target->useHeldItem();
                $player->sendMessage(TextFormat::AQUA . "$name is interacting using {$item_in_hand}.");
            }
            $this->openMainMenu($player);
        });

        $form->setTitle(TextFormat::BLUE . "Interact as Fake Player");
        $form->addDropdown(TextFormat::AQUA . "Player Name", $online_players, 0);
        $sender->sendForm($form);
        $this->plugin->getLogger()->debug("Opened interact form for {$sender->getName()}");
    }
}