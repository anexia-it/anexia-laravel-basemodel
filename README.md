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

### Models
Use the BaseModelInterface in combination with the BaseModelTrait in all models that are supposed to support the
base functionality (filtering, sorting, pagination, ...).
```
// model class app/Post.php

<?php

namespace App;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Traits\BaseModelTrait;
use Illuminate\Database\Eloquent\Model;

class Post extends Model implements BaseModelInterface
{
    use BaseModelTrait;
    
    // additional model functionality can be added
}

----------------------------------
// auth model class app/User.php

<?php

namespace App;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Traits\BaseModelTrait;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;

class User extends Model implements BaseModelInterface,
    AuthenticatableContract,
    AuthorizableContract,
    CanResetPasswordContract
{
    use Authenticatable, Authorizable, CanResetPassword, BaseModelTrait;
    
    ...
}
```

#### Default values
The BaseModelTrait comes with the possibility to prefill default values on an object's instantiation. The 'getDefaults'
method can be configured to return an array of all default values for a model's properties, e.g.:

```
// model class app/Post.php

<?php

namespace App;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Traits\BaseModelTrait;
use Illuminate\Database\Eloquent\Model;

class Post extends Model implements BaseModelInterface
{
    use BaseModelTrait;
    
    $fillable = ['name', 'type'];
    // $guarded / etc.
    
    /**
     * @param Model|null $currentUser
     * @return array
     */
    public static function getDefaults(Model $currentUser = null)
    {
        return [
            'type' => 'blog'
        ];
    }
}
```

#### Relationship configurations (Bulk Actions)
A model can use its relations for 'bulk actions'. These bulk actions allow multiple related models to be managed in a
single request instead of calling one request per each model action. 

If the classes Post and Comment have a One-To-Many relationship (one Post can have many Comments), and the 'comments'
relation within Post is configured as editable, an Update/Create on the Post endpoint will also update/create the given
Comments.

The following scenario describes this use case in detail:

1) Post model defines its relation to the Comment as editable (a change request to Post can 'go downwards' and trigger
a change in one or many of its Comments):
```
// model class app/Post.php

<?php

namespace App;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Traits\BaseModelTrait;
use Illuminate\Database\Eloquent\Model;

class Post extends Model implements BaseModelInterface
{
    use BaseModelTrait;
    
    $fillable = ['name'];
    // $guarded / etc.
    
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
    
    /**
     * @param boolean|false $list
     * @return array
     */
    public static function getRelationships($list = false)
    {
        if ($list) {
            return [
                'comments' => Comment::class
            ];
        }

        return [
            'many' => [
                'comments' => [
                    'model' => Comment::class, // related model's class
                    'inverse' => 'post', // name of the relation within the related model
                    'editable' => true, // true by default
                    'nullable' => true // true by default
                ]
            ]
        ];
    }
}
```

2) Comment model defines its relation to the Post model to be ineditable (a change request on a Comment can not
'go upwards' and trigger a change in its Post). 
```
// model class app/Comment.php

<?php

namespace App;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Traits\BaseModelTrait;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model implements BaseModelInterface
{
    use BaseModelTrait;
    
    $fillable = ['text];
    // $guarded / etc.
    
    public function post()
    {
        return $this->belongsTo(Post::class);
    }
    
    /**
     * @param boolean|false $list
     * @return array
     */
    public static function getRelationships($list = false)
    {
        if ($list) {
            return [
                'post' => Post::class
            ];
        }

        return [
            'one' => [
                'post' => [
                    'model' => Post::class,
                    'inverse' => 'comments',
                    'editable' => false,
                    'nullable' => false
                ]
            ]
        ];
    }
}
```

3) If now a POST request for a new Post occurs that contains the defined relation field 'comments', all contents of 
'comments' will be stored as Comment objects, e.g.:

```
POST /posts

{
    "name":"Post 1"
    "comments":[
        {
           "text":"A comment from a user" 
        },
        {
           "text":"Another comment from a user" 
        }
    ]
}
```

This request will create a new Post with name 'Post 1' AND two new Comments with the texts: 'A comment from a user' and 
'Another comment from a user' without the necessity to call two additional POST requests for the two comments.

