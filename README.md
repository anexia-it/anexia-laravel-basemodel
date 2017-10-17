# Anexia BaseModel

A Laravel package used to provide extended basic functionality (filtering, sorting, pagination) to eloquent models.

## 1. Installation and configuration

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


## 2. Usage

### 2.1. Models
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
```
```
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

### 2.2. Controllers
Use the BaseModelController to allow 'bulk actions' (create, update, delete) over multiple layers of object relations.
It comes with the method 'editObjectContents' which expects a BaseModel (implementing the BaseModelInterface and using
the BaseModelTrait) and the new properties (as array, as provided by a POST/PUT/PATCH request).

It uses the nested transactions* provided by the SubTransactionServiceProvider, which allows better controll over the
data on multiple related models at once.


* The nested transactions are currently only available for PostgreSQL connections
(via Anexia\BaseModel\Database\PostgresConnection). To support other databases, new Connection classes must be provided,
that extend the Anexia\BaseModel\Database\Connection to handle mutliple open transactions.


## 3. Model Configuration Methods
The BaseModelInterface demands several internal configurations for each model, most of them can be empty by default (as
many in the BaseModelTrait). But if need be, specific alterations of those configuration methods can make any model very
self-sufficient in regards of validation, change behaviour and other aspects. 

### 3.1. getUnmodifieable
expected result: array of unmodifieable properties

All properties returned by this function will be excluded from the BaseModelController's 'editObjectContents' method.
Thus, those properties will not be automatically/bulk edited and must be set/changed explicitly.

### 3.2. getDefaults
expected result: array of the properties with their default values

If properties get values assigned within this method, the BaseModel constructor will automatically fill them on model
instantiation (regardless of whether they are defined as $guarded or $fillable). They do not have to be set explicitly.

### 3.3. getDefaultSearch
expected result: array of properties that shall be searched by default whenever allExtended is called with 'search'
parameters. 

Those parameters can either be handed on method calling ($preSetSearches and $preSetOrSearches) or via a
HTTP request (fetched via request()->all() and handled as $searches and $orSearches in the allExtended method).
All properties returned by the getDefaultSearch function will be searched with the given sub string ('WHERE x LIKE "y"'
SQL condition), the searches will be OR connected.

