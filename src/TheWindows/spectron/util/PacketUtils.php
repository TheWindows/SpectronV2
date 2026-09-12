<?php

declare(strict_types=1);

namespace TheWindows\spectron\util;

use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

final class PacketUtils{

    public static function encodePacket(Packet $packet, int $protocolId = ProtocolInfo::CURRENT_PROTOCOL) : string{
        if(class_exists(\pmmp\encoding\ByteBufferWriter::class)){
            $writer = new \pmmp\encoding\ByteBufferWriter();
            $packet->encode($writer, $protocolId);
            return $writer->getData();
        }

        /** @phpstan-ignore-next-line */
        $serializer = \pocketmine\network\mcpe\protocol\serializer\PacketSerializer::encoder($protocolId);
        $packet->encode($serializer);
        return $serializer->getBuffer();
    }

    public static function decodePacket(Packet $packet, string $buffer, int $protocolId = ProtocolInfo::CURRENT_PROTOCOL) : void{
        if($packet instanceof \pocketmine\network\mcpe\protocol\StartGamePacket && class_exists(\pocketmine\network\mcpe\protocol\types\ServerTelemetryData::class)){
            $packet->serverTelemetryData = new \pocketmine\network\mcpe\protocol\types\ServerTelemetryData("", "", "", "");
        }

        if(class_exists(\pmmp\encoding\ByteBufferReader::class)){
            $reader = new \pmmp\encoding\ByteBufferReader($buffer);
            $packet->decode($reader, $protocolId);
            return;
        }

        /** @phpstan-ignore-next-line */
        $serializer = \pocketmine\network\mcpe\protocol\serializer\PacketSerializer::decoder($protocolId, $buffer, 0);
        $packet->decode($serializer);
    }
}