### Controllers
User the BaseModelController to allow 'bulk actions' (create, update, delete) over multiple layers of object relations.
It uses the nested transactions* provided by the SubTransactionServiceProvider, which allows better controll over the
data on multiple related models at once.



* The nested transactions are currently only available for PostgreSQL connections
(via Anexia\BaseModel\Database\PostgresConnection). To support other databases, new Connection classes must be provided,
that extend the Anexia\BaseModel\Database\Connection to handle mutliple open transactions.

## List request options and parameters

### Sorting
Each endpoint request that provides lists of multiple entities can be sorted according to the related entities'
properties.

The BaseModel comes with a default sorting for list requests (GET with no specific id, e.g.: GET /posts). With the
getDefaultSorting method in a BaseModel the fields and direction to sort by can be defined.

```
// model class app/Post.php

<?php

namespace App;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Traits\BaseModelTrait;
use Illuminate\Database\Eloquent\Model;

class Post extends Model implements BaseModelInterface
{
    use BaseModelTrait;
    
    $fillable = ['name', 'type'];
    ...
    
    /**
     * @return array
     */
    public static function getDefaultSorting()
    {
        return [
            'name' => 'ASC'
        ];
    }
}
```
The above example will always return post lists sorted by their names in ascending order. If multiple fields are defined
for the default sorting they will be processed from top to bottom (top first, bottom last).

**Example**
The following definition
```
    /**
     * @return array
     */
    public static function getDefaultSorting()
    {
        return [
            'name' => 'ASC',
            'type' => 'DESC'
        ];
    }
```
results in the post lists to be sorted by names in ascending order and types in descending order.