See section [Searching](#4.5.3.-searching) for more details on the search behaviour.

### 3.4. getDefaultSorting
expected result: all properties plus the wanted direction that shall be sorted by default whenever allExtended is
called.

See section [Sorting](#4.5.1.-sorting) for more details on the sorting behavior.

### 3.5. getRelationships
expected result: all properties that are associated with a related model class.
possible input: boolean $list parameter

If $list is true, this method should return a simple array of all relation-properties plus their related class.

**Example**
```
// from model class app/Post.php
    
    $fillable = ['name', 'type', 'author_id'];
    // $guarded / etc.
    
    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    
    /**
     * @param boolean|false $list
     * @return array
     */
    public static function getRelationships($list = false)
    {
        if ($list) {
            return [
                'author' => User::class
            ];
        }
        
        // return something else for $list = false
    }
}
```

If $list is false however, this method should return a more complex representation of the model's relations. It should
then return a multiarry of 'one' and 'many' relations, according to the relation's nature. Furthermore should each
relation property not only contain the related classes name, but also how the corresponding property on the related
model is called (inverse side) and whether or not the relation is editable and nullable from the current model's side.

**Example**
```
// from model class app/Post.php
    
    $fillable = ['name', 'type', 'author_id'];
    // $guarded / etc.
    
    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    
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
            // return list
        }

        return [
            'one' => [
                'author' => [
                    'model' => User::class,
                    'inverse' => 'posts',
                    'editable' => false, // true by default
                    'nullable' => false // true by default
                ]
            ],
            'many' => [
                'comments' => [
                    'model' => Comment::class,
                    'inverse' => 'post'
                ]
            ]
        ];
    }
}
```

Depending on the configuration of the model's relationships, the BaseModelController's 'editObjectContent' method will
iterate through the related objects (and their relations, and their relations, etc.) and make changes (creation or
update) on them.

### 3.6. getValidationRules
expected result: all laravel validation rules (see https://laravel.com/docs/5.3/validation for details on the supported
rules) that are associated with a related model class.
possible input: boolean $checkCompletion parameter

If $checkCompletion is true, this method should return all properties with all their necessary validation rules.

If $checkCompletion is false however, the returned rules should support only partial presence of the editable
properties, to support PATCH requests.

**Example**
```
// from model class app/Post.php
    
    $fillable = ['name', 'type', 'author_id'];
    
    /**
     * @param bool|true $checkCompletion
     * @return array
     */
    public static function getValidationRules($checkCompletion = true)
    {
        if ($checkCompletion) {
            return [
                'name' => 'required|string|min:1|max:255',
                'type' => 'required|string|nullable',
                'author_id' => 'required|integer|nullable'
            ];
        }

        return [
            'name' => 'string|min:1|max:255',
            'type' => 'string|nullable',
            'author_id' => 'integer|nullable'
        ];
    }
}
```

In the example above the 'required' rules do not get returned on $checkCompletion = false, since it is possible, that
only the name of an existing post might be updated. If the 'required' rules applied, a request like

```
PATCH /posts/1

{
    "id": 1,
    "name": "a new post name!"
}

``` 

would result in an error with the information, that the fields 'type' and 'author_id' are missing, even if they simply
should not get changed.

### 3.7. validateAttributeLogic
expected results: void or exceptions (if any part of the custom validation fails)

For more complex/logical checks the laravel HTTP parameter validation might not be sufficient, e.g. when a property
depends highly on other objects' relations. Thus the BaseModelController's 'editObjectContents' method calls the models'
'validateAttributeLogic' method, which serves as a hook AFTER the object properties get updated, but BEFORE the changes
get stored into the database.

All custom checks and validations regarding a model's properties (and related objects' requirements) can be placed in
the 'validateAttributeLogic' and appropriate exceptions can be thrown on validation failures. All possibly thrown 
exceptions will be caught within the 'editObjectContents' method and be transferred into a BulkValidationException.

See section [Exceptions](#4.4.-exceptions) for more details on the package's exception handling.


## 4. Available Features
The BaseModelTrait provides several handy methods to support your models and controller actions. Once the models are
configured correctly, many basic behaviours will happen automatically or with minimum coding effort.

### 4.1. Model Default values
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

### 4.2. Relationship configurations (Bulk Actions)
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

### 4.3. Extended all and find methods
The BaseModelTrait implements the two improved model methods 'allExtended' and 'findExtended'. They are extended
versions of eloquent models' 'all' and 'find' methods and behave as following:

#### 4.3.1. Static Method allExtended
This method adds BaseModel features to the basic 'all' method of each eloquent model:
```
* filter
* sorting
* pagination
* inclusion of related objects
```

While all of those features can be configured via GET request parameters - see section 
[HTTP List request options and parameters](#4.5.-http-list-request-options-and-parameters) for further detail on the
possible parameter configurations during a request - some of them can also be prefilled when calling the 'allExtended'
method in a class (e.g. a REST controller).
The method definition from the BaseModelInterface looks like this:
```
public static function allExtended($columns = ['*'], $preSetFilters = [], $preSetOrFilters = [], $preSetIncludes = [],
                                   $preSetSearches = [], $preSetOrSearches = []);
```

The 'columns' and 'includes' arrays will affect the model's attributes and relations that will be returned. They do not
affect the SQL query itself, but will influence the representation of the resultSet (received via 
Illuminate\Pagination\LengthAwarePaginator).

The 'filters' and 'searches' arrays will directly affect the SQL query itself and will be accumulated like this:
SELECT ... WHERE (
    (
        $preSetFilters 
        AND (GET-filters from request)
    ) 
    OR $preSetOrFilters
) 
AND (
    $preSetSearches
    OR $preSetOrSearches
)

The following section will describe how the 'allExtended' method's parameters can be used directly on method call,
regardless of possible HTTP request parameters.

##### 4.3.1.1. Parameters
So as the 'add' method once can specify the following configuration when fetching a bunch of objects:

###### 4.3.1.1.1 $columns (first parameter)
Plain array that defines the columns (= model's properties), that are to be returned for all found objects. The object
ids will always be returned, regradless of the settings of the 'columns' variable.
**Example**
```
Post::allExtended(['name']);
```
will only return the names (and ids) of all found post entries:
```
{
	"data": [
		{
			"id": 1,
			"name": "Name Post 1"
		},
		{
			"id": 2,
			"name": "Name Post 2"
		}, ...
	],
	// pagination information fields ...
}
```

###### 4.3.1.1.2. $preSetFilters (second parameter)
Multiarray of 'WHERE x = y' filtering conditions that get nested like this:
```
    $preSetFilters = [
        // AND connected conditions go here (single condition or array)
    
        [
            // OR connected conditions go here (single conditions or array)
    
            [
            
                // AND connected conditions go here (single condition or array)
            ], ...
        ], ...
    ];
```
**Example**
```
$preSetFilters = [
    [
        [
            'author_id' => null,
            'catgory_id' => null,
        ],
        'author_id' => $curUser->id,
        'category.genre.supervisor_id' => $curUser->id
    ],
    'author_id' => 2
];

Post::allExtended(null, $preSetFilters);
```
will result in the following SQL query:
```
select * from posts where (
    (
        (
            (
                ((author_id is null and catgory_id is null)) 
                or author_id = $curUser->id 
                or exists (
                    select * from categories where posts.catgory_id = categories.id 
                    and exists (
                        select * from genres where categories.genre_id = genres.id 
                        and supervisor_id = $curUser->id
                    )
                )
            )
        ) 
        and author_id = 2
    )
)
```

###### 4.3.1.1.3. $preSetOrFilters (third parameter)
Multiarray of 'WHERE ... OR x = y' filtering conditions that get nested like this:
```
    $preSetFilters = [
        // OR connected conditions go here (single condition or array)
    
        [
            // AND connected conditions go here (single condition or array)
    
            [
            
                // OR connected conditions go here (single condition or array)
            ], ...
        ], ...
    ];
```
**Example**
```
$preSetOrFilters = [
   [
       [
           'author_id' => null,
           'catgory_id' => null,
       ],
       'author_id' => $curUser->id,
       'category.genre.supervisor_id' => $curUser->id
   ],
   'author_id' => 2,
];
Post::allExtended(
    ['*'],
    [],
    $preSetOrFilters
);
```
will result in the following SQL query:
```
select * from posts where (
    (
        (
            (
                ((author_id is null or catgory_id is null)) 
                and author_id = $curUser->id 
                and exists (
                    select * from categories where posts.category_id = categories.id 
                    and exists (
                        select * from genres where categories.genre_id = genres.id
                        and supervisor_id = $curUser->id
                    )
                )
            )
        ) 
        or author_id = 2
    )
)
```

###### 4.3.1.1.4. $preSetIncludes (fourth parameter)
Plain array of all model relations (their method's names) that are to be included into the resulting list.
**Example**
```
$preSetIncludes = ['comments', 'author'];
Post::allExtended(
    ['name'],
    [],
    [],
    $preSetIncludes
);
```
will return the wanted relations' properties along with the found post entries:
```
{
	"data": [
		{
			"id": 1,
			"name": "Name Post 1",
			"comments": [
			    {
			        "id": 1,
			        "post_id": 1,
			        "text": "A comment text"
			    },
			    {
                    "id": 2,
                    "post_id": 1,
                    "text": "Another comment text"
                } 
			],
			"author": {
			    "id": 1,
			    "name": "A User"
			}
		},
		{
			"id": 2,
			"name": "Name Post 2",
            "comments": [
                {
                    "id": 3,
                    "post_id": 2,
                    "text": "Some comment text"
                }
            ],
            "author": {
                "id": 1,
                "name": "A User"
            }
		}, ...
	],
	// pagination information fields ...
}
```

###### 4.3.1.1.5. $preSetSearches (fifth parameter)
Multiarray of 'WHERE X LIKE "y"' filtering conditions that get nested like this:
```
    $preSetSearches = [
        // AND connected conditions go here (single condition or array)
    
        [
            // OR connected conditions go here (single conditions or array)
        ], ...
    ];
```
**Example**
```
$preSetSearches = [
    'name' => ['%post%', 'blog%', '%entry'],
    'author_id' => '%1%'
];

Post::allExtended(null, [], [], [], $preSetSearches);
```
will result in the following SQL query:
```
select * from posts where (((name LIKE '%post%' or name LIKE 'blog%' or name LIKE '%entry') and author_id LIKE '%1%'))
```

Respectively, since PostgreSQL only supports LIKE and ILIKE (case insensitive LIKE) queries for character/text fields,
for PostgreSQL connections the query will be:
```
select * from posts where (((name::TEXT ILIKE '%post%' or name::TEXT ILIKE 'blog%' or name::TEXT ILIKE '%entry')
    and author_id::TEXT ILIKE '%1%'))
```

###### 4.3.1.1.6. $preSetOrSearches (sixth parameter)
Multiarray of 'WHERE ... OR X LIKE "y"' filtering conditions that get nested like this:
```
    $preSetOrSearches = [
        // OR connected conditions go here (single condition or array)
    
        [
            // AND connected conditions go here (single conditions or array)
        ], ...
    ];
```
**Example**
```
$preSetOrSearches = [
    'name' => ['%post%', 'blog%', '%entry'],
    'author_id' => '%1%'
];

Post::allExtended(null, [], [], [], [], $preSetOrSearches);
```
will result in the following SQL query:
```
select * from posts where (((name LIKE '%post%' and name LIKE 'blog%' and name LIKE '%entry') or author_id LIKE '%1%'))
```

Respectively, since PostgreSQL only supports LIKE and ILIKE (case insensitive LIKE) queries for character/text fields,
for PostgreSQL connections the query will be:
```
select * from posts where (((name::TEXT ILIKE '%post%' and name::TEXT ILIKE 'blog%' and name::TEXT ILIKE '%entry')
    or author_idvLIKE '%1%'))
```

#### 4.3.2. Static method findExtended
This method adds BaseModel features to the basic 'find' method of each eloquent model:
```
* filter
* inclusion of related objects
```

While all of those features can be configured via GET request parameters - see section 
[HTTP List request options and parameters](#4.5.-http-list-request-options-and-parameters) for further detail on the
possible parameter configurations during a request - some of them can also be prefilled when calling the 'findExtended'
method in a class (e.g. a REST controller).
The method definition from the BaseModelInterface looks like this:
```
public static function findExtended($id, $columns = ['*'], $preSetFilters = [], $preSetIncludes = []);
```

The 'columns' and 'includes' arrays will affect the model's attributes and relations that will be returned. They do
not affect the SQL query itself, but will influence the representation of the resulting object.

The 'filters' array will directly affect the SQL query itself and will be accumulated like this:
SELECT ... WHERE $preSetFilters

The following section will describe how the 'findExtended' method's parameters can be used directly on method call,
regardless of possible HTTP request parameters.

##### 4.3.2.1. Parameters
So as the 'add' method once can specify the following configuration when fetching a bunch of objects:

###### 4.3.2.1.1. $columns (second parameter)
Plain array that defines the columns (= model's properties), that are to be returned for the found object. The object
id will always be returned, regradless of the settings of the 'columns' variable.
**Example**
```
Post::findExtended(1, ['name']);
```
will only return the name (and id) of the found post entry:
```
{
	"data": {
        "id": 1,
        "name": "Name Post 1"
    }
}
```

###### 4.3.2.1.2. $preSetFilters (third parameter)
Multiarray of 'WHERE x = y' filtering conditions that get nested like this:
```
    $preSetFilters = [
        // AND connected conditions go here (single condition or array)
    
        [
            // OR connected conditions go here (single conditions or array)
    
            [
            
                // AND connected conditions go here (single condition or array)
            ], ...
        ], ...
    ];
```
**Example**
```
$preSetFilters = [
    [
        [
            'author_id' => null,
            'catgory_id' => null,
        ],
        'author_id' => $curUser->id,
        'category.genre.supervisor_id' => $curUser->id
    ],
    'author_id' => 2
];

Post::findExtended(1, null, $preSetFilters);
```
will result in the following SQL query:
```
select * from posts where id = 1 and 
```

### 4.4. Exceptions
The BaseModel package comes with two built-in exception classes:

#### 4.4.1. SqlException
When the BaseModelController's 'editObjectContents' method catches a standard QueryException (Illuminate\Database), it
looks for certain PostgreSQL error codes and translates them into SqlExceptions with default status code 400 and the
corresponding info text as 'message'. 

#### 4.4.2. BulkValidationException 
When the BaseModelController's 'editObjectContents' method catches any exception's whatsoever, it puts their message
(or messages, if multiple exceptions get thrown in the course of the edition of multiple related objects) into a
BulkValidationException's 'messages' field. The BulkValidationException defaults to a status code 400 and has both a
'message' field (which by default says 'Error in bulk validation') and a 'messages' field (accessable during the
'getMessages' method) that contains the collected exception's messages with further details to the actual occurring
errors.

### 4.5. HTTP List request options and parameters

#### 4.5.1. Sorting
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

##### 4.5.1.1. Custom sorting
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

#### 4.5.2. Filtering
Each endpoint request that provides lists of multiple entities can be filtered to return only those results that show
the required values for the given attributes.

**Example**

`GET /posts?author_id=1` will only return the posts of the author with id 1

##### 4.5.2.1. AND Filtering (multiple filters)
Filters usually get added via AND constraint, so multiple filters can be combined in one request.

**Example**
 
`GET /posts?author.name=Someone&type=SomeType` will only return the posts that have the type = 'SomeType' AND belong to
the author with name = 'Someone' 

##### 4.5.2.2. OR Filtering (multiple valid values for the same filter)
To allow several possible values for one filter, the OR constraint can be used by making the filter an array.

**Example**
 
`GET /posts?name[]=test post&name[]=Another post` will only return the posts that have the name = 'test post' or name =
'Another post'.

#### 4.5.3. Searching
To trigger a case insensitive 'LIKE' sql search (case insensitive LIKE), the 'search' GET parameter can be used.
By default the model properties defined in the 'getDefaultSearch' method will be searched if no explicit property name
is given with the search parameter.

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

`GET /posts?search=test` will return all posts with name LIKE '%test%' OR type LIKE '%test%'.

`GET /posts?search[type]=test` will return all posts with type LIKE '%test%'.

##### 4.5.3.1. Search at field start or end
To look for a substring at the beginning of a field, the 'search_start' GET parameter can be used. It can also be used
on the default search properties or on specific properties:

**Example**

`GET /posts?search_start=test` will return all posts with name LIKE 'test%' OR type LIKE 'test%'.

`GET /posts?search_start[type]=test` will return all posts with type LIKE 'test%'.

The same applies for the 'search_end' GET parameter that can be used to find substrings at the beginning of a field:

**Example**

`GET /posts?search_end=test` will return all posts with name LIKE '%test' OR type LIKE '%test'.

`GET /posts?search_end[type]=test` will return all posts with type LIKE '%test'.

##### 4.5.3.2. AND Searching (multiple search conditions)
Whenever multiple 'search', 'search_start', 'seartch_end' parameters are given in the GET request, they are AND
connected in the resulting query.

**Example**

`GET /posts?search=test&search_end[type]=foo` will return all posts with ((name LIKE '%test%' OR type LIKE '%test%')
AND type LIKE '%foo').

##### 4.5.3.3. OR Searching (multiple valid values for the same search condition)
To define multiple possible values for the search on the same fields, the values can be arranged as arrays:

**Example**

`GET /posts?search[]=test&search[]=foo` will return all posts with name LIKE '%test%' OR type LIKE '%test%'
OR name LIKE '%foo%' OR type LIKE '%foo%'.

Multiple AND and OR combinations of search filters can be applied in one GET request to create complex searches.

**Example**

`GET /posts?search[]=test&search_end[type]=foo&search[]=foo&search_start[name]=bar` will return all posts with ((name 
LIKE '%test%' OR type LIKE '%test%' OR name LIKE '%foo%' OR type LIKE '%foo%') AND type LIKE '%foo' AND name LIKE
'bar%').

#### 4.5.4. Pagination
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

To change the default pagination within the application, the BaseModel's 'setPagination' method can be used.

To change the number of items shown per page directly on request, the GET parameter 'pagination' can be used.
To change the currently shown page, the GEt parameter 'page' can be used, but careful: if the given page exceeds the
number of available pages for the current list (is greater than 'last_page'), there will not be an error response, but
a valid response with an empty collection of items ('data').

**Example**

`GET /posts?pagination=100&page=2` will return all posts with ((name 
LIKE '%test%' OR type LIKE '%test%' OR name LIKE '%foo%' OR type LIKE '%foo%') AND type LIKE '%foo' AND name LIKE
'bar%').


## 5. Testing
The package comes with a basic test class for BaseModels and a more general DbTestCase that uses DatabaseTransactions
to keep changes to the test db temporary (changes are undone after each test method). 

### 5.1. Model Tests (Unit Tests)
The BaseModelTestCase includes tests for all models' defined default values for properties/attributes and a check
whether their relation definitions are complete (corresponding definition in related models). 

To use the two provided tests for a BaseModel, the BaseModelTestCase class can be extended (abstract methods need to be
implemented). Afterwards the two tests 'testInverseRelationsForBulkActions' and 'testDefaultValues' will be available
for the newly created test class:

```
<?php

namespace Tests\Unit\Models;

use Anexia\BaseModel\Tests\Unit\Models\BaseModelTestCase;
use App\User;

class PostTest extends BaseModelTestCase
{
    /**
     * @param int $userId
     */
    protected function mockLogin($userId = 1)
    {
        // mock the user of request()->user()
        $this->be($this->getUser($userId));
        $this->call('GET', 'login');
    }

    /**
     * @param int $id
     * @return User|null
     */
    public function getUser($id = 1)
    {
        return User::find($id);
    }
}
```
By running the phpunit tests, the tests from BaseModelTestCase will be executed for the Post model.
```
phpunit [--filter PostTest]
```

### 5.2. Controller Tests (Feature Tests)
The RestControllerTestCase provides a check method for the pagination as described in section
[Pagination](#4.5.4.-pagination). This check makes sure, all pagination related fields are set in the list response.

To use this method the RestControllerTestCase can be extended (abstract methods need to be implemented) and after a
(mocked) GET list request, the pagination check can be included:

```
<?php

namespace Tests\Feature\Controllers;

use Anexia\BaseModel\Tests\Feature\Controllers\RestControllerTestCase
use App\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

class PostControllerTest extends RestControllerTestCase
{
    /** string */
    const API_ROUTE = '/api/v1';

    /**
     * Test read list
     *
     * @return void
     */
    public function testReadPostList()
    {
        $response = $this->get(self::API_ROUTE . '/posts');

        $response->assertStatus(200);
        $body = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('data', $body);
        $data = $body['data'];
        $this->assertInternalType('array', $data);
        
        $includes = [
            // OR connected conditions

            [
                // AND connected conditions

                'therapist_id' => null,
                'unit_id' => null
            ],
            'therapist_id' => $this->currentUser->id,
            'unit.therapy.therapist_id' => $this->currentUser->id
        ];

        $posts = Post::allExtended(
            ['*'],
            [],
            $includes
        );

        // add pagination checks
        $this->runPaginationTests($body, $posts->count());
    }
}
```

### 5.3. DbTestCase
Both, BaseModelTestCase and RestControllerTestCase use the DbTestCase, which allows DatabaseTransactions. By default,
all tests use the database connection defined as 'pgsql_testing'. At the first test in each run the database gets
created from scratch (using the commands 'php artisan migrate' and 'php artisan db:seed').


## 6. List of developers

* Alexandra Bruckner <ABruckner@anexia-it.com>, Lead developer


## 7. Project related external resources

* [Laravel 5 documentation](https://laravel.com/docs/5.4/installation)
