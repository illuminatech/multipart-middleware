<p align="center">
    <a href="https://github.com/illuminatech" target="_blank">
        <img src="https://avatars1.githubusercontent.com/u/47185924" height="100px">
    </a>
    <h1 align="center">Multipart request parser middleware for Laravel</h1>
    <br>
</p>

This extension provides ability to parse 'multipart/form-data' HTTP requests for any request method, including 'PUT', 'PATCH' and so on.

For license information check the [LICENSE](LICENSE.md)-file.

[![Latest Stable Version](https://img.shields.io/packagist/v/illuminatech/multipart-middleware.svg)](https://packagist.org/packages/illuminatech/multipart-middleware)
[![Total Downloads](https://img.shields.io/packagist/dt/illuminatech/multipart-middleware.svg)](https://packagist.org/packages/illuminatech/multipart-middleware)
[![Build Status](https://travis-ci.org/illuminatech/multipart-middleware.svg?branch=master)](https://travis-ci.org/illuminatech/multipart-middleware)


Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist illuminatech/multipart-middleware
```

or add

```json
"illuminatech/multipart-middleware": "*"
```

to the require section of your composer.json.


Usage
-----

This extension provides ability to parse 'multipart/form-data' HTTP requests for any request method, including 'PUT',
'PATCH' and so on without necessity to spoof it using '_method' parameter.

This allows REST client, interacting with your application, to use modern strict flow involving file uploading.

It is provided via `\Illuminatech\MultipartMiddleware\MultipartFormDataParser` middleware.
This middleware should be applied to your HTTP kernel prior to any other middleware, which operates input data.
For example:

```php
<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        \App\Http\Middleware\CheckForMaintenanceMode::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \Illuminatech\MultipartMiddleware\MultipartFormDataParser::class, // parse multipart request, before operating input
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        // ...
    ];
    // ...
}
```

`\Illuminatech\MultipartMiddleware\MultipartFormDataParser` will automatically parse any HTTP request with 'multipart/form-data'
content type, skipping only requests performed with 'POST' method as they are already parsed by PHP automatically.


## Enforcing parsing <span id="enforcing-parsing"></span>

By default `\Illuminatech\MultipartMiddleware\MultipartFormDataParser` middleware skips requests performed via 'POST' method
and the ones already containing uploaded files. This is done for performance reason, since PHP parses 'multipart/form-data'
for POST requests automatically. However, it is not always a desirable behavior. You may need to enforce parsing of the
POST requests in case you creating request instances manually in non standard way. For example, in case you are building
ReactPHP application. This can be achieved using `force` middleware parameter. For example:

```php
<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        // ..
        \Illuminatech\MultipartMiddleware\MultipartFormDataParser::class.':true', // enforce multipart request parsing
        // ...
    ];
    // ...
}
```


## Restrictions and drawbacks <span id="restrictions-and-drawbacks"></span>

* Although parser populates temporary file name for the uploaded file instance, such temporary file will
  not be recognized by PHP as uploaded one. Thus functions like `is_uploaded_file()` and `move_uploaded_file()` will
  fail on it. Thus all created uploaded file instances are marked as test ones.
  
* All created temporary files will be automatically deleted, once middleware instance is destroyed.
  Thus any attempt to read the parsed uploaded file outside request handler scope will fail.

* This extension may cause PHP memory overflow error in case of processing large files and large request body.
  Make sure to restrict maximum request body size to avoid such problem.
