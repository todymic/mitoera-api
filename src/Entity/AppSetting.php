<?php

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_settings')]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 100)]
    private string $key;

    #[ORM\Column(type: 'string', length: 500)]
    private string $value;

    public function __construct(string $key, string $value)
    {
        $this->key   = $key;
        $this->value = $value;
    }

    public function getKey(): string   { return $this->key; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $value): self { $this->value = $value; return $this; }
}