#### Custom sorting
The default sort_field and sort_direction can be modified via GET parameters:
 * sort_field - alters 'orderBy' parameter of internal SQL query
 * sort_direction - alters 'orderBy DESC|ASC' parameter of internal SQL query
 * default_sorting - boolean to switch default sorting for each endpoint on/off (it's on by default)
 
The 'sort_field' parameter can be any property that comes with the result of the requested entity.
The 'sort_direction' for each 'sort_field' is 'ASC' by default. To change it, the parameter must always include the correlating property as key:

```
GET /posts?sort_direction[name]=desc&default_sorting=0    // will work

GET /posts?sort_direction=desc           // will NOT work
GET /posts?sort_direction[]=desc         // will NOT work
```

Multiple 'sort_field' and 'sort_direction' parameters can be combined to create a multiply sorted result. The given
order of the 'sort_field' parameters will define the sorting order within the internal SQL query, e.g.:

```
GET /posts?sort_field[]=title&sort_field[]=first_name&sort_direction[title]=desc&default_sorting=0
```

will result in internal SQL query

```
select * from `posts` order by `title` desc, `first_name` asc
```

If 'sort_direction' parameters are given without corresponding 'sort_field' parameter, they will be added (in the given
order) after the 'sort_field' parameter conditions, e.g.:

```
GET /posts?sort_direction[name]=desc&sort_field[]=type&sort_field[]=name&sort_direction[type]=desc&default_sorting=0
```

will result in internal SQL query

```
select * from `posts` order by `type` desc, `name` asc
```

Even though the 'sort_direction[name]' parameter was before sort_field[]=type it will be added afterwards,
so as conclusion the order of the sorting-results is:
* all 'sort_field' parameters (order as in URI) with their according 'sort_direction' parameters
* all remaining (with no corresponding 'sort_field' parameter) 'sort_direction' parameters (order as in URI) 

### Filtering
Each endpoint request that provides lists of multiple entities can be filtered to return only those results that show
the required values for the given attributes.

**Example**

`GET /posts?author_id=1` will only return the posts of the author with id 1

#### AND Filtering (multiple filters)
Filters usually get added via AND constraint, so multiple filters can be combined in one request.

**Example**
 
`GET /posts?author.name=Someone&type=SomeType` will only return the posts that have the type = 'SomeType' AND belong to
the author with name = 'Someone' 

#### OR Filtering (multiple valid values for the same filter)
To allow several possible values for one filter, the OR constraint can be used by making the filter an array.

**Example**
 
`GET /posts?name[]=test post&name[]=Another post` will only return the posts that have the name = 'test post' or name =
'Another post'.

### Searching
To trigger an 'ILIKE' sql search (case insensitive LIKE), the 'search' GET parameter can be used. By default the model
properties defined in the 'getDefaultSearch' method will be searched if no explicit property name is given with the
search parameter.

```
// model class app/Post.php

<?php

namespace App;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Traits\BaseModelTrait;
use Illuminate\Database\Eloquent\Model;

class Post extends Model implements BaseModelInterface
{
    use BaseModelTrait;
    
    $fillable = ['name', 'type'];
    ...
    
    /**
     * @return array
     */
    public static function getDefaultSearch()
    {
        return [
            'name',
            'type'
        ];
    }
}
```

**Example**

`GET /posts?search=test` will return all posts with name ILIKE '%test%' OR type ILIKE '%test%'.

`GET /posts?search[type]=test` will return all posts with type ILIKE '%test%'.

#### Search at field start or end
To look for a substring at the beginning of a field, the 'search_start' GET parameter can be used. It can also be used
on the default search properties or on specific properties:

**Example**

`GET /posts?search_start=test` will return all posts with name ILIKE 'test%' OR type ILIKE 'test%'.

`GET /posts?search_start[type]=test` will return all posts with type ILIKE 'test%'.

The same applies for the 'search_end' GET parameter that can be used to find substrings at the beginning of a field:

**Example**

`GET /posts?search_end=test` will return all posts with name ILIKE '%test' OR type ILIKE '%test'.

`GET /posts?search_end[type]=test` will return all posts with type ILIKE '%test'.

#### AND Searching (multiple search conditions)
Whenever multiple 'search', 'search_start', 'seartch_end' parameters are given in the GET request, they are AND
connected in the resulting query.

**Example**

`GET /posts?search=test&search_end[type]=foo` will return all posts with ((name ILIKE '%test%' OR type ILIKE '%test%')
AND type ILIKE '%foo').

#### OR Searching (multiple valid values for the same search condition)
To define multiple possible values for the search on the same fields, the values can be arranged as arrays:

**Example**

`GET /posts?search[]=test&search[]=foo` will return all posts with name ILIKE '%test%' OR type ILIKE '%test%'
OR name ILIKE '%foo%' OR type ILIKE '%foo%'.

Multiple AND and OR combinations of search filters can be applied in one GET request to create complex searches.

**Example**

`GET /posts?search[]=test&search_end[type]=foo&search[]=foo&search_start[name]=bar` will return all posts with ((name 
ILIKE '%test%' OR type ILIKE '%test%' OR name ILIKE '%foo%' OR type ILIKE '%foo%') AND type ILIKE '%foo' AND name ILIKE
'bar%').

### Pagination
For GET requests on lists of models (e.g. GET /posts), the default pagination is always 10 items per page, starting with
page 1 (items 0 - 10).

The paginated response always show the following structure:
```
* current_page; currently shown page; default = 1
* data; array of all items found for the current page
* from; index of the first item on the current page
* last_page; last available page for the current list
* next_page_url; null or the http url to the next page (with GET param 'page=<nextpage>')
* path; current page's url
* prev_page_url; null or the http url to the previous page (with GET param 'page=<previouspage>')
* to; index of the last item on the current page
* total; sum of all items for the current list (over all pages)
```

To change the number of items shown per page, the GET parameter 'pagination' can be used.
To change the currently shown page, the GEt parameter 'page' can be used, but careful: if the given page exceeds the
number of available pages for the current list (is greater than 'last_page'), there will not be an error response, but
a valid response with an empty collection of items ('data').

**Example**

`GET /posts?pagination=100&page=2` will return all posts with ((name 
ILIKE '%test%' OR type ILIKE '%test%' OR name ILIKE '%foo%' OR type ILIKE '%foo%') AND type ILIKE '%foo' AND name ILIKE
'bar%').


## List of developers

* Alexandra Bruckner <ABruckner@anexia-it.com>, Lead developer

## Project related external resources

* [Laravel 5 documentation](https://laravel.com/docs/5.4/installation)
