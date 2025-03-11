<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'string', length: 180, unique: true, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'string', length: 180)]
    private ?string $name = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string', length: 255, unique: true, nullable: true)]
    private ?string $twitterId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $twitterAccessToken = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $twitterAccessTokenSecret = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function getTwitterId(): ?string
    {
        return $this->twitterId;
    }

    public function setTwitterId(string $twitterId): self
    {
        $this->twitterId = $twitterId;
        return $this;
    }

    public function getTwitterAccessToken(): ?string
    {
        return $this->twitterAccessToken;
    }

    public function setTwitterAccessToken(?string $twitterAccessToken): self
    {
        $this->twitterAccessToken = $twitterAccessToken;
        return $this;
    }

    public function getTwitterAccessTokenSecret(): ?string
    {
        return $this->twitterAccessTokenSecret;
    }

    public function setTwitterAccessTokenSecret(?string $twitterAccessTokenSecret): self
    {
        $this->twitterAccessTokenSecret = $twitterAccessTokenSecret;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }
}
