# Anexia BaseModel

A Laravel package used to provide extended basic functionality (filtering, sorting, pagination) to eloquent models.

## Installation and configuration

Install the module via composer, therefore adapt the ``require`` part of your ``composer.json``:
```
"require": {
    "anexia/laravel-basemodel": "1.0.0"
}
```

Now run
```
composer update [-o]
```
to add the packages source code to your ``/vendor`` directory and update the autoloading.

## Usage

Use The ExtendedModel trait in all models that are supposed to support the extended base functionality.
```
// actual model class app/Post.php

<?php

namespace App;

use Anexia\BaseModel\Traits\ExtendedModel;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use ExtendedModel;
    
    ...
}

----------------------------------
// auth model class app/User.php

<?php

namespace App;

use Anexia\BaseModel\Traits\ExtendedModel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;

class User extends Model implements
    AuthenticatableContract,
    AuthorizableContract,
    CanResetPasswordContract
{
    use Authenticatable, Authorizable, CanResetPassword, ExtendedModel;
    
    ...
}
```

## List of developers

* Alexandra Bruckner <ABruckner@anexia-it.com>, Lead developer

## Project related external resources

* [Laravel 5 documentation](https://laravel.com/docs/5.4/installation)
