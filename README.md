Example:

```php
<?php

use Emericanec\UniqueEntity; 

/**
 * @UniqueEntity(
 *     entityClass="App\Entity\User",
 *     fields={"email"}
 * )
 */
class RegistrationForm
{
    protected string $email;

    public function getEmail(): string
    {
        return $this->email;
    }
    
    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

}
```