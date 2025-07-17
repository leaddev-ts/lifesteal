<?php

namespace Lead\Lifesteal;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use onebone\economyapi\EconomyAPI;

class Lifesteal extends PluginBase implements Listener {

    private Config $data;
    private Config $config;

    public function onEnable(): void {
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        $this->data = new Config($this->getDataFolder() . "data.yml", Config::YAML);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $map = $this->getServer()->getCommandMap();
        $map->register("lifesteal", new class($this) extends Command {
            public function __construct(private Lifesteal $plugin) {
                parent::__construct("hearts", "Lihat jumlah heart kamu", "/hearts");
                $this->setPermission("lifesteal.use");
            }
            public function execute(CommandSender $s, string $label, array $args): void {
                $this->plugin->onCommand($s, $this, $label, $args);
            }
        });
        $map->register("lifesteal", new class($this) extends Command {
            public function __construct(private Lifesteal $plugin) {
                parent::__construct("revive", "Revive player", "/revive <player>");
                $this->setPermission("lifesteal.revive");
            }
            public function execute(CommandSender $s, string $label, array $args): void {
                $this->plugin->onCommand($s, $this, $label, $args);
            }
        });
    }

    public function onJoin(PlayerJoinEvent $e): void {
        $p = $e->getPlayer();
        $n = strtolower($p->getName());
        if (!$this->data->exists($n)) {
            $this->data->set($n, 20);
            $this->data->save();
        }
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => $this->setMaxHealth($p, $this->data->get($n))), 20);
    }

    public function onQuit(PlayerQuitEvent $e): void {
        $p = $e->getPlayer();
        $this->data->set(strtolower($p->getName()), $p->getMaxHealth());
        $this->data->save();
    }

    public function onDeath(PlayerDeathEvent $e): void {
        $victim = $e->getPlayer();
        $vName = strtolower($victim->getName());
        $current = $this->data->get($vName, 20);
        $new = max((int)$this->config->get("min_health", 1), $current - 2);

        if ($new <= (int)$this->config->get("min_health", 1) && $this->config->get("ban_on_zero", true)) {
            $until = new \DateTime("+" . ((int)$this->config->get("ban_duration", 3600)) . " seconds");
            Server::getInstance()->getNameBans()->addBan($victim->getName(), "You ran out of hearts!", $until);
            $victim->kick("§cKamu dibanned 1 jam karena tidak punya heart lagi.", false);
        }

        $this->data->set($vName, $new);
        $this->data->save();
        $this->setMaxHealth($victim, $new);

        $cause = $victim->getLastDamageCause();
        if ($cause instanceof EntityDamageByEntityEvent && ($killer = $cause->getDamager()) instanceof Player) {
            $kName = strtolower($killer->getName());
            $currK = $this->data->get($kName, 20);
            $max = (int)$this->config->get("max_health", 40);
            $this->data->set($kName, min($max, $currK + 1));
            $this->data->save();
            $this->setMaxHealth($killer, $this->data->get($kName));
        }
    }

    private function setMaxHealth(Player $p, int $amount): void {
        $amount = max(1, min($amount, 2048));
        $p->setMaxHealth($amount);
        if ($p->getHealth() > $amount) {
            $p->setHealth($amount);
        }
    }

    public function onCommand(CommandSender $s, Command $cmd, string $label, array $args): bool {
        $name = strtolower($s->getName());

        switch (strtolower($cmd->getName())) {
            case "hearts":
                if (!$s instanceof Player) {
                    $s->sendMessage("§cCommand hanya untuk player.");
                    return true;
                }
                $hearts = $this->data->get($name, 20);
                $s->sendMessage("§a❤️ Heart kamu: §l{$hearts}§r§a HP");
                return true;

            case "revive":
                if (!$s instanceof Player) {
                    $s->sendMessage("§cCommand ini hanya bisa digunakan oleh player.");
                    return true;
                }

                if (!$s->hasPermission("lifesteal.revive")) {
                    $s->sendMessage("§cKamu tidak punya izin.");
                    return true;
                }

                if (count($args) < 1) {
                    $s->sendMessage("§cGunakan: /revive <player>");
                    return true;
                }

                $targetName = strtolower($args[0]);
                $price = (int)$this->config->get("revive_price", 10000000);

                $eco = EconomyAPI::getInstance();
                if ($eco->myMoney($s) < $price) {
                    $s->sendMessage("§cUang kamu tidak cukup untuk revive! Butuh §e" . number_format($price));
                    return true;
                }

                $eco->reduceMoney($s, $price);
                $this->data->set($targetName, 20);
                $this->data->save();
                Server::getInstance()->getNameBans()->remove($targetName);
                $s->sendMessage("§aBerhasil me-revive §e$args[0]§a dengan biaya §e" . number_format($price));

                $target = $this->getServer()->getPlayerExact($args[0]);
                if ($target instanceof Player) {
                    $this->setMaxHealth($target, 20);
                    $target->sendMessage("§aKamu telah di-revive oleh §e" . $s->getName() . "§a.");
                }
                return true;
        }

        return false;
    }
}
