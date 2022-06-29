# Web Crawler Demo 

> This is the source code for the web crawler demp - a PHP application

This app runs with Laravel version 9.18.0 (PHP v8.1.6)

## Getting started

Assuming you've already installed on your machine: PHP (>= 8.1), [Laravel](https://laravel.com) and [Composer](https://getcomposer.org) 
``` bash
# install dependencies
composer install

# create .env file and generate the application key
cp .env.example .env
php artisan key:generate

# create a database, save database name & credentials to .env file  and then run
php artisan migrate

```
Then launch the server:

``` bash
php artisan serve
```

At this point the app is now up and running! Access it at http://localhost:8000.

## Licence

This software is licensed under the Apache 2 license, quoted below.

Copyright 2018 Prismic.io (https://prismic.io).

Licensed under the Apache License, Version 2.0 (the "License"); you may not use this project except in compliance with the License. You may obtain a copy of the License at http://www.apache.org/licenses/LICENSE-2.0.

Unless required by applicable law or agreed to in writing, software distributed under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the License for the specific language governing permissions and limitations under the License.